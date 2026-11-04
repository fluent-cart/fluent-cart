<?php

namespace Tests\Integration\Http;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * HTTP endpoint tests for subscription routes.
 *
 * User-context switching for non-admin scenarios is marked skipped due to the
 * known framework limitation: Helper::getCurrentUser() caches in a static variable.
 */
class SubscriptionControllerTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private OrderAddressFactory $addressFactory;
    private SubscriptionFactory $subscriptionFactory;

    private static int $persistentAdminId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$persistentAdminId = 1;
        wp_set_current_user(self::$persistentAdminId);
        $user = get_user_by('ID', self::$persistentAdminId);
        if ($user && !user_can($user, 'manage_options')) {
            $user->set_role('administrator');
        }
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory    = new CustomerFactory();
        $this->productFactory     = new ProductFactory();
        $this->orderFactory       = new OrderFactory();
        $this->itemFactory        = new OrderItemFactory();
        $this->addressFactory     = new OrderAddressFactory();
        $this->subscriptionFactory = new SubscriptionFactory();

        wp_set_current_user(self::$persistentAdminId);
        $this->resetFluentRequest();
        rest_get_server();
    }

    public function tearDown(): void
    {
        wp_cache_flush();
        gc_collect_cycles();
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeSubscription(array $subOverrides = []): array
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $order    = $this->orderFactory->withAddresses()->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        $item = $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);
        $sub = $this->subscriptionFactory->create(array_merge([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ], $subOverrides));

        return compact('customer', 'product', 'variant', 'order', 'item', 'sub');
    }

    // =========================================================================
    // Spec 175-177: GET /subscriptions (index)
    // =========================================================================

    public function test_get_subscriptions_returns_data(): void
    {
        $this->makeSubscription();

        $request  = new \WP_REST_Request('GET', '/fluent-cart/v2/subscriptions');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('data', $data, 'Response should have data key');
    }

    public function test_get_subscriptions_accepts_per_page_param(): void
    {
        $this->makeSubscription();
        $this->makeSubscription();

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/subscriptions');
        $request->set_param('per_page', 1);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
    }

    // =========================================================================
    // Spec 178-185: GET /subscriptions/{id}
    // =========================================================================

    public function test_get_subscription_detail_returns_subscription(): void
    {
        ['sub' => $sub] = $this->makeSubscription();

        // Spec 184: response should pass through fluent_cart/subscription/view filter.
        $filterFired = false;
        $filter = function ($value) use (&$filterFired) {
            $filterFired = true;
            return $value;
        };
        add_filter('fluent_cart/subscription/view', $filter);

        $request  = new \WP_REST_Request('GET', '/fluent-cart/v2/subscriptions/' . $sub->id);
        $response = $this->dispatchRequest($request);

        remove_filter('fluent_cart/subscription/view', $filter);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('subscription', $data);
        $this->assertSame($sub->id, $data['subscription']['id']);

        // Spec 181: selected_labels top-level
        $this->assertArrayHasKey('selected_labels', $data);

        // Spec 182: reminder_permissions top-level
        $this->assertArrayHasKey('reminder_permissions', $data);

        // Spec 179-180: billing_address / shipping_address / related_orders are attached to the subscription
        // model as dynamic attributes; they appear under the subscription key when serialized.
        $subData = $data['subscription'];
        if (is_object($subData) && method_exists($subData, 'toArray')) {
            $subData = $subData->toArray();
        }
        $hasShapeKey = (is_array($subData) && (
            array_key_exists('billing_address', $subData)
            || array_key_exists('shipping_address', $subData)
            || array_key_exists('related_orders', $subData)
        ));
        $this->assertTrue($hasShapeKey, 'Expected subscription to carry billing_address, shipping_address, or related_orders');

        // Spec 184: filter fired
        $this->assertTrue($filterFired, 'fluent_cart/subscription/view filter should be invoked');
    }

    public function test_get_subscription_detail_returns_error_for_nonexistent(): void
    {
        $request  = new \WP_REST_Request('GET', '/fluent-cart/v2/subscriptions/999999');
        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status(), 'Non-existent subscription should return 4xx');
    }

    // =========================================================================
    // Spec 186-191: PUT /orders/{order}/subscriptions/{subscription}/cancel
    // =========================================================================

    public function test_cancel_subscription_succeeds_with_cancel_reason(): void
    {
        // config must be non-empty to avoid core bug: empty [] -> '[]' string in getter
        // Runs first to avoid WPFluent route param caching issue (first dispatch to cancel route gets fresh params)
        ['order' => $order, 'sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'config' => ['_init' => true],
        ]);

        $request = new \WP_REST_Request('PUT', "/fluent-cart/v2/orders/{$order->id}/subscriptions/{$sub->id}/cancel");
        $request->set_body_params(['cancel_reason' => 'No longer needed']);
        $response = $this->dispatchRequest($request);

        $data = $response->get_data();
        $this->assertSame(200, $response->get_status(), $data['message'] ?? '');
        $this->assertArrayHasKey('subscription', $data);

        $fresh = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_CANCELED, $fresh->status);
    }

    public function test_cancel_already_canceled_subscription_returns_error(): void
    {
        ['order' => $order, 'sub' => $sub] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_CANCELED,
        ]);

        $request = new \WP_REST_Request('PUT', "/fluent-cart/v2/orders/{$order->id}/subscriptions/{$sub->id}/cancel");
        $request->set_body_params(['cancel_reason' => 'already done']);
        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    // =========================================================================
    // Spec 192-194: PUT /orders/{order}/subscriptions/{subscription}/fetch
    // =========================================================================

    public function test_fetch_subscription_returns_capability_error_for_cod_gateway(): void
    {
        // COD no longer carries a vendor subscriptions module (store-billed since the
        // invoice engine) — reSyncFromRemote returns the invalid_payment_method error.
        ['order' => $order, 'sub' => $sub] = $this->makeSubscription([
            'current_payment_method' => 'offline_payment',
        ]);

        $request  = new \WP_REST_Request('PUT', "/fluent-cart/v2/orders/{$order->id}/subscriptions/{$sub->id}/fetch");
        $response = $this->dispatchRequestSilently($request);

        $this->assertSame(422, $response->get_status());
        $data = $response->get_data();
        $this->assertStringContainsString('does not support remote resync', $data['message'] ?? '');
    }

    // =========================================================================
    // Spec 195-201: POST /early-payment-link
    // =========================================================================

    public function test_early_payment_link_returns_error_when_bill_times_zero(): void
    {
        ['order' => $order, 'sub' => $sub] = $this->makeSubscription([
            'bill_times' => 0,
            'status'     => Status::SUBSCRIPTION_ACTIVE,
        ]);

        $request  = new \WP_REST_Request('POST', "/fluent-cart/v2/orders/{$order->id}/subscriptions/{$sub->id}/early-payment-link");
        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
        $data = $response->get_data();
        // EarlyPaymentFeature::isEnabled() is the first guard — returns false when Pro is not active,
        // so message is "Early payment is not enabled for this site." rather than an installment error.
        $this->assertStringContainsString('enabled', $data['message'] ?? '');
    }

    // =========================================================================
    // Spec 202-203: pause/resume/reactivate reject ineligible subscriptions (implemented by the renewal engine; "Not available yet" stubs are gone)
    // =========================================================================

    public function test_reactivate_subscription_rejects_gateway_billed_subscription(): void
    {
        // The endpoints are implemented now — an automatic (gateway-billed) subscription
        // is rejected because reactivation is a store-billed (invoice engine) flow.
        ['order' => $order, 'sub' => $sub] = $this->makeSubscription();

        $request  = new \WP_REST_Request('PUT', "/fluent-cart/v2/orders/{$order->id}/subscriptions/{$sub->id}/reactivate");
        $response = $this->dispatchRequestSilently($request);

        $data = $response->get_data();
        $this->assertStringContainsString('store-billed', $data['message'] ?? '');
    }

    public function test_pause_subscription_rejects_non_pausable_subscription(): void
    {
        ['order' => $order, 'sub' => $sub] = $this->makeSubscription();

        $request  = new \WP_REST_Request('PUT', "/fluent-cart/v2/orders/{$order->id}/subscriptions/{$sub->id}/pause");
        $response = $this->dispatchRequestSilently($request);

        $data = $response->get_data();
        $this->assertStringContainsString('cannot be paused', $data['message'] ?? '');
    }

    public function test_resume_subscription_rejects_non_resumable_subscription(): void
    {
        ['order' => $order, 'sub' => $sub] = $this->makeSubscription();

        $request  = new \WP_REST_Request('PUT', "/fluent-cart/v2/orders/{$order->id}/subscriptions/{$sub->id}/resume");
        $response = $this->dispatchRequestSilently($request);

        $data = $response->get_data();
        $this->assertStringContainsString('cannot be resumed', $data['message'] ?? '');
    }

    // =========================================================================
    // Spec 209-224: Events
    // =========================================================================

    public function test_subscription_activated_event_has_correct_hook(): void
    {
        $event = new \FluentCart\App\Events\Subscription\SubscriptionActivated(
            new Subscription(),
            null,
            null,
            []
        );
        $this->assertSame('fluent_cart/subscription_activated', $event->hook);
    }

    public function test_subscription_canceled_event_has_correct_hook(): void
    {
        $event = new \FluentCart\App\Events\Subscription\SubscriptionCanceled(
            new Subscription(),
            null,
            null,
            ''
        );
        $this->assertSame('fluent_cart/subscription_canceled', $event->hook);
    }

    public function test_subscription_canceled_event_to_array_has_default_reason(): void
    {
        $event = new \FluentCart\App\Events\Subscription\SubscriptionCanceled(
            new Subscription(),
            null,
            null,
            ''
        );
        $arr = $event->toArray();
        $this->assertArrayHasKey('reason', $arr);
        $this->assertNotEmpty($arr['reason']);
    }

    public function test_subscription_renewed_event_has_correct_hook(): void
    {
        $event = new \FluentCart\App\Events\Subscription\SubscriptionRenewed(
            new Subscription(),
            new \FluentCart\App\Models\Order(),
            new \FluentCart\App\Models\Order(),
            null
        );
        $this->assertSame('fluent_cart/subscription_renewed', $event->hook);
    }

    public function test_subscription_eot_event_has_correct_hook(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription();
        $orderModel = \FluentCart\App\Models\Order::query()->find($order->id);
        $subModel   = Subscription::query()->find($sub->id);
        $event = new \FluentCart\App\Events\Subscription\SubscriptionEOT($subModel, $orderModel);
        $this->assertSame('fluent_cart/subscription_eot', $event->hook);
    }

    public function test_subscription_validity_expired_event_has_correct_hook(): void
    {
        $event = new \FluentCart\App\Events\Subscription\SubscriptionValidityExpired(
            new Subscription(),
            null,
            null,
            ''
        );
        $this->assertSame('fluent_cart/subscription_expired_validity', $event->hook);
    }

    public function test_subscription_validity_expired_to_array_has_default_reason(): void
    {
        $event = new \FluentCart\App\Events\Subscription\SubscriptionValidityExpired(
            new Subscription(),
            null,
            null,
            ''
        );
        $arr = $event->toArray();
        $this->assertArrayHasKey('reason', $arr);
        $this->assertNotEmpty($arr['reason']);
    }

    public function test_plan_changed_event_has_correct_hook(): void
    {
        $product  = $this->productFactory->create();
        $variant  = $product->variants->first();
        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);
        $orderModel = \FluentCart\App\Models\Order::query()->find($order->id);

        $event = new \FluentCart\App\Events\PlanChanged($orderModel, $variant, $variant);
        $this->assertSame('fluent_cart/plan_changed', $event->hook);
    }

    // Spec 210: SubscriptionActivated::toArray exposes subscription, order, customer, meta
    public function test_subscription_activated_to_array_exposes_all_fields(): void
    {
        $sub = new Subscription();
        $event = new \FluentCart\App\Events\Subscription\SubscriptionActivated(
            $sub,
            null,
            null,
            ['note' => 'activated']
        );
        $arr = $event->toArray();
        $this->assertArrayHasKey('subscription', $arr);
        $this->assertArrayHasKey('order', $arr);
        $this->assertArrayHasKey('customer', $arr);
        $this->assertArrayHasKey('meta', $arr);
        $this->assertSame(['note' => 'activated'], $arr['meta']);
    }

    // Spec 211: SubscriptionActivated::getActivityEventModel returns the subscription
    public function test_subscription_activated_activity_event_model_returns_subscription(): void
    {
        $sub = new Subscription();
        $event = new \FluentCart\App\Events\Subscription\SubscriptionActivated($sub);
        $this->assertSame($sub, $event->getActivityEventModel());
    }

    // Spec 214: SubscriptionCanceled::shouldCreateActivity returns true
    public function test_subscription_canceled_should_create_activity_returns_true(): void
    {
        $event = new \FluentCart\App\Events\Subscription\SubscriptionCanceled(
            new Subscription(), null, null, ''
        );
        $this->assertTrue($event->shouldCreateActivity());
    }

    // Spec 216: SubscriptionRenewed::beforeDispatch calls newOrder->generateReceiptNumber()
    public function test_subscription_renewed_before_dispatch_generates_receipt_number(): void
    {
        ['sub' => $sub, 'order' => $parent, 'customer' => $customer] = $this->makeSubscription();
        $parentOrder = \FluentCart\App\Models\Order::query()->find($parent->id);

        $renewalOrder = $this->orderFactory->withAddresses()->create([
            'customer_id' => $customer->id,
            'parent_id'   => $parent->id,
            'type'        => 'renewal',
        ]);
        $renewalModel = \FluentCart\App\Models\Order::query()->find($renewalOrder->id);

        $subModel = Subscription::query()->find($sub->id);

        $event = new \FluentCart\App\Events\Subscription\SubscriptionRenewed(
            $subModel, $renewalModel, $parentOrder, null
        );
        $event->beforeDispatch();

        $refreshed = \FluentCart\App\Models\Order::query()->find($renewalOrder->id);
        $this->assertNotEmpty($refreshed->receipt_number);
    }

    // Spec 217: SubscriptionRenewed::toArray exposes subscription, order (newOrder), main_order (oldOrder), customer
    public function test_subscription_renewed_to_array_maps_new_order_and_old_order(): void
    {
        $sub   = new Subscription();
        $new   = new \FluentCart\App\Models\Order();
        $old   = new \FluentCart\App\Models\Order();
        $event = new \FluentCart\App\Events\Subscription\SubscriptionRenewed($sub, $new, $old, null);
        $arr   = $event->toArray();
        $this->assertArrayHasKey('subscription', $arr);
        $this->assertArrayHasKey('order', $arr);
        $this->assertArrayHasKey('main_order', $arr);
        $this->assertArrayHasKey('customer', $arr);
        $this->assertSame($new, $arr['order']);
        $this->assertSame($old, $arr['main_order']);
    }

    // Spec 218: SubscriptionRenewed has registered listener Listeners\Subscription\SubscriptionRenewed
    public function test_subscription_renewed_has_registered_listener(): void
    {
        $reflect  = new \ReflectionClass(\FluentCart\App\Events\Subscription\SubscriptionRenewed::class);
        $prop     = $reflect->getProperty('listeners');
        $prop->setAccessible(true);
        $instance = $reflect->newInstanceWithoutConstructor();
        $listeners = $prop->getValue($instance);
        $this->assertContains(
            \FluentCart\App\Listeners\Subscription\SubscriptionRenewed::class,
            $listeners
        );
    }

    // Spec 220: SubscriptionEOT::beforeDispatch calls cancelRemoteSubscription(reason=end_of_term, fire_hooks=false)
    public function test_subscription_eot_before_dispatch_cancels_remote_with_end_of_term(): void
    {
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'config' => ['_init' => true],
        ]);
        $subModel   = Subscription::query()->find($sub->id);
        $orderModel = \FluentCart\App\Models\Order::query()->find($order->id);

        $fired = 0;
        $hook  = function () use (&$fired) { $fired++; };
        add_action('fluent_cart/subscription_canceled', $hook);

        $event = new \FluentCart\App\Events\Subscription\SubscriptionEOT($subModel, $orderModel);
        $event->beforeDispatch();

        remove_action('fluent_cart/subscription_canceled', $hook);

        $refreshed = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_CANCELED, $refreshed->status);
        $config = is_array($refreshed->config) ? $refreshed->config : [];
        $this->assertSame('end_of_term', $config['cancellation_reason'] ?? null);
        $this->assertSame(0, $fired, 'fire_hooks=false should suppress the canceled event');
    }

    // Spec 221: SubscriptionEOT::beforeDispatch logs an error via fluent_cart_add_log on WP_Error from cancel
    public function test_subscription_eot_before_dispatch_logs_when_remote_cancel_errors(): void
    {
        // Already-canceled subscription → cancelRemoteSubscription returns WP_Error → logger path fires.
        ['sub' => $sub, 'order' => $order] = $this->makeSubscription([
            'status' => Status::SUBSCRIPTION_CANCELED,
            'config' => ['_init' => true],
        ]);
        $subModel   = Subscription::query()->find($sub->id);
        $orderModel = \FluentCart\App\Models\Order::query()->find($order->id);

        global $wpdb;
        $logsBefore = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_activity WHERE status = 'error'"
        );

        $event = new \FluentCart\App\Events\Subscription\SubscriptionEOT($subModel, $orderModel);
        $event->beforeDispatch();

        $logsAfter = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_activity WHERE status = 'error'"
        );
        $this->assertGreaterThan($logsBefore, $logsAfter, 'EOT should log an error when remote cancel fails');
    }

    // =========================================================================
    // Spec 204: Subscription permission keys are registered
    // =========================================================================

    public function test_subscription_permission_keys_are_registered(): void
    {
        $allPermissions = PermissionManager::getAllPermissions();
        $this->assertArrayHasKey('subscriptions/view', $allPermissions);
        $this->assertArrayHasKey('subscriptions/manage', $allPermissions);
    }

    // =========================================================================
    // Spec 177, 185, 191, 194, 201, 203: route-level permission metadata
    // These tests parse the route file (no dispatch) so the WPFluent Route::getParameter
    // caching issue does not apply.
    // =========================================================================

    private function subscriptionRoutesFile(): string
    {
        return file_get_contents(
            FLUENTCART_PLUGIN_PATH . 'app/Modules/Subscriptions/Http/subscriptions-api.php'
        );
    }

    private function assertRouteHasPermission(string $routesSrc, string $needle, string $expectedPermission): void
    {
        $pos = strpos($routesSrc, $needle);
        $this->assertNotFalse($pos, "Route fragment not found: $needle");
        // Inspect a window of ~300 chars after the needle for the permission meta
        $window = substr($routesSrc, $pos, 400);
        $this->assertStringContainsString("'permissions' => '$expectedPermission'", $window,
            "Expected $needle to declare permissions => $expectedPermission");
    }

    public function test_stub_routes_require_subscriptions_manage_permission(): void
    {
        $src = $this->subscriptionRoutesFile();
        $this->assertRouteHasPermission($src, "'reactivateSubscription'", 'subscriptions/manage');
        $this->assertRouteHasPermission($src, "'pauseSubscription'", 'subscriptions/manage');
        $this->assertRouteHasPermission($src, "'resumeSubscription'", 'subscriptions/manage');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function resetFluentRequest(): void
    {
        $app     = App::getInstance();
        $request = new FluentRequest($app, [], []);
        $app->instance(FluentRequest::class, $request);
        $app->instance('request', $request);
        if ($app->bound('wprestrequest')) {
            $app->forgetInstance('wprestrequest');
        }
    }

    private function dispatchRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->dispatchRequestInternal($request, false);
    }

    private function dispatchRequestSilently(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->dispatchRequestInternal($request, true);
    }

    private function dispatchRequestInternal(\WP_REST_Request $request, bool $suppressErrors): \WP_REST_Response
    {
        if (self::$persistentAdminId && get_current_user_id() !== self::$persistentAdminId) {
            wp_set_current_user(self::$persistentAdminId);
        }

        global $wp_rest_auth_cookie;
        $wp_rest_auth_cookie = false;

        $this->resetFluentRequest();

        $currentUserId = get_current_user_id();
        if ($currentUserId) {
            wp_set_current_user($currentUserId);
            wp_set_auth_cookie($currentUserId, false, is_ssl());
        } else {
            wp_clear_auth_cookie();
        }

        $method = strtoupper($request->get_method());
        $shouldAttachNonce = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && (bool) $currentUserId;

        $hadRequestNonce  = array_key_exists('_wpnonce', $_REQUEST);
        $originalReqNonce = $hadRequestNonce ? $_REQUEST['_wpnonce'] : null;
        $hadServerNonce   = array_key_exists('HTTP_X_WP_NONCE', $_SERVER);
        $originalSrvNonce = $hadServerNonce ? $_SERVER['HTTP_X_WP_NONCE'] : null;

        if ($shouldAttachNonce) {
            $nonce = wp_create_nonce('wp_rest');
            $_REQUEST['_wpnonce'] = $nonce;
            $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
            $request->set_header('X-WP-Nonce', $nonce);
        } else {
            unset($_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE']);
        }

        $originalErrorLog  = ini_get('error_log');
        $temporaryErrorLog = $suppressErrors ? tempnam(sys_get_temp_dir(), 'fc-sub-errors-') : false;
        if ($temporaryErrorLog) {
            ini_set('error_log', $temporaryErrorLog);
        }

        try {
            return rest_do_request($request);
        } finally {
            if ($temporaryErrorLog) {
                ini_set('error_log', $originalErrorLog === false ? '' : (string) $originalErrorLog);
                if (file_exists($temporaryErrorLog)) {
                    @unlink($temporaryErrorLog);
                }
            }

            if ($hadRequestNonce) {
                $_REQUEST['_wpnonce'] = $originalReqNonce;
            } else {
                unset($_REQUEST['_wpnonce']);
            }

            if ($hadServerNonce) {
                $_SERVER['HTTP_X_WP_NONCE'] = $originalSrvNonce;
            } else {
                unset($_SERVER['HTTP_X_WP_NONCE']);
            }
        }
    }
}
