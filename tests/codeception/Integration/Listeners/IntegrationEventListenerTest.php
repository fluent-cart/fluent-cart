<?php

namespace Tests\Integration\Listeners;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Listeners\IntegrationEventListener;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ScheduledAction;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;

class IntegrationEventListenerTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->productFactory = new ProductFactory();
    }

    private function makeOrder(array $attributes = []): Order
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $order = $this->orderFactory->create(array_merge(['customer_id' => $customer->id], $attributes));
        $this->itemFactory->create($order->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 1,
        ]);
        return Order::query()->with(['order_items', 'customer'])->find($order->id);
    }

    private function addGlobalFeed(string $provider, array $integration): Meta
    {
        return Meta::query()->create([
            'object_type' => 'order_integration',
            'meta_key'    => $provider,
            'meta_value'  => $integration,
        ]);
    }

    private function addProviderFilter(string $provider, array $opts = []): callable
    {
        $filter = function ($addons) use ($provider, $opts) {
            $addons[$provider] = array_merge([
                'enabled'  => true,
                'priority' => 5,
            ], $opts);
            return $addons;
        };
        add_filter('fluent_cart/integration/order_integrations', $filter);
        return $filter;
    }

    // Spec 134: registerHooks binds the expected action hooks at priority 11
    public function test_register_hooks_binds_all_event_hooks(): void
    {
        $listener = new IntegrationEventListener();
        $listener->registerHooks();

        $hooks = [
            'fluent_cart/order_paid_done',
            'fluent_cart/order_status_changed_to_canceled',
            'fluent_cart/order_fully_refunded',
            'fluent_cart/subscription_activated',
            'fluent_cart/subscription_canceled',
            'fluent_cart/subscription_renewed',
            'fluent_cart/subscription_eot',
            'fluent_cart/subscription_expired_validity',
            'fluent_cart/shipping_status_changed_to_shipped',
            'fluent_cart/shipping_status_changed_to_delivered',
        ];

        foreach ($hooks as $hook) {
            $this->assertTrue(has_action($hook) !== false, "Expected binding on {$hook}");
        }
    }

    // Spec 135: binds async runner, receipt js, and ajax handlers
    public function test_register_hooks_binds_infrastructure_hooks(): void
    {
        $listener = new IntegrationEventListener();
        $listener->registerHooks();

        $this->assertNotFalse(has_action('fluent_cart/init_order_async_runner'));
        $this->assertNotFalse(has_action('fluent_cart/after_receipt_first_time'));
        $this->assertNotFalse(has_action('wp_ajax_fluent_cart_run_order_actions'));
        $this->assertNotFalse(has_action('wp_ajax_nopriv_fluent_cart_run_order_actions'));
    }

    // Spec 136: mapAllIntegrationActions early-returns when order missing
    public function test_map_all_integration_actions_noop_without_order(): void
    {
        $listener = new IntegrationEventListener();
        $before = ScheduledAction::query()->count();
        $listener->mapAllIntegrationActions(['order' => null], 'order_paid_done');
        $this->assertSame($before, ScheduledAction::query()->count());
    }

    // Spec 137: early-return when no enabled addons
    public function test_map_all_integration_actions_noop_without_enabled_addons(): void
    {
        $filter = function () { return []; };
        add_filter('fluent_cart/integration/order_integrations', $filter);

        $listener = new IntegrationEventListener();
        $order = $this->makeOrder();
        $fired = 0;
        add_action('fluent_cart/integration/run/fakeprovider', function () use (&$fired) {
            $fired++;
        });

        $listener->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $filter);

        $this->assertSame(0, $fired);
    }

    // Spec 138: feed skipped when integration.enabled != 'yes' OR addon disabled
    public function test_feed_skipped_when_not_enabled(): void
    {
        $order = $this->makeOrder();

        $addonFilter = $this->addProviderFilter('fakep1', ['enabled' => true]);
        $this->addGlobalFeed('fakep1', [
            'enabled'       => 'no',
            'event_trigger' => ['order_paid_done'],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/fakep1', function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');

        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        $this->assertSame(0, $fired);
    }

    // Spec 139: feed skipped when hook not in event_trigger and not a revoke hook
    public function test_feed_skipped_when_hook_not_triggered(): void
    {
        $order = $this->makeOrder();
        $addonFilter = $this->addProviderFilter('fakep2');
        $this->addGlobalFeed('fakep2', [
            'enabled'       => 'yes',
            'event_trigger' => ['subscription_activated'],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/fakep2', function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');

        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        $this->assertSame(0, $fired);
    }

    // Spec 140: revoke hooks included when watch_on_access_revoke=yes
    public function test_revoke_hook_included_when_watch_flag_yes(): void
    {
        $order = $this->makeOrder();
        $addonFilter = $this->addProviderFilter('fakep3');
        $this->addGlobalFeed('fakep3', [
            'enabled'                => 'yes',
            'event_trigger'          => ['order_paid_done'],
            'watch_on_access_revoke' => 'yes',
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/fakep3', function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_fully_refunded');

        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        $this->assertSame(1, $fired);
    }

    // Canceling an order that never received a payment must not fire revoke feeds —
    // its grant feeds never ran, so revoking would strip access granted by other orders
    public function test_cancel_revoke_skipped_for_never_paid_order(): void
    {
        $order = $this->makeOrder(); // factory default: payment pending, total_paid 0
        $addonFilter = $this->addProviderFilter('fakep3a');
        $this->addGlobalFeed('fakep3a', [
            'enabled'                => 'yes',
            'event_trigger'          => ['order_paid_done'],
            'watch_on_access_revoke' => 'yes',
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/fakep3a', function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_status_changed_to_canceled');

        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        $this->assertSame(0, $fired);
    }

    public function test_cancel_revoke_fires_for_paid_order(): void
    {
        $order = $this->makeOrder(['payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PAID]);
        $addonFilter = $this->addProviderFilter('fakep3b');
        $this->addGlobalFeed('fakep3b', [
            'enabled'                => 'yes',
            'event_trigger'          => ['order_paid_done'],
            'watch_on_access_revoke' => 'yes',
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/fakep3b', function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_status_changed_to_canceled');

        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        $this->assertSame(1, $fired);
    }

    // Spec 141: product-scoped feed filtered by conditional_variation_ids
    public function test_product_feed_filtered_by_conditional_variation_ids(): void
    {
        $order = $this->makeOrder();
        $item = $order->order_items->first();
        $addonFilter = $this->addProviderFilter('fakep4');

        ProductMeta::query()->create([
            'object_id'   => $item->post_id,
            'object_type' => 'product_integration',
            'meta_key'    => 'fakep4',
            'meta_value'  => [
                'enabled'                   => 'yes',
                'event_trigger'             => ['order_paid_done'],
                'conditional_variation_ids' => [99999999], // not matching order's variant
            ],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/fakep4', function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');

        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        $this->assertSame(0, $fired);
    }

    // Spec 142: duplicate uuids de-duped
    public function test_duplicate_feed_uuids_deduped(): void
    {
        $order = $this->makeOrder();
        $addonFilter = $this->addProviderFilter('fakep5');

        // single global feed → single uuid; calling map twice should still fire once per call
        $this->addGlobalFeed('fakep5', [
            'enabled'       => 'yes',
            'event_trigger' => ['order_paid_done'],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/fakep5', function () use (&$fired) { $fired++; });

        $listener = new IntegrationEventListener();
        $listener->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        $listener->mapAllIntegrationActions(['order' => $order], 'order_paid_done');

        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        // Listener's pushedIntegrationsCache de-dups within the same listener instance.
        $this->assertSame(1, $fired);
    }

    // Spec 143: order_paid_done forces all feeds to realtime
    public function test_order_paid_done_forces_all_feeds_realtime(): void
    {
        $order = $this->makeOrder();
        $addonFilter = $this->addProviderFilter('fakep6', ['delay_on_global_action' => true]);
        $this->addGlobalFeed('fakep6', [
            'enabled'       => 'yes',
            'event_trigger' => ['order_paid_done'],
        ]);

        $beforeScheduled = ScheduledAction::query()->count();
        $fired = 0;
        add_action('fluent_cart/integration/run/fakep6', function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');

        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        $this->assertSame(1, $fired);
        $this->assertSame($beforeScheduled, ScheduledAction::query()->count());
    }

    // Spec 144: filter forces all feeds async
    public function test_run_all_actions_on_async_filter_forces_background(): void
    {
        $order = $this->makeOrder();
        $addonFilter = $this->addProviderFilter('fakep7');
        $forceAsync = function () { return true; };
        add_filter('fluent_cart/integration/run_all_actions_on_async', $forceAsync);

        $this->addGlobalFeed('fakep7', [
            'enabled'       => 'yes',
            'event_trigger' => ['subscription_activated'],
        ]);

        $before = ScheduledAction::query()->count();

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'subscription_activated');

        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        remove_filter('fluent_cart/integration/run_all_actions_on_async', $forceAsync);

        $this->assertGreaterThan($before, ScheduledAction::query()->count());
    }

    // Spec 145: background feeds written to fct_scheduled_actions
    public function test_background_feeds_written_to_scheduled_actions(): void
    {

        $order = $this->makeOrder();
        $addonFilter = $this->addProviderFilter('fakep8', ['delay_on_global_action' => true]);
        $this->addGlobalFeed('fakep8', [
            'enabled'       => 'yes',
            'event_trigger' => ['subscription_activated'],
        ]);

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'subscription_activated');
        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);

        $action = ScheduledAction::query()
            ->where('action', 'fluent_cart/integration/run_order_actions')
            ->where('object_id', $order->id)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($action);
        $this->assertSame('pending', $action->status);
    }

    // Spec 146: realtime feeds fire do_action with order + event_data keys
    public function test_scheduled_action_times_are_utc_on_a_store_in_another_timezone(): void
    {
        $original = get_option('timezone_string');
        update_option('timezone_string', 'Asia/Dhaka'); // UTC+6: site time is 6 hours ahead

        try {
            $order = $this->makeOrder();
            $addonFilter = $this->addProviderFilter('fakep8', ['delay_on_global_action' => true]);
            $this->addGlobalFeed('fakep8', [
                'enabled'       => 'yes',
                'event_trigger' => ['subscription_activated'],
            ]);

            (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'subscription_activated');

            $action = ScheduledAction::query()
                ->where('action', 'fluent_cart/integration/run_order_actions')
                ->where('object_id', $order->id)
                ->orderBy('id', 'DESC')
                ->first();
            $this->assertNotEmpty($action->scheduled_at);
            $this->assertLessThan(120, abs(strtotime($action->scheduled_at . ' UTC') - time()), 'scheduled_at is UTC, like the other columns');

            // The provider stays registered, or the runner drops the feed and never writes completed_at
            (new IntegrationEventListener())->initOrderAsyncRunner($action->id);
            remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
            $done = ScheduledAction::query()->find($action->id);
            $this->assertSame('completed', $done->status);
            $this->assertNotEmpty($done->completed_at, 'An empty value would read as "now" below');
            $this->assertLessThan(120, abs(strtotime($done->completed_at . ' UTC') - time()), 'completed_at is UTC');

            $queueId = (new \FluentCart\App\Hooks\Scheduler\JobRunner())->addQueue(['action' => 'fct_test_utc_clock', 'group' => 'integration']);
            $queued  = ScheduledAction::query()->find($queueId);
            $this->assertNotEmpty($queued->created_at);
            $this->assertLessThan(120, abs(strtotime($queued->created_at . ' UTC') - time()), 'created_at is UTC');
        } finally {
            update_option('timezone_string', $original);
        }
    }

    public function test_realtime_feeds_fire_action_with_order_and_event_data(): void
    {
        $order = $this->makeOrder();
        $addonFilter = $this->addProviderFilter('fakep9');
        $this->addGlobalFeed('fakep9', [
            'enabled'       => 'yes',
            'event_trigger' => ['order_paid_done'],
        ]);

        $received = null;
        add_action('fluent_cart/integration/run/fakep9', function ($args) use (&$received) {
            $received = $args;
        });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');

        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        $this->assertIsArray($received);
        $this->assertArrayHasKey('order', $received);
        $this->assertArrayHasKey('event_data', $received);
    }

    // Spec 147: subscription key injected for subscription/renewal orders (realtime path)
    public function test_subscription_key_injected_when_order_is_subscription(): void
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        $this->itemFactory->create($order->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 1,
        ]);
        $order = Order::query()->with(['order_items', 'customer'])->find($order->id);

        $subscription = \FluentCart\App\Models\Subscription::query()->create([
            'customer_id'            => $customer->id,
            'parent_order_id'        => $order->id,
            'product_id'             => $product->ID,
            'variation_id'           => $variant->id,
            'billing_interval'       => 'monthly',
            'quantity'               => 1,
            'item_name'              => 'Sub',
            'recurring_amount'       => Helper::toCent(20),
            'recurring_total'        => Helper::toCent(20),
            'status'                 => \FluentCart\App\Helpers\Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => 'offline_payment',
            'collection_method'      => 'automatic',
        ]);

        $addonFilter = $this->addProviderFilter('subprovider');
        $this->addGlobalFeed('subprovider', [
            'enabled'       => 'yes',
            'event_trigger' => ['order_paid_done'],
        ]);

        $received = null;
        add_action('fluent_cart/integration/run/subprovider', function ($args) use (&$received) {
            $received = $args;
        });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);

        $this->assertIsArray($received);
        $this->assertArrayHasKey('subscription', $received['event_data']);
        $this->assertSame($subscription->id, (int) $received['event_data']['subscription']->id);
    }

    // Spec 148: provider action exception logged via order->addLog
    public function test_provider_exception_caught_and_logged(): void
    {
        $order = $this->makeOrder();
        $addonFilter = $this->addProviderFilter('fakep10');
        $this->addGlobalFeed('fakep10', [
            'enabled'       => 'yes',
            'event_trigger' => ['order_paid_done'],
        ]);

        add_action('fluent_cart/integration/run/fakep10', function () {
            throw new \RuntimeException('boom');
        });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);

        $log = \FluentCart\App\Models\Activity::query()
            ->where('module_id', $order->id)
            ->where('status', 'error')
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('fakep10', (string) $log->title);
    }

    // Spec 149: initOrderAsyncRunner transitions status to completed with completed_at + response_note set
    public function test_init_order_async_runner_marks_completed_with_metadata(): void
    {
        $order = $this->makeOrder();
        $addonFilter = $this->addProviderFilter('fakerunner');
        $providerFeed = Meta::query()->create([
            'object_type' => 'order_integration',
            'meta_key'    => 'fakerunner',
            'meta_value'  => [
                'enabled'       => 'yes',
                'event_trigger' => ['order_paid_done'],
            ],
        ]);

        $providerFired = 0;
        add_action('fluent_cart/integration/run/fakerunner', function () use (&$providerFired) {
            $providerFired++;
        });

        $action = ScheduledAction::query()->create([
            'scheduled_at' => current_time('mysql'),
            'action'       => 'fluent_cart/integration/run_order_actions',
            'status'       => 'pending',
            'group'        => 'order_integration',
            'object_id'    => $order->id,
            'object_type'  => 'order',
            'data'         => [
                'hook'    => 'order_paid_done',
                'actions' => [[
                    'integration_id' => $providerFeed->id,
                    'priority'       => 5,
                    'scope'          => 'global',
                    'is_revoke_hook' => 'no',
                ]],
            ],
        ]);

        (new IntegrationEventListener())->initOrderAsyncRunner($action->id);
        remove_filter('fluent_cart/integration/order_integrations', $addonFilter);

        $fresh = ScheduledAction::query()->find($action->id);
        $this->assertSame('completed', $fresh->status, 'final status must be completed (not stuck at running)');
        $this->assertNotEmpty($fresh->completed_at, 'completed_at must be persisted');
        $this->assertNotEmpty($fresh->response_note, 'response_note must be persisted');
        $this->assertGreaterThanOrEqual(1, $providerFired, 'provider action must execute at least once');
    }

    // Spec 150: initOrderAsyncRunner no-ops on wrong action name or non-pending
    public function test_init_order_async_runner_noop_on_wrong_action(): void
    {
        $order = $this->makeOrder();
        $action = ScheduledAction::query()->create([
            'scheduled_at' => current_time('mysql'),
            'action'       => 'something_else',
            'status'       => 'pending',
            'group'        => 'order_integration',
            'object_id'    => $order->id,
            'object_type'  => 'order',
            'data'         => [],
        ]);

        (new IntegrationEventListener())->initOrderAsyncRunner($action->id);

        $fresh = ScheduledAction::query()->find($action->id);
        $this->assertSame('pending', $fresh->status);
    }

    // SCAN-11: the receipt-page request and the background job can both read the
    // action as pending; only the one that claims it may run the integrations.
    public function test_init_order_async_runner_skips_action_claimed_by_another_runner(): void
    {
        $order = $this->makeOrder();
        $addonFilter = $this->addProviderFilter('fakeclaim');
        $providerFeed = Meta::query()->create([
            'object_type' => 'order_integration',
            'meta_key'    => 'fakeclaim',
            'meta_value'  => [
                'enabled'       => 'yes',
                'event_trigger' => ['order_paid_done'],
            ],
        ]);

        $providerFired = 0;
        add_action('fluent_cart/integration/run/fakeclaim', function () use (&$providerFired) {
            $providerFired++;
        });

        $action = ScheduledAction::query()->create([
            'scheduled_at' => current_time('mysql'),
            'action'       => 'fluent_cart/integration/run_order_actions',
            'status'       => 'pending',
            'group'        => 'order_integration',
            'object_id'    => $order->id,
            'object_type'  => 'order',
            'data'         => [
                'hook'    => 'order_paid_done',
                'actions' => [[
                    'integration_id' => $providerFeed->id,
                    'priority'       => 5,
                    'scope'          => 'global',
                    'is_revoke_hook' => 'no',
                ]],
            ],
        ]);

        // The other runner's claim lands after this runner read "pending", before it writes.
        global $wpdb;
        $claimedFirst = false;
        $otherRunnerClaims = function ($sql) use ($wpdb, $action, &$claimedFirst) {
            if (!$claimedFirst && stripos($sql, 'UPDATE') === 0 && strpos($sql, 'fct_scheduled_actions') !== false) {
                $claimedFirst = true;
                $wpdb->update($wpdb->prefix . 'fct_scheduled_actions', ['status' => 'running'], ['id' => $action->id]);
            }
            return $sql;
        };
        add_filter('query', $otherRunnerClaims);

        try {
            (new IntegrationEventListener())->initOrderAsyncRunner($action->id);
        } finally {
            remove_filter('query', $otherRunnerClaims);
            remove_filter('fluent_cart/integration/order_integrations', $addonFilter);
        }

        $this->assertTrue($claimedFirst, 'the runner must have tried to claim the action');
        $this->assertSame(0, $providerFired, 'an action another runner already claimed must not run the integrations again');
    }

    private function rebindFluentRequest(): void
    {
        $app = \FluentCart\App\App::getInstance();
        $request = new \FluentCart\Framework\Http\Request\Request($app, $_GET, $_POST);
        $app->instance(\FluentCart\Framework\Http\Request\Request::class, $request);
        $app->instance('request', $request);
    }

    private function captureAjaxJson(callable $action): array
    {
        $captured = ['payload' => null];
        $dieHandler = function ($message = '') {
            throw new \RuntimeException('__fc_test_ajax_die__:' . (string) $message);
        };
        // Force wp_send_json onto the AJAX branch so wp_die runs (instead of plain exit) in CLI tests.
        $forceAjax = function () { return true; };
        add_filter('wp_doing_ajax', $forceAjax);
        add_filter('wp_die_ajax_handler', function () use ($dieHandler) { return $dieHandler; });
        add_filter('wp_die_handler', function () use ($dieHandler) { return $dieHandler; });

        ob_start();
        try {
            $action();
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), '__fc_test_ajax_die__:') !== 0) {
                ob_end_clean();
                remove_filter('wp_doing_ajax', $forceAjax);
                remove_all_filters('wp_die_ajax_handler');
                remove_all_filters('wp_die_handler');
                throw $e;
            }
        }
        $body = ob_get_clean();
        if ($body !== '' && $body !== false) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $captured['payload'] = $decoded;
            }
        }
        remove_filter('wp_doing_ajax', $forceAjax);
        remove_all_filters('wp_die_ajax_handler');
        remove_all_filters('wp_die_handler');
        return $captured;
    }

    // Spec 151: runOrderActionsAjax error response on missing hash
    public function test_run_order_actions_ajax_errors_on_missing_hash(): void
    {
        unset($_GET['order_hash'], $_REQUEST['order_hash']);
        $this->rebindFluentRequest();
        $captured = $this->captureAjaxJson(function () {
            (new IntegrationEventListener())->runOrderActionsAjax();
        });

        $this->assertIsArray($captured['payload']);
        $this->assertArrayHasKey('message', $captured['payload']);
        $this->assertStringContainsString('Order hash is required', (string) $captured['payload']['message']);
    }

    // Spec 152: runOrderActionsAjax error when order not found
    public function test_run_order_actions_ajax_errors_on_unknown_order(): void
    {
        $_GET['order_hash'] = 'no-such-uuid-' . wp_generate_password(6, false);
        $_REQUEST['order_hash'] = $_GET['order_hash'];
        $this->rebindFluentRequest();

        $captured = $this->captureAjaxJson(function () {
            (new IntegrationEventListener())->runOrderActionsAjax();
        });

        unset($_GET['order_hash'], $_REQUEST['order_hash']);
        $this->assertIsArray($captured['payload']);
        $this->assertArrayHasKey('message', $captured['payload']);
        $this->assertStringContainsString('Order not found', (string) $captured['payload']['message']);
    }

    // Spec 153: runOrderActionsAjax fires async private handle for non-renewal
    public function test_run_order_actions_ajax_fires_async_handle_for_non_renewal(): void
    {
        $order = $this->makeOrder();
        $order->type = 'payment';
        $order->save();

        $fired = 0;
        add_action('fluent_cart/order_paid_async_private_handle', function () use (&$fired) {
            $fired++;
        });

        $_GET['order_hash'] = $order->uuid;
        $_REQUEST['order_hash'] = $order->uuid;
        $this->rebindFluentRequest();

        $this->captureAjaxJson(function () {
            (new IntegrationEventListener())->runOrderActionsAjax();
        });
        unset($_GET['order_hash'], $_REQUEST['order_hash']);

        $this->assertSame(1, $fired);
    }
}
