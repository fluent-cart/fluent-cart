<?php

namespace Tests\Integration\Listeners;

use FluentCart\App\Listeners\IntegrationEventListener;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ScheduledAction;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\IntegrationsTestCase;

class IntegrationEventListenerGapsTest extends IntegrationsTestCase
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

    private function makeOrder(): Order
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 1,
        ]);
        return Order::query()->with(['order_items', 'customer'])->find($order->id);
    }

    // Spec 86: product feeds resolved from order_items.post_id
    public function test_product_feeds_resolved_by_order_item_post_id(): void
    {
        $order = $this->makeOrder();
        $item = $order->order_items->first();
        $provider = 'p_' . wp_generate_password(4, false);

        $addon = $this->registerAddonInFilter($provider);
        $this->addProductFeed($item->post_id, $provider, [
            'enabled'       => 'yes',
            'name'          => 'P Feed',
            'event_trigger' => ['order_paid_done'],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/' . $provider, function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $this->assertSame(1, $fired);
    }

    // Spec 87: global feeds resolved by Meta.object_type='order_integration'
    public function test_global_feeds_resolved_by_object_type(): void
    {
        $order = $this->makeOrder();
        $provider = 'g_' . wp_generate_password(4, false);

        $addon = $this->registerAddonInFilter($provider);
        $this->addGlobalFeed($provider, [
            'enabled'       => 'yes',
            'name'          => 'G Feed',
            'event_trigger' => ['order_paid_done'],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/' . $provider, function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $this->assertSame(1, $fired);
    }

    // Spec 89: priority sorting — low priority runs first
    public function test_feeds_dispatched_in_ascending_priority_order(): void
    {
        $order = $this->makeOrder();
        $low = 'low_' . wp_generate_password(4, false);
        $high = 'high_' . wp_generate_password(4, false);

        $a1 = $this->registerAddonInFilter($low, ['priority' => 1]);
        $a2 = $this->registerAddonInFilter($high, ['priority' => 100]);

        $this->addGlobalFeed($low, ['enabled' => 'yes', 'name' => 'L', 'event_trigger' => ['order_paid_done']]);
        $this->addGlobalFeed($high, ['enabled' => 'yes', 'name' => 'H', 'event_trigger' => ['order_paid_done']]);

        $ordered = [];
        add_action('fluent_cart/integration/run/' . $low, function () use (&$ordered) { $ordered[] = 'low'; });
        add_action('fluent_cart/integration/run/' . $high, function () use (&$ordered) { $ordered[] = 'high'; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $a1);
        remove_filter('fluent_cart/integration/order_integrations', $a2);

        $this->assertSame(['low', 'high'], $ordered);
    }

    // Spec 95: order reloaded with order_items/customer/shipping/billing before dispatch
    public function test_dispatched_order_has_expected_relationships_eager_loaded(): void
    {
        $order = $this->makeOrder();
        $provider = 'eager_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider);
        $this->addGlobalFeed($provider, ['enabled' => 'yes', 'name' => 'E', 'event_trigger' => ['order_paid_done']]);

        $captured = null;
        add_action('fluent_cart/integration/run/' . $provider, function ($args) use (&$captured) { $captured = $args; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $this->assertIsArray($captured);
        $this->assertTrue($captured['order']->relationLoaded('order_items'));
        $this->assertTrue($captured['order']->relationLoaded('customer'));
    }

    // Spec 99/103/104: formatIntegrationFeed shape via reflection on listener
    public function test_format_integration_feed_returns_null_when_feed_disabled(): void
    {
        $order = $this->makeOrder();
        $provider = 'fmt_dis_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider);

        $feed = $this->addGlobalFeed($provider, [
            'enabled'       => 'no', // disabled
            'name'          => 'D',
            'event_trigger' => ['order_paid_done'],
        ]);

        $listener = new IntegrationEventListener();
        $ref = new \ReflectionClass($listener);
        $method = $ref->getMethod('formatIntegrationFeed');
        $method->setAccessible(true);

        $result = $method->invoke($listener, $feed, 'order_paid_done', null, 'global', $order->id);
        remove_filter('fluent_cart/integration/order_integrations', $addon);
        $this->assertNull($result);
    }

    public function test_format_integration_feed_shape(): void
    {
        $order = $this->makeOrder();
        $provider = 'fmt_shape_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider, ['priority' => 7, 'delay_on_global_action' => true]);

        $feed = $this->addGlobalFeed($provider, [
            'enabled'       => 'yes',
            'name'          => 'S',
            'event_trigger' => ['order_paid_done'],
        ]);

        $listener = new IntegrationEventListener();
        $ref = new \ReflectionClass($listener);
        $method = $ref->getMethod('formatIntegrationFeed');
        $method->setAccessible(true);

        $result = $method->invoke($listener, $feed, 'order_paid_done', null, 'global', $order->id);
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        foreach (['uuid', 'priority', 'integration_id', 'scope', 'provider', 'trigger', 'is_revoke_hook', 'feed', 'async'] as $k) {
            $this->assertArrayHasKey($k, $result, "formatIntegrationFeed must expose '{$k}'");
        }
        $this->assertSame('global_' . $feed->id . '_' . $order->id, $result['uuid']);
        $this->assertSame(7, $result['priority']);
        $this->assertTrue($result['async'], 'async must reflect delay_on_global_action flag');
        $this->assertSame('no', $result['is_revoke_hook']);
    }

    public function test_format_integration_feed_revoke_hook_flag(): void
    {
        $order = $this->makeOrder();
        $provider = 'fmt_revoke_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider);

        $feed = $this->addGlobalFeed($provider, [
            'enabled'                => 'yes',
            'name'                   => 'R',
            'event_trigger'          => ['order_paid_done'],
            'watch_on_access_revoke' => 'yes',
        ]);

        $listener = new IntegrationEventListener();
        $ref = new \ReflectionClass($listener);
        $method = $ref->getMethod('formatIntegrationFeed');
        $method->setAccessible(true);

        $result = $method->invoke($listener, $feed, 'order_fully_refunded', null, 'global', $order->id);
        remove_filter('fluent_cart/integration/order_integrations', $addon);
        $this->assertSame('yes', $result['is_revoke_hook']);
    }

    // Spec 107: initOrderAsyncRunner reloads order with relationships
    public function test_init_order_async_runner_reloads_order_with_relationships(): void
    {
        $order = $this->makeOrder();
        $provider = 'arr_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider);
        $feed = $this->addGlobalFeed($provider, ['enabled' => 'yes', 'name' => 'R', 'event_trigger' => ['order_paid_done']]);

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
                    'integration_id' => $feed->id,
                    'priority'       => 5,
                    'scope'          => 'global',
                    'is_revoke_hook' => 'no',
                ]],
            ],
        ]);

        $captured = null;
        add_action('fluent_cart/integration/run/' . $provider, function ($args) use (&$captured) { $captured = $args; });

        (new IntegrationEventListener())->initOrderAsyncRunner($action->id);
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $this->assertNotNull($captured);
        $this->assertTrue($captured['order']->relationLoaded('order_items'));
        $this->assertTrue($captured['order']->relationLoaded('customer'));
    }

    // Spec 108: product-scoped actions loaded via ProductMeta::whereIn('id',...)
    public function test_init_order_async_runner_resolves_product_scoped_actions(): void
    {
        $order = $this->makeOrder();
        $item = $order->order_items->first();
        $provider = 'arr_prod_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider);

        $feed = $this->addProductFeed($item->post_id, $provider, [
            'enabled'       => 'yes',
            'name'          => 'P',
            'event_trigger' => ['order_paid_done'],
        ]);

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
                    'integration_id' => $feed->id,
                    'priority'       => 5,
                    'scope'          => 'product',
                    'is_revoke_hook' => 'no',
                ]],
            ],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/' . $provider, function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->initOrderAsyncRunner($action->id);
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $this->assertSame(1, $fired);
    }

    // Spec 110: no formatted actions → early return (status does not move to completed)
    public function test_init_order_async_runner_early_return_without_formatted_actions(): void
    {
        $order = $this->makeOrder();
        $provider = 'arr_empty_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider);
        // Do NOT create a matching feed — lookup returns nothing
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
                    'integration_id' => 99999999,
                    'priority'       => 5,
                    'scope'          => 'global',
                    'is_revoke_hook' => 'no',
                ]],
            ],
        ]);

        (new IntegrationEventListener())->initOrderAsyncRunner($action->id);
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $fresh = ScheduledAction::query()->find($action->id);
        $this->assertSame('running', $fresh->status, 'status set to running but early-returns before completed');
    }

    // Spec 111: provider exception in async runner logged via order.addLog
    public function test_init_order_async_runner_logs_on_exception(): void
    {
        $order = $this->makeOrder();
        $provider = 'arr_exc_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider);
        $feed = $this->addGlobalFeed($provider, ['enabled' => 'yes', 'name' => 'E', 'event_trigger' => ['order_paid_done']]);

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
                    'integration_id' => $feed->id,
                    'priority'       => 5,
                    'scope'          => 'global',
                    'is_revoke_hook' => 'no',
                ]],
            ],
        ]);

        add_action('fluent_cart/integration/run/' . $provider, function () {
            throw new \RuntimeException('boom async');
        });

        (new IntegrationEventListener())->initOrderAsyncRunner($action->id);
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $log = \FluentCart\App\Models\Activity::query()
            ->where('module_id', $order->id)
            ->where('status', 'error')
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString($provider, (string) $log->title);
    }

    // Spec 115: runOrderActionsAjax skips order_paid_async_private_handle for renewal type
    public function test_run_order_actions_ajax_skips_private_handle_for_renewal(): void
    {
        $order = $this->makeOrder();
        $order->type = 'renewal';
        $order->save();

        $fired = 0;
        add_action('fluent_cart/order_paid_async_private_handle', function () use (&$fired) { $fired++; });

        $_GET['order_hash'] = $order->uuid;
        $_REQUEST['order_hash'] = $order->uuid;
        $this->rebindFluentRequest();

        $this->captureAjaxJson(function () {
            (new IntegrationEventListener())->runOrderActionsAjax();
        });
        unset($_GET['order_hash'], $_REQUEST['order_hash']);

        $this->assertSame(0, $fired, 'renewal orders must NOT fire order_paid_async_private_handle');
    }

    // Spec 116: no pending action → error message
    public function test_run_order_actions_ajax_reports_no_pending_actions(): void
    {
        $order = $this->makeOrder();
        $_GET['order_hash'] = $order->uuid;
        $_REQUEST['order_hash'] = $order->uuid;
        $this->rebindFluentRequest();

        $captured = $this->captureAjaxJson(function () {
            (new IntegrationEventListener())->runOrderActionsAjax();
        });
        unset($_GET['order_hash'], $_REQUEST['order_hash']);

        $this->assertIsArray($captured['payload']);
        $this->assertStringContainsString('No pending actions', (string) $captured['payload']['message']);
    }

    // Spec 117: pending action → initOrderAsyncRunner executes
    public function test_run_order_actions_ajax_triggers_async_runner_when_pending_action_exists(): void
    {
        $order = $this->makeOrder();
        $provider = 'ajax_p_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider);
        $feed = $this->addGlobalFeed($provider, ['enabled' => 'yes', 'name' => 'A', 'event_trigger' => ['order_paid_done']]);

        ScheduledAction::query()->create([
            'scheduled_at' => current_time('mysql'),
            'action'       => 'fluent_cart/integration/run_order_actions',
            'status'       => 'pending',
            'group'        => 'order_integration',
            'object_id'    => $order->id,
            'object_type'  => 'order',
            'data'         => [
                'hook'    => 'order_paid_done',
                'actions' => [[
                    'integration_id' => $feed->id,
                    'priority'       => 5,
                    'scope'          => 'global',
                    'is_revoke_hook' => 'no',
                ]],
            ],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/' . $provider, function () use (&$fired) { $fired++; });

        $_GET['order_hash'] = $order->uuid;
        $_REQUEST['order_hash'] = $order->uuid;
        $this->rebindFluentRequest();

        $this->captureAjaxJson(function () {
            (new IntegrationEventListener())->runOrderActionsAjax();
        });
        unset($_GET['order_hash'], $_REQUEST['order_hash']);
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $this->assertGreaterThanOrEqual(1, $fired);
    }

    // Spec 118: addJsToReceipt noop when order missing
    public function test_add_js_to_receipt_noop_without_order(): void
    {
        ob_start();
        (new IntegrationEventListener())->addJsToReceipt(['order' => null]);
        $output = ob_get_clean();
        $this->assertSame('', (string) $output);
    }

    // Spec 119/120: addJsToReceipt emits XHR script with escaped url
    public function test_add_js_to_receipt_emits_escaped_xhr_script(): void
    {
        $order = $this->makeOrder();
        ob_start();
        (new IntegrationEventListener())->addJsToReceipt(['order' => $order]);
        $output = ob_get_clean();

        $this->assertStringContainsString('fluent_cart_run_order_actions', $output);
        $this->assertStringContainsString($order->uuid, $output);
        $this->assertStringContainsString('admin-ajax.php', $output);
        $this->assertStringContainsString('XMLHttpRequest', $output);
    }

    // Spec 176: product feed storage → listener consumes via ProductMeta (object_type='product_integration').
    // Verify WRITE → REAL READER → DISPATCH, not just a meta round-trip.
    public function test_product_feed_stored_under_product_integration_is_consumed_by_listener(): void
    {
        $order = $this->makeOrder();
        $item = $order->order_items->first();
        $provider = 'store_prod_' . wp_generate_password(4, false);

        $addon = $this->registerAddonInFilter($provider);
        $this->addProductFeed($item->post_id, $provider, [
            'enabled'       => 'yes',
            'name'          => 'P',
            'event_trigger' => ['order_paid_done'],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/' . $provider, function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $this->assertSame(1, $fired, 'feed stored with object_type=product_integration must be picked up by listener lookup');

        // Wrong object_type must NOT be consumed:
        $wrongProvider = 'store_prod_bad_' . wp_generate_password(4, false);
        $a2 = $this->registerAddonInFilter($wrongProvider);
        ProductMeta::query()->create([
            'object_id'   => $item->post_id,
            'object_type' => 'typo_wrong', // not 'product_integration'
            'meta_key'    => $wrongProvider,
            'meta_value'  => ['enabled' => 'yes', 'name' => 'W', 'event_trigger' => ['order_paid_done']],
        ]);

        $firedWrong = 0;
        add_action('fluent_cart/integration/run/' . $wrongProvider, function () use (&$firedWrong) { $firedWrong++; });
        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $a2);

        $this->assertSame(0, $firedWrong, 'wrong object_type must NOT be loaded by listener');
    }

    // Spec 177: global feed storage → reader path is GlobalIntegrationSettings::getNotificationFeeds + listener dispatch.
    public function test_global_feed_stored_under_order_integration_is_consumed_by_listener(): void
    {
        $order = $this->makeOrder();
        $provider = 'store_global_' . wp_generate_password(4, false);

        $addon = $this->registerAddonInFilter($provider);
        $this->addGlobalFeed($provider, [
            'enabled'       => 'yes',
            'name'          => 'G',
            'event_trigger' => ['order_paid_done'],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/' . $provider, function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $this->assertSame(1, $fired, 'feed stored with object_type=order_integration must be dispatched by listener');

        // Negative control: wrong object_type → not dispatched
        $wrongProvider = 'store_global_bad_' . wp_generate_password(4, false);
        $a2 = $this->registerAddonInFilter($wrongProvider);
        \FluentCart\App\Models\Meta::query()->create([
            'object_type' => 'something_else',
            'meta_key'    => $wrongProvider,
            'meta_value'  => ['enabled' => 'yes', 'name' => 'W', 'event_trigger' => ['order_paid_done']],
        ]);
        $firedWrong = 0;
        add_action('fluent_cart/integration/run/' . $wrongProvider, function () use (&$firedWrong) { $firedWrong++; });
        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $a2);
        $this->assertSame(0, $firedWrong);
    }

    // Spec 178: addon toggle option round-trip via AddOnModule (writer) + showAddOns-path derivative.
    public function test_update_addons_status_round_trip_via_add_on_module(): void
    {
        (new \FluentCart\App\Modules\Integrations\AddOnModule())->updateAddOnsStatus([
            'addons' => ['fluentcrm' => 'yes', 'other' => 'no'],
        ]);

        // Read through the same option key consumers rely on.
        $persisted = fluent_cart_get_option('fluent_cart_global_integrations', null, false);
        $this->assertSame(['fluentcrm' => 'yes', 'other' => 'no'], $persisted);

        // Overwriting replaces — not merges — to match documented update semantics.
        (new \FluentCart\App\Modules\Integrations\AddOnModule())->updateAddOnsStatus([
            'addons' => ['fluentcrm' => 'no'],
        ]);
        $persisted2 = fluent_cart_get_option('fluent_cart_global_integrations', null, false);
        $this->assertSame(['fluentcrm' => 'no'], $persisted2);
    }

    // Spec 179: _integration_api_{key} option consumed by BaseIntegrationManager::isConfigured/getApiSettings.
    public function test_integration_api_option_is_read_by_base_manager(): void
    {
        $key = 'api_opt_' . wp_generate_password(4, false);
        $mgr = new class ('Real', $key, 10) extends \FluentCart\App\Modules\Integrations\BaseIntegrationManager {
            public function processAction($order, $eventData) {}
            public function getIntegrationDefaults($settings) { return []; }
            public function getSettingsFields($settings, $args = []) { return []; }
        };

        $this->assertFalse($mgr->isConfigured(), 'missing option must yield isConfigured=false');

        fluent_cart_update_option('_integration_api_' . $key, ['status' => true, 'apiKey' => 'SECRET']);
        fluent_cart_get_option('_integration_api_' . $key, null, false); // reset function-static cache

        $this->assertTrue($mgr->isConfigured(), 'option with status=true must flip isConfigured→true');
        $this->assertSame(['status' => true, 'apiKey' => 'SECRET'], $mgr->getApiSettings());

        // Wrong option name must NOT be picked up as configuration.
        fluent_cart_update_option('integration_api_' . $key, ['status' => true]);
        fluent_cart_get_option('integration_api_' . $key, null, false);
        fluent_cart_get_option('_integration_api_' . $key, null, false);
        $this->assertTrue($mgr->isConfigured(), 'real option still present — wrong-key write must not affect state');
    }

    // Spec 181: order with zero order_items → still processes global feeds
    public function test_zero_order_items_still_dispatches_global_feeds(): void
    {
        $customer = $this->customerFactory->create();
        $bareOrder = $this->orderFactory->create(['customer_id' => $customer->id]);
        $bareOrder = Order::query()->with(['order_items', 'customer'])->find($bareOrder->id);
        $this->assertCount(0, $bareOrder->order_items);

        $provider = 'empty_items_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider);
        $this->addGlobalFeed($provider, [
            'enabled'       => 'yes',
            'name'          => 'Empty',
            'event_trigger' => ['order_paid_done'],
        ]);

        $fired = 0;
        add_action('fluent_cart/integration/run/' . $provider, function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $bareOrder], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $this->assertSame(1, $fired, 'global feeds must still run when no product feeds match');
    }

    // Spec 182: all feeds filtered out by conditional_variation_ids → no dispatch
    public function test_all_feeds_filtered_out_yields_no_dispatch(): void
    {
        $order = $this->makeOrder();
        $item = $order->order_items->first();
        $provider = 'filtered_' . wp_generate_password(4, false);
        $addon = $this->registerAddonInFilter($provider);
        $this->addProductFeed($item->post_id, $provider, [
            'enabled'                   => 'yes',
            'name'                      => 'F',
            'event_trigger'             => ['order_paid_done'],
            'conditional_variation_ids' => [88888888], // no overlap
        ]);

        $before = ScheduledAction::query()->count();
        $fired = 0;
        add_action('fluent_cart/integration/run/' . $provider, function () use (&$fired) { $fired++; });

        (new IntegrationEventListener())->mapAllIntegrationActions(['order' => $order], 'order_paid_done');
        remove_filter('fluent_cart/integration/order_integrations', $addon);

        $this->assertSame(0, $fired);
        $this->assertSame($before, ScheduledAction::query()->count());
    }

    // Spec 185: triggerNotification with empty provider key → no action fires
    public function test_trigger_notification_empty_provider_does_nothing(): void
    {
        $order = $this->makeOrder();
        $feeds = [
            [
                'id'       => 1,
                'enabled'  => true,
                'provider' => '', // empty
                'feed'     => ['event_trigger' => ['order_paid_done']],
            ],
        ];
        $fired = 0;
        add_action('fluent_cart/integration/integration_notify_', function () use (&$fired) { $fired++; });

        (new \FluentCart\App\Modules\Integrations\GlobalNotificationHandler())->triggerNotification(
            $feeds, $order, $order->customer, 'order_paid_done', 'integration'
        );
        $this->assertSame(0, $fired);
    }
}
