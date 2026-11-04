<?php

namespace Tests\Integration\Events;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Events\FirstTimePluginActivation;
use FluentCart\App\Events\License\LicenseRenewed;
use FluentCart\App\Events\PlanChanged;
use FluentCart\App\Events\ProductVariationsChanged;
use FluentCart\App\Events\StockChanged;
use FluentCart\App\Events\UserCreated;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Listeners;
use FluentCart\App\Listeners\FirstTimePluginActivation\ManagePayments;
use FluentCart\App\Listeners\FirstTimePluginActivation\SetupStore;
use FluentCart\App\Listeners\UpdateDefaultVariation;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use lucatume\WPBrowser\TestCase\WPTestCase;
use ReflectionClass;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;

class MiscEventsTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->productFactory = new ProductFactory();
    }

    private function reflectListeners(object $event): array
    {
        $ref = new ReflectionClass($event);
        $prop = $ref->getProperty('listeners');
        $prop->setAccessible(true);
        return $prop->getValue($event);
    }

    public function test_license_renewed_constructor_signature_requires_pro_license_class(): void
    {
        // The LicenseRenewed event hard-types its $license argument to FluentCartPro\...\License.
        // In a core-only install, attempting to construct it without that class must raise a clear
        // class-not-found error so callers know FluentCart Pro is required for license renewal flows.
        $reflection = new \ReflectionClass(\FluentCart\App\Events\License\LicenseRenewed::class);
        $params = $reflection->getConstructor()->getParameters();
        $licenseParam = $params[1] ?? null;
        $this->assertNotNull($licenseParam);
        $type = $licenseParam->getType();
        $this->assertNotNull($type);
        $this->assertSame(
            'FluentCartPro\\App\\Modules\\Licensing\\Models\\License',
            $type->getName(),
            'LicenseRenewed must declare its License dependency from FluentCartPro so the missing-Pro failure mode is explicit.'
        );
    }

    // =========================================================================
    // PlanChanged (specs 109-111)
    // =========================================================================

    public function test_plan_changed_hook_and_no_listeners(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $productA = $this->productFactory->create();
        $productB = $this->productFactory->create();

        $event = new PlanChanged($order, $productB->variants->first(), $productA->variants->first());
        $this->assertSame('fluent_cart/plan_changed', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([], $this->reflectListeners($event));
    }

    public function test_plan_changed_to_array_keys(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $productA = $this->productFactory->create();
        $productB = $this->productFactory->create();

        $data = (new PlanChanged(
            $order,
            $productB->variants->first(),
            $productA->variants->first(),
            ['note' => 'upgrade']
        ))->toArray();

        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('customer', $data);
        $this->assertArrayHasKey('new_variation', $data);
        $this->assertArrayHasKey('old_variation', $data);
        $this->assertSame(['note' => 'upgrade'], $data['other_info']);
    }

    public function test_plan_changed_should_create_activity_false(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $productA = $this->productFactory->create();
        $productB = $this->productFactory->create();

        $event = new PlanChanged($order, $productB->variants->first(), $productA->variants->first());
        $this->assertFalse($event->shouldCreateActivity());
    }

    // =========================================================================
    // UserCreated (specs 112-113)
    // =========================================================================

    public function test_user_created_hook_and_no_listeners(): void
    {
        $userId = static::factory()->user->create();
        $user = get_user_by('id', $userId);

        $event = new UserCreated($user, $userId, 'secret');
        $this->assertSame('fluent_cart/user_created', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([], $this->reflectListeners($event));
    }

    public function test_user_created_to_array_keys(): void
    {
        $userId = static::factory()->user->create();
        $user = get_user_by('id', $userId);

        $data = (new UserCreated($user, $userId, 'secret'))->toArray();
        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('userId', $data);
        $this->assertArrayHasKey('password', $data);
        $this->assertSame('secret', $data['password']);
    }

    // =========================================================================
    // StockChanged (specs 114-118)
    // =========================================================================

    public function test_stock_changed_hook_and_no_listeners(): void
    {
        $event = new StockChanged([1, 2, 3], ['ctx' => 'manual']);
        $this->assertSame('fluent_cart/product_stock_changed', $event->hook);
        $this->assertTrue($event->autoFireHook);
        $this->assertSame([], $this->reflectListeners($event));
    }

    public function test_stock_changed_to_array_keys(): void
    {
        $event = new StockChanged([7], ['note' => 'x']);
        $data = $event->toArray();
        $this->assertSame([7], $data['post_ids']);
        $this->assertSame(['note' => 'x'], $data['other_info']);
    }

    public function test_stock_changed_should_create_activity_false(): void
    {
        $event = new StockChanged([1]);
        $this->assertFalse($event->shouldCreateActivity());
    }

    public function test_update_default_variation_sets_first_variant_by_serial_index(): void
    {
        // The default must always become the FIRST variant by serial_index,
        // overriding any prior value and ignoring stock_status / item_status —
        // an out-of-stock, inactive, lowest-serial variant still wins.
        $product = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'serial_index' => 2,
                'manage_stock' => 1,
                'stock_status' => Helper::IN_STOCK,
                'total_stock'  => 5,
                'available'    => 5,
            ])
            ->create();
        $second = $product->variants->first(); // serial_index 2, in stock, active

        // First by serial_index, but out of stock AND inactive — must still win.
        $first = ProductVariation::query()->create([
            'post_id'          => $product->ID,
            'variation_title'  => 'First',
            'serial_index'     => 1,
            'payment_type'     => 'onetime',
            'fulfillment_type' => 'digital',
            'item_status'      => 'inactive',
            'item_price'       => Helper::toCent(10),
            'compare_price'    => 0,
            'item_cost'        => 0,
            'manage_stock'     => 1,
            'stock_status'     => Helper::OUT_OF_STOCK,
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'backorders'       => 0,
            'other_info'       => ['payment_type' => 'onetime'],
        ]);

        // Start with the default pointing at the later (in-stock, active) one.
        ProductDetail::query()->where('post_id', $product->ID)->update([
            'default_variation_id' => $second->id,
        ]);

        $event = new ProductVariationsChanged([$product->ID]);
        UpdateDefaultVariation::handle($event);

        $fresh = ProductDetail::query()->where('post_id', $product->ID)->first();
        $this->assertSame($first->id, (int) $fresh->default_variation_id);
    }

    // =========================================================================
    // FirstTimePluginActivation (specs 119-125)
    // =========================================================================

    public function test_first_time_activation_empty_hook_no_do_action(): void
    {
        $event = new FirstTimePluginActivation();
        $this->assertSame('', $event->hook);
    }

    public function test_first_time_activation_should_create_activity_false(): void
    {
        $event = new FirstTimePluginActivation();
        $this->assertFalse($event->shouldCreateActivity());
    }

    public function test_first_time_activation_listeners_are_payments_and_store(): void
    {
        $event = new FirstTimePluginActivation();
        $this->assertSame(
            [ManagePayments::class, SetupStore::class],
            $this->reflectListeners($event)
        );
    }

    public function test_manage_payments_sets_currency_usd(): void
    {
        $event = new FirstTimePluginActivation();
        ManagePayments::handle($event);

        $settings = new StoreSettings();
        $this->assertSame('USD', $settings->get('currency'));
    }

    public function test_setup_store_sets_store_name_and_logo_empty(): void
    {
        $event = new FirstTimePluginActivation();
        SetupStore::handle($event);

        $raw = get_option('fluent_cart_store_settings', []);
        $this->assertArrayHasKey('store_name', $raw);
        $this->assertArrayHasKey('store_logo', $raw);
        $this->assertSame('', $raw['store_name']);
        $this->assertSame('', $raw['store_logo']);
    }

    public function test_first_time_activation_static_handle_noop_without_flag(): void
    {
        delete_option('fluent_cart_do_activation_redirect');
        update_option('fluent_cart_plugin_once_activated', false);

        FirstTimePluginActivation::handle();

        $this->assertFalse(get_option('fluent_cart_do_activation_redirect', false));
        // plugin_once_activated should remain unchanged (unset/false)
        $this->assertFalse((bool) get_option('fluent_cart_plugin_once_activated', false));
    }

    public function test_first_time_activation_static_handle_dispatches_when_flag_set(): void
    {
        update_option('fluent_cart_do_activation_redirect', true);
        delete_option('fluent_cart_plugin_once_activated');

        // Suppress the wp_redirect(...) call so headers-sent does not abort the test. The filter
        // intercepts the URL before WP attempts to send headers.
        $blockRedirect = function () { return false; };
        add_filter('wp_redirect', $blockRedirect, 1);

        FirstTimePluginActivation::handle();

        remove_filter('wp_redirect', $blockRedirect, 1);

        $this->assertFalse((bool) get_option('fluent_cart_do_activation_redirect', false));
        $this->assertTrue((bool) get_option('fluent_cart_plugin_once_activated', false));
        // Currency/store_name should also be set by the dispatched listeners.
        $raw = get_option('fluent_cart_store_settings', []);
        $this->assertSame('USD', $raw['currency'] ?? null);
    }
}
