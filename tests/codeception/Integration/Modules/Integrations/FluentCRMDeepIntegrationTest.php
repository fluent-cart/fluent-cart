<?php

namespace Tests\Integration\Modules\Integrations;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\Integrations\FluentPlugins\FluentCRMDeepIntegration;
use FluentCart\App\Modules\Integrations\FluentPlugins\FluentSupportWidget;
use FluentCart\App\Services\DateTime\DateFormatter;
use FluentCart\App\Services\Translations\TransStrings;
use lucatume\WPBrowser\TestCase\WPTestCase;

class FluentCRMDeepIntegrationTest extends WPTestCase
{
    /** @var array */
    private $originalStoreSettings;

    /** @var string */
    private $originalTimezone;

    /** @var string */
    private $originalDateFormat;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalStoreSettings = get_option('fluent_cart_store_settings', []);
        $this->originalTimezone      = get_option('timezone_string');
        $this->originalDateFormat    = get_option('date_format');
    }

    public function tearDown(): void
    {
        update_option('fluent_cart_store_settings', $this->originalStoreSettings);
        update_option('timezone_string', $this->originalTimezone);
        update_option('date_format', $this->originalDateFormat);
        StoreSettings::clearCache();
        TransStrings::dateTimeStrings(true);
        parent::tearDown();
    }

    /**
     * Point StoreSettings at a timezone source and rebuild the cache between
     * it and DateFormatter, mirroring DateFormatterTest::useSources.
     */
    private function useTimezoneSource(string $timezoneSource): void
    {
        $settings = get_option('fluent_cart_store_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['timezone_source'] = $timezoneSource;
        update_option('fluent_cart_store_settings', $settings);
        StoreSettings::clearCache();
        TransStrings::dateTimeStrings(true);
    }

    private function formatDateForFluentCrm(?string $datetime = null): string
    {
        return (new FluentCRMDeepIntegration())->formatDateForFluentCrm($datetime);
    }

    // Spec 191: empty/falsy $datetime returns ''
    /**
     * @dataProvider emptyDatetimeProvider
     */
    public function test_format_date_for_fluent_crm_returns_empty_string_for_empty_datetime($value): void
    {
        $this->assertSame('', $this->formatDateForFluentCrm($value));
    }

    public function emptyDatetimeProvider(): array
    {
        return [
            'null'  => [null],
            'empty' => [''],
        ];
    }

    // Spec 193: regression pin for the ticket #3632 no-context UTC fallback.
    // DateFormatter::format() on its own falls back to UTC with no order
    // context on the 'fluent_cart' timezone source (see
    // DateFormatterTest::test_fluent_cart_timezone_source_falls_back_to_utc_without_a_context).
    // The FluentCRM widget must NOT reproduce that here -- it pins wp_timezone()
    // explicitly so a German/non-UTC WordPress site still renders its own day.
    public function test_format_date_for_fluent_crm_uses_wp_timezone_instead_of_utc_fallback_with_no_order_context(): void
    {
        update_option('date_format', 'Y-m-d H:i');
        update_option('timezone_string', 'Europe/Berlin');
        $this->useTimezoneSource('fluent_cart');

        // 22:30 UTC is already 2026-08-17 00:30 in Berlin (CEST, UTC+2). The
        // bare DateFormatter::format() call (no context) would still report
        // 2026-08-16, the UTC day.
        $this->assertSame(
            '2026-08-17 00:30',
            $this->formatDateForFluentCrm('2026-08-16 22:30:00'),
            'The widget must render the WordPress site day, not fall back to the UTC day used when no order context is available'
        );
    }

    // Spec 60: base advanced filter options always present
    public function test_add_advanced_filter_options_base_set(): void
    {
        $deep = new FluentCRMDeepIntegration();
        $groups = $deep->addAdvancedFilterOptions([]);
        $this->assertArrayHasKey('fluent_cart', $groups);
        $values = array_column($groups['fluent_cart']['children'], 'value');
        foreach (['commerce_exist', 'ltv', 'aov', 'first_purchase_date', 'last_purchase_date', 'purchased_items', 'variation_purchased', 'purchased_categories', 'commerce_coupons'] as $v) {
            $this->assertContains($v, $values, "advanced filter option '{$v}' missing");
        }
    }

    // Spec 61: license options only with license module
    public function test_license_options_gated_by_license_module(): void
    {
        $deep = new FluentCRMDeepIntegration();
        $groups = $deep->addAdvancedFilterOptions([]);
        $values = array_column($groups['fluent_cart']['children'], 'value');
        $hasLicense = \FluentCart\Api\ModuleSettings::isActive('license');
        if ($hasLicense) {
            $this->assertContains('active_licenses', $values);
            $this->assertContains('license_exist', $values);
        } else {
            $this->assertNotContains('active_licenses', $values);
            $this->assertNotContains('license_exist', $values);
        }
    }

    // Spec 68: product selector returns matching + leftover selections
    public function test_handle_product_selector_returns_matches_and_leftovers(): void
    {
        $deep = new FluentCRMDeepIntegration();
        // Create a product via factory (no variant needed for search)
        $factory = new \Tests\Support\Factory\ProductFactory();
        $product = $factory->create(['post_title' => 'ZZ Specific Product']);

        $out = $deep->handleProductSelectorAjax([], 'ZZ Specific');
        $ids = array_column($out, 'id');
        $this->assertContains((string) $product->ID, $ids);
    }

    // Spec 70: pushInfoWidgetToContact returns widgets unchanged when no Customer found
    public function test_push_info_widget_returns_unchanged_when_no_customer(): void
    {
        $deep = new FluentCRMDeepIntegration();
        $subscriber = (object) ['user_id' => 0, 'email' => 'nomatch-' . wp_generate_password(6, false) . '@example.com'];
        $out = $deep->pushInfoWidgetToContact(['existing' => 'widget'], $subscriber);
        $this->assertSame(['existing' => 'widget'], $out);
    }

    // Spec 71: pushInfoWidgetToContact adds fluent_cart widget when Customer matches
    public function test_push_info_widget_adds_commerce_info_when_customer_matches(): void
    {
        $customer = (new \Tests\Support\Factory\CustomerFactory())->create();
        $subscriber = (object) ['user_id' => 0, 'email' => $customer->email];

        $out = (new FluentCRMDeepIntegration())->pushInfoWidgetToContact([], $subscriber);
        $this->assertArrayHasKey('fluent_cart', $out);
        $this->assertSame('Commerce Info', $out['fluent_cart']['title']);
        $this->assertIsString($out['fluent_cart']['content']);
    }

    // Spec 72: getStatsHtml includes stats + ordered items
    public function test_get_stats_html_contains_ltv_and_purchase_markers(): void
    {
        $customer = (new \Tests\Support\Factory\CustomerFactory())->create([
            'ltv'             => 1000,
            'purchase_count'  => 2,
            'first_purchase_date' => '2024-01-01 00:00:00',
            'last_purchase_date'  => '2024-06-01 00:00:00',
        ]);

        // success_order_items only reaches items whose order carries a
        // payment-success status (Customer::success_order_items ->
        // Status::getOrderPaymentSuccessStatuses), so every fixture order must be
        // paid or the whole "Recent purchases" section never renders.
        //
        // THREE orders, deliberately: the repeat purchase must cross an order
        // boundary. With both grouped rows on one order, a renderer grouping by
        // (order_id, object_id) — or resetting aggregation per order — still
        // renders two rows with a count of 2 and passes. Buying the same product
        // in separate orders is the normal case grouping exists for.
        $earliestOrder = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => \FluentCart\App\Helpers\Status::ORDER_COMPLETED,
            'payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PAID,
        ]);
        $laterOrder = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => \FluentCart\App\Helpers\Status::ORDER_COMPLETED,
            'payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PAID,
        ]);
        $soloOrder = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => \FluentCart\App\Helpers\Status::ORDER_COMPLETED,
            'payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PAID,
        ]);

        // Same object_id, two DIFFERENT orders. Created earliest-first so the
        // earliest purchase also holds the lowest item id: getStatsHtml iterates
        // newest-id-first, so the last write for a product keeps its oldest row,
        // and that row supplies both the rendered date and the order link.
        (new \Tests\Support\Factory\OrderItemFactory())->create((int) $earliestOrder->id, [
            'object_id'  => 4242,
            'post_title' => 'Grouped Product',
            'title'      => 'Large',
            'created_at' => '2024-02-01 00:00:00',
        ]);
        (new \Tests\Support\Factory\OrderItemFactory())->create((int) $laterOrder->id, [
            'object_id'  => 4242,
            'post_title' => 'Grouped Product',
            'title'      => 'Large',
            'created_at' => '2024-03-01 00:00:00',
        ]);
        // A distinct product on its own order, so a renderer that linked every row
        // to one order, or folded distinct products together, fails.
        (new \Tests\Support\Factory\OrderItemFactory())->create((int) $soloOrder->id, [
            'object_id'  => 7777,
            'post_title' => 'Solo Product',
            'title'      => 'Solo Product',
            'created_at' => '2024-04-01 00:00:00',
        ]);

        // A PENDING order for a product that must never surface. Every other fixture
        // order is paid, so without this row, deleting the payment-status filter in
        // Customer::success_order_items() would render identical HTML and pass — while
        // in production unpaid orders would show up as purchases and corrupt the rows,
        // repeat counts, dates and links.
        $pendingOrder = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => \FluentCart\App\Helpers\Status::ORDER_ON_HOLD,
            'payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PENDING,
        ]);
        (new \Tests\Support\Factory\OrderItemFactory())->create((int) $pendingOrder->id, [
            'object_id'  => 9999,
            'post_title' => 'Unpaid Ghost Product',
            'title'      => 'Unpaid Ghost Product',
            'created_at' => '2024-05-01 00:00:00',
        ]);

        $html = (new FluentCRMDeepIntegration())->getStatsHtml($customer);

        // Assertions are bound PER ROW. Searching the whole document for a label,
        // a date, or an href proves only that each token exists somewhere: swapping
        // First Order with Last Order, or exchanging the two product rows' order
        // links, leaves every token and every count intact. So each row is extracted
        // first and its own contents asserted.

        // ---- stat rows: label and value must live in the SAME <li> ----
        preg_match_all('#<li class="fcrm_fc_stat">.*?</li>#s', $html, $statMatches);
        $statRows = $statMatches[0];
        $this->assertCount(4, $statRows, 'getStatsHtml must render exactly four stat rows. HTML: ' . $html);

        $expectedStats = [
            ['Lifetime Value', '10.00', 'lifetime value must render 1000 cents as 10.00 in ITS OWN row'],
            ['Paid Purchases', '>2<', 'purchase count 2 must render in the Paid Purchases row, not merely somewhere in the document'],
            ['First Paid Order', DateFormatter::format('2024-01-01 00:00:00'), 'first_purchase_date must render in the First Paid Order row — a swap with Last Paid Order must fail here'],
            ['Last Paid Order', DateFormatter::format('2024-06-01 00:00:00'), 'last_purchase_date must render in the Last Paid Order row — a swap with First Paid Order must fail here'],
        ];

        foreach ($expectedStats as $expectedStat) {
            list($label, $valueNeedle, $why) = $expectedStat;

            $owning = array_values(array_filter(
                $statRows,
                function ($row) use ($label) {
                    return strpos($row, '>' . $label . '<') !== false;
                }
            ));
            $this->assertCount(1, $owning, 'exactly one stat row must carry the "' . $label . '" caption. HTML: ' . $html);
            $this->assertStringContainsString($valueNeedle, $owning[0], $why . '. Row: ' . $owning[0]);
        }

        // The link must be a REAL anchor in the Lifetime Value row: parsing href=""
        // and exact-matching the URL means href -> data-href, URL-as-text, or a
        // link moved to another row all fail — a substring search over the first
        // row catches none of those.
        $ltvRow = array_values(array_filter($statRows, function ($row) {
            return strpos($row, '>Lifetime Value<') !== false;
        }));
        $this->assertCount(1, $ltvRow, 'exactly one stat row must carry the Lifetime Value caption');
        $this->assertSame(
            1,
            preg_match('#<a\s[^>]*(?<![-\w])href="([^"]+)"#', $ltvRow[0], $ltvHref),
            'the Lifetime Value row must contain a real <a href> anchor. Row: ' . $ltvRow[0]
        );
        $this->assertSame(
            esc_url(\FluentCart\App\Services\URL::getDashboardUrl('customers/' . (int) $customer->id . '/view')),
            $ltvHref[1],
            'the Lifetime Value anchor must point exactly at this customer\'s dashboard view'
        );

        // ---- product rows: name, quantity, date and href must live in the SAME <li> ----
        $this->assertStringContainsString('Recent purchases', $html, 'a customer with paid order items must render the Recent purchases section');

        preg_match_all('#<li class="fcrm_fc_product">.*?</li>#s', $html, $productMatches);
        $productRows = $productMatches[0];
        $this->assertCount(
            2,
            $productRows,
            'two distinct PAID object_ids must produce exactly two product rows — the two rows sharing object_id 4242 group into one, and the pending order contributes none. HTML: ' . $html
        );
        $this->assertStringNotContainsString(
            'Unpaid Ghost Product',
            $html,
            'an item on a PENDING order must never render as a purchase — dropping the payment-status filter in Customer::success_order_items() must fail here'
        );
        $this->assertStringNotContainsString(
            'orders/' . (int) $pendingOrder->id . '/view',
            $html,
            'no row may link to a pending order'
        );

        $groupedRow = array_values(array_filter($productRows, function ($row) {
            return strpos($row, 'Grouped Product') !== false;
        }));
        $this->assertCount(1, $groupedRow, 'exactly one product row must carry the grouped product name');
        $this->assertStringContainsString('<span class="fcrm_fc_product_variant">Large</span>', $groupedRow[0], 'a variant title differing from the product name must render inside its own row');
        $this->assertStringContainsString('<span class="fcrm_fc_product_qty">2</span>', $groupedRow[0], 'the repeat-purchase count of 2 must render in the GROUPED row, not in the single-purchase row');
        $this->assertStringContainsString(DateFormatter::format('2024-02-01 00:00:00'), $groupedRow[0], 'the grouped row must date from the OLDEST of its rows — items are iterated newest-first, so the last write keeps the first order for that product');
        $this->assertSame(
            1,
            preg_match('#<a\s[^>]*(?<![-\w])href="([^"]+)"#', $groupedRow[0], $groupedHref),
            'the grouped row must contain a real <a href> anchor, not a URL rendered as text. Row: ' . $groupedRow[0]
        );
        $this->assertSame(
            esc_url(\FluentCart\App\Services\URL::getDashboardUrl('orders/' . (int) $earliestOrder->id . '/view')),
            $groupedHref[1],
            'the grouped row must link to the EARLIEST order the product was bought on — a renderer grouping by (order_id, object_id), or linking every row to one order, fails here'
        );

        $soloRow = array_values(array_filter($productRows, function ($row) {
            return strpos($row, 'Solo Product') !== false;
        }));
        $this->assertCount(1, $soloRow, 'exactly one product row must carry the second product name');
        $this->assertStringNotContainsString('fcrm_fc_product_qty', $soloRow[0], 'a single purchase must NOT render a repeat-count badge');
        $this->assertStringContainsString(DateFormatter::format('2024-04-01 00:00:00'), $soloRow[0], 'the solo row must carry its own item date');
        $this->assertSame(
            1,
            preg_match('#<a\s[^>]*(?<![-\w])href="([^"]+)"#', $soloRow[0], $soloHref),
            'the solo row must contain a real <a href> anchor. Row: ' . $soloRow[0]
        );
        $this->assertSame(
            esc_url(\FluentCart\App\Services\URL::getDashboardUrl('orders/' . (int) $soloOrder->id . '/view')),
            $soloHref[1],
            'the solo row must link to ITS OWN order — swapping the two rows\' links must fail here'
        );
    }

    /**
     * Extracts the value cell for a single-labelled stat row from getStatsHtml()'s
     * output, asserting the label is unique so a mislabeled or duplicated row fails
     * loudly instead of silently matching the wrong row.
     */
    private function extractStatRowValue(string $html, string $label): string
    {
        preg_match_all('#<li class="fcrm_fc_stat">.*?</li>#s', $html, $matches);
        $owning = array_values(array_filter($matches[0], function ($row) use ($label) {
            return strpos($row, '>' . $label . '<') !== false;
        }));
        $this->assertCount(1, $owning, 'exactly one stat row must carry the "' . $label . '" caption. HTML: ' . $html);

        return $owning[0];
    }

    // Ticket: Commerce Info widget vs. FluentCRM purchase tab consistency.
    // A customer whose only orders are pending/on-hold has never had
    // Customer::recountStat() count a paid order, so purchase_count stays 0 and
    // first/last_purchase_date stay null -- the widget must show that plainly
    // (0 paid purchases, N/A dates) rather than any pending-order figures, even
    // though the FluentCRM purchase tab's own order table/summary lists the
    // pending orders.
    public function test_get_stats_html_shows_zero_paid_purchases_when_only_pending_orders_exist(): void
    {
        $customer = (new \Tests\Support\Factory\CustomerFactory())->create();

        $pendingOrder = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => \FluentCart\App\Helpers\Status::ORDER_ON_HOLD,
            'payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PENDING,
        ]);
        (new \Tests\Support\Factory\OrderItemFactory())->create((int) $pendingOrder->id, [
            'object_id'  => 5151,
            'post_title' => 'Pending Only Product',
            'title'      => 'Pending Only Product',
        ]);

        // Mirrors what production runs after any order/payment change.
        $customer->recountStat();
        $customer = $customer->fresh();

        $html = (new FluentCRMDeepIntegration())->getStatsHtml($customer);

        $this->assertStringContainsString('>0<', $this->extractStatRowValue($html, 'Paid Purchases'), 'a customer with only pending orders must show 0 paid purchases');

        $naLabel = __('N/A', 'fluent-cart');
        $this->assertStringContainsString($naLabel, $this->extractStatRowValue($html, 'First Paid Order'), 'no paid order exists, so First Paid Order must read N/A');
        $this->assertStringContainsString($naLabel, $this->extractStatRowValue($html, 'Last Paid Order'), 'no paid order exists, so Last Paid Order must read N/A');
    }

    // Defensive-guard regression: purchase_count is the source of truth for
    // whether a paid order exists, not the presence of a date value. A refund/
    // cancellation can leave first_purchase_date/last_purchase_date stale until
    // the next recountStat() run; the widget must not trust those columns alone.
    public function test_get_stats_html_ignores_stale_dates_when_purchase_count_is_zero(): void
    {
        $customer = (new \Tests\Support\Factory\CustomerFactory())->create([
            'purchase_count'      => 0,
            'first_purchase_date' => '2020-01-01 00:00:00',
            'last_purchase_date'  => '2020-06-01 00:00:00',
        ]);

        $html = (new FluentCRMDeepIntegration())->getStatsHtml($customer);

        $naLabel = __('N/A', 'fluent-cart');
        $firstRow = $this->extractStatRowValue($html, 'First Paid Order');
        $lastRow = $this->extractStatRowValue($html, 'Last Paid Order');

        $this->assertStringContainsString($naLabel, $firstRow, 'purchase_count=0 must force N/A even though first_purchase_date is stale-populated. Row: ' . $firstRow);
        $this->assertStringContainsString($naLabel, $lastRow, 'purchase_count=0 must force N/A even though last_purchase_date is stale-populated. Row: ' . $lastRow);
        $this->assertStringNotContainsString(DateFormatter::format('2020-01-01 00:00:00'), $firstRow, 'the stale first_purchase_date must never render when purchase_count is 0');
        $this->assertStringNotContainsString(DateFormatter::format('2020-06-01 00:00:00'), $lastRow, 'the stale last_purchase_date must never render when purchase_count is 0');
    }

    // A pending order created BEFORE the customer's only paid order must not
    // leak into First/Last Paid Order -- those must reflect the paid order only,
    // matching Customer::recountStat()'s payment-status-filtered aggregation.
    public function test_get_stats_html_uses_paid_order_date_not_earlier_pending_order(): void
    {
        $customer = (new \Tests\Support\Factory\CustomerFactory())->create();

        $pendingOrder = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => \FluentCart\App\Helpers\Status::ORDER_ON_HOLD,
            'payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PENDING,
            'created_at'     => '2026-09-01 00:00:00',
        ]);
        (new \Tests\Support\Factory\OrderItemFactory())->create((int) $pendingOrder->id, [
            'object_id'  => 5252,
            'post_title' => 'Earlier Pending Product',
            'title'      => 'Earlier Pending Product',
        ]);

        $paidOrder = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => \FluentCart\App\Helpers\Status::ORDER_COMPLETED,
            'payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PAID,
            'created_at'     => '2026-09-16 00:00:00',
        ]);
        (new \Tests\Support\Factory\OrderItemFactory())->create((int) $paidOrder->id, [
            'object_id'  => 5353,
            'post_title' => 'Later Paid Product',
            'title'      => 'Later Paid Product',
        ]);

        $customer->recountStat();
        $customer = $customer->fresh();

        $html = (new FluentCRMDeepIntegration())->getStatsHtml($customer);

        $this->assertStringContainsString('>1<', $this->extractStatRowValue($html, 'Paid Purchases'), 'only one order is paid, so Paid Purchases must read 1');

        $firstRow = $this->extractStatRowValue($html, 'First Paid Order');
        $lastRow = $this->extractStatRowValue($html, 'Last Paid Order');

        $this->assertStringContainsString(DateFormatter::format('2026-09-16 00:00:00'), $firstRow, 'First Paid Order must be the paid order\'s date, not the earlier pending order\'s date');
        $this->assertStringContainsString(DateFormatter::format('2026-09-16 00:00:00'), $lastRow, 'Last Paid Order must be the paid order\'s date, not the earlier pending order\'s date');
        $this->assertStringNotContainsString(DateFormatter::format('2026-09-01 00:00:00'), $firstRow, 'the pending order\'s earlier date must never surface as First Paid Order');
        $this->assertStringNotContainsString(DateFormatter::format('2026-09-01 00:00:00'), $lastRow, 'the pending order\'s earlier date must never surface as Last Paid Order');
    }

    // Ticket: unbounded "Recent purchases" query. getStatsHtml() used to load
    // and group EVERY paid order item the customer ever had; a customer with
    // many distinct products could load thousands of rows on every profile
    // view. The product list is now capped server-side (in SQL) to the most
    // recently purchased products, so this proves both the cap itself and that
    // it keeps the RIGHT products -- the most recent ones, not an arbitrary cut.
    public function test_get_stats_html_caps_recent_purchases_to_the_most_recently_bought_products(): void
    {
        $customer = (new \Tests\Support\Factory\CustomerFactory())->create();

        $order = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => \FluentCart\App\Helpers\Status::ORDER_COMPLETED,
            'payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PAID,
        ]);

        // 22 distinct products, oldest to newest, one item id per product --
        // two more than the widget's cap so the two oldest must be dropped.
        for ($i = 1; $i <= 22; $i++) {
            (new \Tests\Support\Factory\OrderItemFactory())->create((int) $order->id, [
                'object_id'  => 9000 + $i,
                'post_title' => 'Cap Product ' . $i,
                'title'      => 'Cap Product ' . $i,
                'created_at' => sprintf('2026-01-%02d 00:00:00', $i),
            ]);
        }

        $customer->recountStat();
        $customer = $customer->fresh();

        $html = (new FluentCRMDeepIntegration())->getStatsHtml($customer);

        preg_match_all('#<li class="fcrm_fc_product">.*?</li>#s', $html, $productMatches);
        $this->assertCount(20, $productMatches[0], 'the Recent purchases list must be capped, not render all 22 distinct products. HTML: ' . $html);

        $this->assertStringNotContainsString('Cap Product 1<', $html, 'the OLDEST product (by purchase recency) must be dropped once the cap is exceeded');
        $this->assertStringNotContainsString('Cap Product 2<', $html, 'the second-oldest product must also be dropped once the cap is exceeded');
        $this->assertStringContainsString('Cap Product 22', $html, 'the MOST RECENT product must survive the cap');
        $this->assertStringContainsString('Cap Product 3', $html, 'the cap must keep exactly the 20 most recent products, so the 20th-oldest (Cap Product 3) must still be present');
    }

    // Spec 195: FluentSupportWidget must NOT inherit FluentCRM's date/time
    // preference through the shared getStatsHtml() renderer.
    //
    // MUST STAY THE LAST TEST METHOD IN THIS CLASS. It defines a stub
    // FluentCrm\App\Services\Helper via eval() when the real class isn't
    // loaded (neither Integration.suite.yml nor IntegrationPro.suite.yml load
    // the FluentCRM plugin). PHP cannot undefine a class once declared, and
    // this suite runs every test in one process -- Codeception+WPLoader does
    // not honor @runInSeparateProcess (see
    // PermissionManagerTest::test_pro_off_contract_is_not_leaked, same
    // constraint). So once this stub exists, class_exists(Helper::class)
    // stays true for every test declared AFTER it in file order, which would
    // silently change the branch formatDateForFluentCrm() takes in
    // spec 192/193 above. Keeping this test last means nothing in this file
    // runs after the stub is defined.
    public function test_fluent_support_widget_does_not_inherit_fluent_crm_relative_date_preference(): void
    {
        if (!class_exists('FluentCrm\\App\\Services\\Helper')) {
            eval('namespace FluentCrm\\App\\Services; class Helper { public static function isExperimentalEnabled($module) { return false; } public static function formatDateTime($dateTime) { return "relative-from-fluentcrm"; } }');
        }

        // purchase_count must accompany the dates here: getStatsHtml() gates
        // First/Last Paid Order on purchase_count > 0 (see
        // test_get_stats_html_ignores_stale_dates_when_purchase_count_is_zero),
        // and this fixture's own paid order below is what those dates represent.
        $customer = (new \Tests\Support\Factory\CustomerFactory())->create([
            'purchase_count'      => 1,
            'first_purchase_date' => '2024-01-01 00:00:00',
            'last_purchase_date'  => '2024-06-01 00:00:00',
        ]);

        $order = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => \FluentCart\App\Helpers\Status::ORDER_COMPLETED,
            'payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PAID,
        ]);
        (new \Tests\Support\Factory\OrderItemFactory())->create((int) $order->id, [
            'object_id'  => 4242,
            'post_title' => 'Support Widget Product',
            'title'      => 'Support Widget Product',
            'created_at' => '2024-02-01 00:00:00',
        ]);

        $widgets = (new FluentSupportWidget())->getPurchaseWidgets([], (object) ['email' => $customer->email]);
        $this->assertArrayHasKey('fluent_cart_purchases', $widgets);

        $html = $widgets['fluent_cart_purchases']['body_html'];
        $this->assertStringNotContainsString(
            'relative-from-fluentcrm',
            $html,
            'FluentSupport must keep the shared renderer default DateFormatter output even when FluentCRM relative dates are available'
        );
        $this->assertStringContainsString(DateFormatter::format('2024-01-01 00:00:00'), $html);
        $this->assertStringContainsString(DateFormatter::format('2024-02-01 00:00:00'), $html);
    }
}
