<?php

namespace Tests\Integration\Services;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\ShortCodeParser\Parsers\DownloadParser;
use FluentCart\App\Services\ShortCodeParser\Parsers\ItemParser;
use FluentCart\App\Services\ShortCodeParser\Parsers\OrderParser;
use FluentCart\App\Services\ShortCodeParser\Parsers\SettingsParser;
use FluentCart\App\Services\ShortCodeParser\Parsers\SubscriptionParser;
use FluentCart\App\Services\ShortCodeParser\Parsers\TransactionParser;
use FluentCart\App\Services\ShortCodeParser\Parsers\UserParser;
use FluentCart\App\Services\ShortCodeParser\Parsers\WPParser;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\BuildsSmartCodeParserFixtures;
use Tests\Support\Traits\ConfiguresStoreOrderMode;
use FluentCart\App\Services\Translations\TransStrings;

/**
 * Coverage for all 9 smartcode parsers under app/Services/ShortCodeParser/Parsers/.
 */
class ParsersTest extends WPTestCase
{
    use BuildsSmartCodeParserFixtures;
    use ConfiguresStoreOrderMode;

    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();

        // The date expectations are FluentCart's own M j, Y. The formats are
        // memoised per process in TransStrings, so pin the source and refresh.
        $this->setStoreSetting('date_time_format_source', 'fluent_cart');
        TransStrings::dateTimeStrings(true);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        // After the rollback, so the memo is rebuilt from the restored options.
        StoreSettings::clearCache();
        TransStrings::dateTimeStrings(true);
    }

    // =========================================================================
    // OrderParser — Spec 147-170
    // =========================================================================

    /** Spec 147 */
    public function test_order_parser_constructor_reads_user_tz_with_utc_fallback(): void
    {
        $order = $this->buildParserOrder(['config' => ['user_tz' => 'Invalid/Zone']]);
        $parser = new OrderParser(['order' => $order]);
        $ref = new \ReflectionClass($parser);
        $tzProp = $ref->getProperty('orderTz');
        $tzProp->setAccessible(true);
        $this->assertSame('UTC', $tzProp->getValue($parser));
    }

    /** Spec 148 */
    public function test_order_parser_billing_address_field(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $result = $parser->parse('billing.city', 'billing.city');
        $this->assertSame('New York', $result);
    }

    /** Spec 149 */
    public function test_order_parser_shipping_formatted_city(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $result = $parser->parse('shipping.city', 'shipping.city');
        // OrderAddress.city may be returned formatted or as raw; both contain 'Los Angeles'
        $this->assertStringContainsString('Los Angeles', (string) $result);
    }

    /** Spec 150 */
    public function test_order_parser_created_at_formats_via_wp_date(): void
    {
        $order = $this->buildParserOrder();
        $this->setStoreSetting('date_time_format_source', 'wordpress');
        update_option('date_format', 'Y-m-d');
        TransStrings::dateTimeStrings(true);
        $parser = new OrderParser(['order' => $order]);
        $result = $parser->parse('created_at', 'created_at');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result);
    }

    /** Spec 151 */
    public function test_order_parser_cent_columns_divide_by_100(): void
    {
        $order = $this->buildParserOrder(['total_amount' => 12345, 'shipping_total' => 500]);
        $parser = new OrderParser(['order' => $order]);
        $this->assertSame('123.45', $parser->parse('total_amount', 'order.total_amount'));
        $this->assertSame('5', $parser->parse('shipping_total', 'order.shipping_total'));
    }

    /** Spec 152 */
    public function test_order_parser_formatted_suffix_returns_price_html(): void
    {
        $order = $this->buildParserOrder(['total_amount' => 12345]);
        $parser = new OrderParser(['order' => $order]);
        $html = $parser->parse('total_amount_formatted', 'order.total_amount_formatted');
        $this->assertStringContainsString('123.45', (string) $html);
    }

    /** Spec 153 */
    public function test_order_parser_item_count_method(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $this->assertSame('0', $parser->parse('item_count', 'order.item_count'));
    }

    /** Spec 154 */
    public function test_order_parser_is_digital_method(): void
    {
        $digitalOrder = $this->buildParserOrder(['fulfillment_type' => 'digital']);
        $this->assertSame('yes', (new OrderParser(['order' => $digitalOrder]))->parse('is_digital', 'order.is_digital'));

        $physicalOrder = $this->buildParserOrder(['fulfillment_type' => 'physical']);
        $this->assertSame('no', (new OrderParser(['order' => $physicalOrder]))->parse('is_digital', 'order.is_digital'));
    }

    /** Spec 155 */
    public function test_order_parser_attribute_map_resolves(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $this->assertSame((string) $order->id, (string) $parser->parse('id', 'order.id'));
        $this->assertSame((string) $order->status, (string) $parser->parse('status', 'order.status'));
    }

    /** Spec 156 */
    public function test_order_parser_items_table_html(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $html = $parser->parse('items_table', 'order.items_table');
        $this->assertIsString($html);
        // Items table view emits a table tag
        $this->assertStringContainsString('table', $html);
    }

    /** Spec 157 */
    public function test_order_parser_subscription_details_empty_when_no_subscriptions(): void
    {
        // Subscriptions committed past rollback by earlier DDL-bearing tests
        // (TC-058) keep their parent_order_id, which a fresh order's id can
        // reuse — the order would then render a subscription it never had.
        // Clearing inside this test's transaction rolls back with it.
        Subscription::query()->delete();

        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $this->assertSame('', $parser->parse('subscription_details', 'order.subscription_details'));
    }

    /**
     * Spec 157 (the populated half)
     *
     * The empty case above cannot see the rendering branch: delete
     * OrderParser's subscription_details view call and it still returns '', so
     * that test stays green while every customer template silently loses its
     * subscription block.
     */
    public function test_order_parser_subscription_details_renders_when_the_order_has_subscriptions(): void
    {
        Subscription::query()->delete();

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);
        $product = (new ProductFactory())
            ->withVariantAttributes(['payment_type' => 'subscription'])
            ->create();
        $variant = $product->variants->first();

        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
            'item_name'       => 'Rendered Plan',
        ]);

        $loaded = Order::query()->with(['subscriptions'])->find($order->id);
        $html = (new OrderParser(['order' => $loaded]))->parse('subscription_details', 'order.subscription_details');

        $this->assertNotSame('', $html, 'an order that owns a subscription must render the block, not an empty string');
        $this->assertStringContainsString($subscription->item_name, $html,
            'the rendered block names the subscribed item');
        $this->assertStringContainsString('29.99', $html,
            'the rendered block states the recurring amount the customer will be charged');
    }

    /** Spec 159 */
    public function test_order_parser_download_details_empty_when_no_downloads(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $this->assertSame('', $parser->parse('download_details', 'order.download_details'));
    }

    /** Spec 160 */
    public function test_order_parser_address_details_renders(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $html = $parser->parse('address_details', 'order.address_details');
        $this->assertIsString($html);
    }

    /** Spec 161 */
    public function test_order_parser_payment_summary_renders(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $html = $parser->parse('payment_summary', 'order.payment_summary');
        $this->assertIsString($html);
        $this->assertStringContainsString('table', $html);
    }

    /** Spec 163 */
    public function test_order_parser_get_discount_total_sums_in_cents(): void
    {
        $order = $this->buildParserOrder(['coupon_discount_total' => 500, 'manual_discount_total' => 250]);
        $parser = new OrderParser(['order' => $order]);
        $this->assertSame('7.5', $parser->getDiscountTotal());
    }

    /** Spec 164 */
    public function test_order_parser_get_discount_total_formatted(): void
    {
        $order = $this->buildParserOrder(['coupon_discount_total' => 1000, 'manual_discount_total' => 500]);
        $parser = new OrderParser(['order' => $order]);
        $this->assertStringContainsString('15', $parser->getDiscountTotalFormatted());
    }

    /** Spec 165 */
    public function test_order_parser_get_order_ref_prefers_invoice_no(): void
    {
        $orderWithInvoice = $this->buildParserOrder(['invoice_no' => 'INV-123']);
        $orderWithoutInvoice = $this->buildParserOrder(['invoice_no' => '']);

        $this->assertSame('INV-123', (new OrderParser(['order' => $orderWithInvoice]))->getOrderRef());
        $this->assertSame((string) $orderWithoutInvoice->uuid, (new OrderParser(['order' => $orderWithoutInvoice]))->getOrderRef());
    }

    /** Spec 166 */
    public function test_order_parser_customer_dashboard_link_logged_in_vs_guest(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);

        wp_set_current_user(0);
        $guestLink = $parser->getCustomerDashboardLink('customer_dashboard_link');
        $this->assertStringContainsString('wp-login.php', $guestLink);

        $userId = static::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);
        $loggedLink = $parser->getCustomerDashboardLink('customer_dashboard_link');
        $this->assertStringNotContainsString('wp-login.php', $loggedLink);
    }

    /** Spec 167 */
    public function test_order_parser_customer_dashboard_anchor_link(): void
    {
        $order = $this->buildParserOrder(['invoice_no' => 'INV-XYZ']);
        // No profile page configured => returns invoice_no fallback
        $store = new StoreSettings();
        $store->set('customer_profile_page_id', 0);
        $parser = new OrderParser(['order' => $order]);
        $html = $parser->getCustomerDashboardAnchorLink('customer_dashboard_anchor_link', null, ['default_value' => null]);
        $this->assertSame('INV-XYZ', $html);
    }

    /** Spec 168 */
    public function test_order_parser_admin_order_link(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $url = $parser->getAdminOrderLink('admin_order_link');
        $this->assertStringContainsString('admin.php?page=fluent-cart', $url);
        $this->assertStringContainsString('/orders/' . $order->id . '/view', $url);
    }

    /** Spec 169 */
    public function test_order_parser_get_downloads_empty_when_none(): void
    {
        $order = $this->buildParserOrder();
        $parser = new OrderParser(['order' => $order]);
        $this->assertSame('', $parser->getDownloads());
    }

    // =========================================================================
    // SettingsParser — Spec 171-177
    // =========================================================================

    /** Spec 171 */
    public function test_settings_parser_store_name(): void
    {
        $store = new StoreSettings();
        $store->set('store_name', 'My Test Store');
        $this->assertSame('My Test Store', (new SettingsParser([]))->parse('store_name'));
    }

    /** Spec 172 */
    public function test_settings_parser_store_logo_renders_img_or_empty(): void
    {
        $store = new StoreSettings();
        $store->set('store_logo', ['url' => 'https://example.test/logo.png']);
        $store->set('store_name', 'Logo Shop');

        $logoHtml = (new SettingsParser([]))->parse('store_logo');
        $this->assertStringContainsString('<img', $logoHtml);
        $this->assertStringContainsString('logo.png', $logoHtml);

        $store->set('store_logo', ['url' => '']);
        $this->assertSame('', (new SettingsParser([]))->parse('store_logo'));
    }

    /** Spec 173 */
    public function test_settings_parser_store_brand(): void
    {
        $store = new StoreSettings();
        $store->set('store_logo', ['url' => '']);
        $store->set('store_name', 'BrandText');
        $this->assertSame('BrandText', (new SettingsParser([]))->parse('store_brand'));

        $store->set('store_logo', ['url' => 'https://example.test/brand.png']);
        $brandHtml = (new SettingsParser([]))->parse('store_brand');
        $this->assertStringContainsString('<img', $brandHtml);
    }

    /** Spec 174 */
    public function test_settings_parser_store_country_returns_name(): void
    {
        $store = new StoreSettings();
        $store->set('store_country', 'US');
        $rendered = (new SettingsParser([]))->parse('store_country');
        $this->assertStringContainsString('United States', (string) $rendered);
    }

    /** Spec 175 */
    public function test_settings_parser_store_state_returns_name(): void
    {
        $store = new StoreSettings();
        $store->set('store_country', 'US');
        $store->set('store_state', 'CA');
        $name = (new SettingsParser([]))->parse('store_state');
        $this->assertSame('California', $name);
    }

    /** Spec 176 */
    public function test_settings_parser_store_address_lines(): void
    {
        $store = new StoreSettings();
        $store->set('store_address1', '123 Main St');
        $store->set('store_address2', 'Suite 4');
        $this->assertSame('123 Main St', (new SettingsParser([]))->parse('store_address'));
        $this->assertSame('Suite 4', (new SettingsParser([]))->parse('store_address2'));
    }

    /** Spec 177 */
    public function test_settings_parser_city_postcode(): void
    {
        $store = new StoreSettings();
        $store->set('store_city', 'Townsville');
        $store->set('store_postcode', '99999');
        $this->assertSame('Townsville', (new SettingsParser([]))->parse('store_city'));
        $this->assertSame('99999', (new SettingsParser([]))->parse('store_postcode'));
    }

    // =========================================================================
    // WPParser — Spec 178-181
    // =========================================================================

    /** Spec 178 */
    public function test_wp_parser_site_url(): void
    {
        $this->assertSame(get_bloginfo('url'), (new WPParser([]))->parse('site_url'));
    }

    /** Spec 179 */
    public function test_wp_parser_url_alias(): void
    {
        $this->assertSame(get_bloginfo('url'), (new WPParser([]))->parse('url'));
    }

    /** Spec 180 */
    public function test_wp_parser_admin_email(): void
    {
        $this->assertSame(get_bloginfo('admin_email'), (new WPParser([]))->parse('admin_email'));
    }

    /** Spec 181 */
    public function test_wp_parser_site_name(): void
    {
        update_option('blogname', 'WPParserShop');
        $this->assertSame('WPParserShop', (new WPParser([]))->parse('site_name'));
    }

    // =========================================================================
    // UserParser — Spec 182-186
    // =========================================================================

    /** Spec 182 */
    public function test_user_parser_reads_user_id_from_data(): void
    {
        $userId = static::factory()->user->create(['role' => 'subscriber', 'user_email' => 'first-test@example.test']);
        $this->assertSame('first-test@example.test', (new UserParser(['user_id' => $userId]))->parse('user_email'));

        // Falls back to customer.user_id
        $userId2 = static::factory()->user->create(['role' => 'subscriber', 'user_email' => 'fallback-test@example.test']);
        $this->assertSame('fallback-test@example.test', (new UserParser(['customer' => ['user_id' => $userId2]]))->parse('user_email'));
    }

    /** Spec 183 */
    public function test_user_parser_first_last_name_via_user_meta(): void
    {
        $userId = static::factory()->user->create(['role' => 'subscriber']);
        update_user_meta($userId, 'first_name', 'Alice');
        update_user_meta($userId, 'last_name', 'Wonder');
        $this->assertSame('Alice', (new UserParser(['user_id' => $userId]))->parse('first_name'));
        $this->assertSame('Wonder', (new UserParser(['user_id' => $userId]))->parse('last_name'));
    }

    /** Spec 184 */
    public function test_user_parser_display_name_email(): void
    {
        $userId = static::factory()->user->create([
            'role'         => 'subscriber',
            'display_name' => 'Display Bob',
            'user_email'   => 'bob@x.test',
        ]);
        $this->assertSame('Display Bob', (new UserParser(['user_id' => $userId]))->parse('display_name'));
        $this->assertSame('bob@x.test', (new UserParser(['user_id' => $userId]))->parse('user_email'));
    }

    /** Spec 185 */
    public function test_user_parser_returns_empty_when_user_missing(): void
    {
        $this->assertSame('', (new UserParser(['user_id' => 9999999]))->parse('first_name'));
    }

    // =========================================================================
    // TransactionParser — Spec 187-190
    // =========================================================================

    /** Spec 187 */
    public function test_transaction_parser_returns_code_when_missing(): void
    {
        $this->assertSame('transaction.total', (new TransactionParser([]))->parse('total', 'transaction.total'));
    }

    /** Spec 188 */
    public function test_transaction_parser_total_divides_by_100(): void
    {
        $txn = ['total' => 4900, 'currency' => 'USD'];
        $this->assertSame('49', (new TransactionParser(['transaction' => $txn]))->parse('total', 'transaction.total'));
    }

    /** Spec 189 */
    public function test_transaction_parser_total_formatted_html(): void
    {
        $txn = ['total' => 4900, 'currency' => 'USD'];
        $html = (new TransactionParser(['transaction' => $txn]))->parse('total_formatted', 'transaction.total_formatted');
        $this->assertStringContainsString('49', (string) $html);
    }

    /** Spec 190 */
    public function test_transaction_parser_refund_amount_helpers(): void
    {
        $txn = ['total' => 1000, 'currency' => 'USD'];
        $parser = new TransactionParser(['transaction' => $txn]);
        $this->assertSame('10', $parser->getRefundAmount());
        $this->assertStringContainsString('10', $parser->getRefundAmountFormatted());
    }

    // =========================================================================
    // ItemParser — Spec 191-199
    // =========================================================================

    /** Spec 191 — constructor reads item + order */
    public function test_item_parser_constructor_reads_item_and_order(): void
    {
        $parser = new ItemParser(['item' => ['post_title' => 'X'], 'order' => null]);
        $this->assertSame('X', $parser->parse('name'));
    }

    /** Spec 192 */
    public function test_item_parser_name(): void
    {
        $this->assertSame('From Title', $this->buildItemParser(['post_title' => 'From Title'])->parse('name'));
        $this->assertSame('Fallback', $this->buildItemParser(['post_title' => '', 'title' => 'Fallback'])->parse('name'));
    }

    /** Spec 193 */
    public function test_item_parser_variant(): void
    {
        $this->assertSame('Var-A', $this->buildItemParser(['title' => 'Var-A'])->parse('variant'));
    }

    /** Spec 194 */
    public function test_item_parser_quantity(): void
    {
        $this->assertSame('3', $this->buildItemParser(['quantity' => 3])->parse('quantity'));
    }

    /** Spec 195 */
    public function test_item_parser_price_and_formatted(): void
    {
        $this->assertSame('19.99', $this->buildItemParser(['line_total' => 1999])->parse('price'));
        $this->assertSame('$19.99', $this->buildItemParser(['formatted_total' => '$19.99'])->parse('price_formatted'));
    }

    /** Spec 196 */
    public function test_item_parser_unit_price(): void
    {
        $this->assertSame('5', $this->buildItemParser(['unit_price' => 500])->parse('unit_price'));
        $formatted = $this->buildItemParser(['unit_price' => 1234])->parse('unit_price_formatted');
        $this->assertStringContainsString('12.34', (string) $formatted);
    }

    /** Spec 197 */
    public function test_item_parser_subtotal(): void
    {
        $this->assertSame('25', $this->buildItemParser(['subtotal' => 2500])->parse('subtotal'));
        $formatted = $this->buildItemParser(['subtotal' => 2599])->parse('subtotal_formatted');
        $this->assertStringContainsString('25.99', (string) $formatted);
    }

    /** Spec 198 */
    public function test_item_parser_payment_info_kses(): void
    {
        $info = '<strong>Info</strong> <script>x</script>';
        $rendered = $this->buildItemParser(['payment_info' => $info])->parse('payment_info');
        $this->assertStringContainsString('<strong>Info</strong>', $rendered);
        $this->assertStringNotContainsString('<script>', $rendered);
    }

    /** Spec 199 */
    public function test_item_parser_unknown_accessor_falls_through(): void
    {
        $this->assertSame('hidden_value', $this->buildItemParser(['custom_field' => 'hidden_value'])->parse('custom_field'));
        $this->assertSame('', $this->buildItemParser([])->parse('missing_field'));
    }

    // =========================================================================
    // SubscriptionParser — Spec 200-209
    // =========================================================================

    /** Spec 200 */
    public function test_subscription_parser_returns_empty_when_missing(): void
    {
        $this->assertSame('', (new SubscriptionParser([]))->parse('item_name'));
    }

    /** Spec 201 */
    public function test_subscription_parser_item_name(): void
    {
        // Real model, not a stdClass fake: the item_name shortcode reads the
        // display_item_name accessor, which only exists on the model. With an
        // item_attributes snapshot the accessor renders the labeled combination
        // (proving the shortcode goes through the accessor, not raw item_name);
        // without one it falls back to item_name.
        $withAttributes = new Subscription([
            'item_name' => 'My Plan',
            'config'    => ['item_attributes' => ['engraving' => ['value' => 'Gold']]],
        ]);
        $parser = new SubscriptionParser(['subscription' => $withAttributes]);
        $this->assertSame('engraving: Gold', $parser->parse('item_name'), 'item_name shortcode should render the attribute combination via display_item_name');

        $plain  = new Subscription(['item_name' => 'My Plan']);
        $parser = new SubscriptionParser(['subscription' => $plain]);
        $this->assertSame('My Plan', $parser->parse('item_name'), 'item_name shortcode should fall back to item_name without an item_attributes snapshot');
    }

    /** Spec 202 */
    public function test_subscription_parser_status_and_interval(): void
    {
        $this->assertSame('active', $this->buildSubscriptionParser(['status' => 'active'])->parse('status'));
        $this->assertSame('monthly', $this->buildSubscriptionParser(['billing_interval' => 'monthly'])->parse('billing_interval'));
    }

    /** Spec 203 */
    public function test_subscription_parser_recurring_amount(): void
    {
        $this->assertSame('29.99', $this->buildSubscriptionParser(['recurring_amount' => 2999])->parse('recurring_amount'));
        $this->assertSame('0', $this->buildSubscriptionParser(['recurring_amount' => 0])->parse('recurring_amount'));
    }

    /** Spec 204 */
    public function test_subscription_parser_recurring_amount_formatted(): void
    {
        $formatted = $this->buildSubscriptionParser(['recurring_amount' => 4999])->parse('recurring_amount_formatted');
        $this->assertStringContainsString('49.99', (string) $formatted);
    }

    /** Spec 205 */
    public function test_subscription_parser_next_billing_date(): void
    {
        $rendered = $this->buildSubscriptionParser(['next_billing_date' => '2030-06-15 12:00:00'])->parse('next_billing_date');
        $this->assertSame('Jun 15, 2030', $rendered);
        $this->assertSame('N/A', $this->buildSubscriptionParser(['next_billing_date' => null])->parse('next_billing_date'));
    }

    /** Spec 206 */
    public function test_subscription_parser_bill_times(): void
    {
        $this->assertSame('12', $this->buildSubscriptionParser(['bill_times' => 12])->parse('bill_times'));
        $this->assertSame('Unlimited', $this->buildSubscriptionParser(['bill_times' => 0])->parse('bill_times'));
    }

    /** Spec 207 */
    public function test_subscription_parser_trial_days(): void
    {
        $this->assertSame('14', $this->buildSubscriptionParser(['trial_days' => 14])->parse('trial_days'));
        $this->assertSame('0', $this->buildSubscriptionParser(['trial_days' => 0])->parse('trial_days'));
    }

    /** Spec 208 */
    public function test_subscription_parser_expire_at(): void
    {
        $this->assertSame('Jan 1, 2030', $this->buildSubscriptionParser(['expire_at' => '2030-01-01 00:00:00'])->parse('expire_at'));
        $this->assertSame('Never', $this->buildSubscriptionParser(['expire_at' => null])->parse('expire_at'));
    }

    /** Spec 209 */
    public function test_subscription_parser_unknown_accessor_falls_through(): void
    {
        $this->assertSame('value-x', $this->buildSubscriptionParser(['custom' => 'value-x'])->parse('custom'));
    }

    // =========================================================================
    // LicenseParser — Spec 210-215
    // =========================================================================

    /** Spec 210 */
    public function test_license_parser_empty_when_missing(): void
    {
        $parser = new \FluentCart\App\Services\ShortCodeParser\Parsers\LicenseParser([]);
        $this->assertSame('', $parser->parse('key'));
    }

    /** Spec 211 */
    public function test_license_parser_key(): void
    {
        $license = (object) ['license_key' => 'ABC-123-XYZ'];
        $parser = new \FluentCart\App\Services\ShortCodeParser\Parsers\LicenseParser(['license' => $license]);
        $this->assertSame('ABC-123-XYZ', $parser->parse('key'));
    }

    /** Spec 212 */
    public function test_license_parser_product_name(): void
    {
        $license = (object) ['product' => (object) ['post_title' => 'My Plugin']];
        $parser = new \FluentCart\App\Services\ShortCodeParser\Parsers\LicenseParser(['license' => $license]);
        $this->assertSame('My Plugin', $parser->parse('product_name'));

        $licenseNoProduct = (object) ['product' => null];
        $parser2 = new \FluentCart\App\Services\ShortCodeParser\Parsers\LicenseParser(['license' => $licenseNoProduct]);
        $this->assertSame('', $parser2->parse('product_name'));
    }

    /** Spec 213 */
    public function test_license_parser_variant(): void
    {
        $license = (object) ['productVariant' => (object) ['variation_title' => 'Pro Tier']];
        $parser = new \FluentCart\App\Services\ShortCodeParser\Parsers\LicenseParser(['license' => $license]);
        $this->assertSame('Pro Tier', $parser->parse('variant'));

        $licenseNoVar = (object) ['productVariant' => null];
        $parser2 = new \FluentCart\App\Services\ShortCodeParser\Parsers\LicenseParser(['license' => $licenseNoVar]);
        $this->assertSame('', $parser2->parse('variant'));
    }

    /** Spec 214 */
    public function test_license_parser_limit(): void
    {
        $licenseLimited = (object) ['limit' => 5];
        $parser1 = new \FluentCart\App\Services\ShortCodeParser\Parsers\LicenseParser(['license' => $licenseLimited]);
        $this->assertSame('5', $parser1->parse('limit'));

        $licenseUnlimited = (object) ['limit' => 0];
        $parser2 = new \FluentCart\App\Services\ShortCodeParser\Parsers\LicenseParser(['license' => $licenseUnlimited]);
        $this->assertSame('Unlimited', $parser2->parse('limit'));
    }

    /** Spec 215 */
    public function test_license_parser_expiration_date(): void
    {
        $licenseDate = (object) ['expiration_date' => '2030-12-31 00:00:00'];
        $parser1 = new \FluentCart\App\Services\ShortCodeParser\Parsers\LicenseParser(['license' => $licenseDate]);
        $this->assertSame('Dec 31, 2030', $parser1->parse('expiration_date'));

        $licenseNever = (object) ['expiration_date' => null];
        $parser2 = new \FluentCart\App\Services\ShortCodeParser\Parsers\LicenseParser(['license' => $licenseNever]);
        $this->assertSame('Never', $parser2->parse('expiration_date'));
    }

    // =========================================================================
    // DownloadParser — Spec 216-219
    // =========================================================================

    /** Spec 216 */
    public function test_download_parser_title(): void
    {
        $parser = new DownloadParser(['download' => ['title' => 'My Doc.pdf']]);
        $this->assertSame('My Doc.pdf', $parser->parse('title'));
    }

    /** Spec 217 */
    public function test_download_parser_product_name(): void
    {
        $parser = new DownloadParser(['download' => ['product_name' => 'Bundle X']]);
        $this->assertSame('Bundle X', $parser->parse('product_name'));
    }

    /** Spec 218 */
    public function test_download_parser_url(): void
    {
        $parser = new DownloadParser(['download' => ['download_url' => 'https://example.test/file.zip']]);
        $this->assertSame('https://example.test/file.zip', $parser->parse('url'));
    }

    /** Spec 219 */
    public function test_download_parser_file_size(): void
    {
        $parser = new DownloadParser(['download' => ['formatted_file_size' => '2.4 MB']]);
        $this->assertSame('2.4 MB', $parser->parse('file_size'));
    }
}
