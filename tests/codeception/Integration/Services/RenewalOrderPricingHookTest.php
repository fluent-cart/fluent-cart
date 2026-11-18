<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTaxRateFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\ConfiguresStoreOrderMode;

/**
 * The contract a renewal invoice offers a pricing listener.
 *
 * The invoice is priced once, as it is created: a filter is handed the
 * figures before anything is written, and what it hands back lands on the
 * order, its line and its pending charge — the three rows that the system
 * charge, the pay-now checkout and the receipts read. An action after the
 * write says what was applied.
 */
class RenewalOrderPricingHookTest extends DatabaseTestCase
{
    use ConfiguresStoreOrderMode;

    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private SubscriptionFactory $subscriptionFactory;

    public function setUp(): void
    {
        parent::setUp();

        $this->customerFactory = new CustomerFactory();
        $this->productFactory = new ProductFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->subscriptionFactory = new SubscriptionFactory();

        // OrderFactory creates live-mode orders; RenewalService refuses a
        // subscription whose order mode differs from the store mode.
        $this->setStoreOrderMode(Status::ORDER_MODE_LIVE);
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/renewal/before_create_order');
        remove_all_actions('fluent_cart/renewal/before_commit_order');

        parent::tearDown();
    }

    public function test_a_listener_can_discount_the_invoice_and_every_money_row_agrees(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_total' => 10000]);

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['discount_total'] = 1000;
            $pricing['total'] = 9000;
            $pricing['applied_rules'] = [['rule_id' => 7]];

            return $pricing;
        });

        $created = RenewalService::createRenewalOrders($subscription);

        $this->assertFalse(is_wp_error($created));

        $invoice = Order::query()->with('order_items')->find($created[0]->id);
        $line = $invoice->order_items->first();
        $charge = OrderTransaction::query()->where('order_id', $invoice->id)->first();

        $this->assertSame(1000, (int) $invoice->manual_discount_total, 'The invoice records what was taken');
        $this->assertSame(9000, (int) $invoice->total_amount, 'And bills the rest');
        $this->assertSame(1000, (int) $line->discount_total, 'The line shows the discount, so receipts strike the price');
        $this->assertSame(9000, (int) $line->line_total, 'And carries the discounted line');
        $this->assertSame(9000, (int) $charge->total, 'The pending charge is what a gateway bills — it must agree');
    }

    public function test_with_no_listener_the_invoice_is_exactly_what_it_was(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_total' => 10000]);

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);

        $this->assertSame(0, (int) $invoice->manual_discount_total, 'Nothing listening, nothing taken');
        $this->assertSame(10000, (int) $invoice->total_amount, 'The invoice is the recurring total, as before');
    }

    public function test_the_filter_is_handed_the_invoice_figures_and_the_subscription(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_tax_total' => 2000, 'recurring_total' => 12000]);

        $seen = null;
        add_filter('fluent_cart/renewal/before_create_order', function ($pricing, $context) use (&$seen) {
            $seen = ['pricing' => $pricing, 'context' => $context];

            return $pricing;
        }, 10, 2);

        RenewalService::createRenewalOrders($subscription);

        $this->assertSame(10000, $seen['pricing']['subtotal'], 'The net figure the rules work on');
        $this->assertSame(2000, $seen['pricing']['tax_total']);
        $this->assertSame(12000, $seen['pricing']['total']);
        $this->assertSame(0, $seen['pricing']['discount_total'], 'Nothing taken yet');
        $this->assertSame((int) $subscription->id, (int) $seen['context']['subscription']->id, 'The listener knows whose invoice this is');
    }

    public function test_the_before_commit_action_carries_the_invoice_and_what_was_applied(): void
    {
        $subscription = $this->dueSubscription();

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['applied_rules'] = [['rule_id' => 7]];

            return $pricing;
        });

        $seen = null;
        add_action('fluent_cart/renewal/before_commit_order', function ($data) use (&$seen) {
            $seen = $data;
        });

        $created = RenewalService::createRenewalOrders($subscription);

        $this->assertNotNull($seen, 'The action fires once the invoice exists');
        $this->assertSame((int) $created[0]->id, (int) $seen['order']->id, 'And names the invoice');
        $this->assertSame([['rule_id' => 7]], $seen['pricing']['applied_rules'], 'And carries what the listener applied');
    }

    public function test_a_discount_larger_than_the_invoice_is_capped_at_the_invoice(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_total' => 10000]);

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['discount_total'] = 15000;
            $pricing['total'] = -5000;

            return $pricing;
        });

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);

        $this->assertSame(10000, (int) $invoice->manual_discount_total, 'A listener cannot take more than the invoice holds');
        $this->assertSame(0, (int) $invoice->total_amount, 'And an invoice never bills below zero');
    }

    public function test_a_listener_cannot_record_a_discount_and_still_charge_the_full_total(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_total' => 10000]);

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['discount_total'] = 5000;   // says it took 50.00 ...
            $pricing['total'] = 10000;           // ... but leaves the charge untouched

            return $pricing;
        });

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);
        $charge = OrderTransaction::query()->where('order_id', $invoice->id)->first();

        $this->assertSame(5000, (int) $invoice->manual_discount_total, 'The discount stands');
        $this->assertSame(5000, (int) $invoice->total_amount, 'And the total follows from it, not from what the listener claimed');
        $this->assertSame(5000, (int) $charge->total, 'So the gateway bills what the order says was left');
    }

    public function test_a_listener_cannot_raise_the_tax(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_tax_total' => 2000, 'recurring_total' => 12000]);

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['discount_total'] = 1000;
            $pricing['tax_total'] = 50000;       // more tax than the whole order
            $pricing['total'] = 1;

            return $pricing;
        });

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);

        $this->assertSame(2000, (int) $invoice->tax_total, 'A discount may lower the tax, never raise it');
        $this->assertSame(11000, (int) $invoice->total_amount, '120.00 - 10.00 off, tax held at the original 20.00');
        $this->assertLessThanOrEqual((int) $invoice->total_amount, (int) $invoice->tax_total, 'Tax never exceeds the total');
    }

    public function test_without_a_discount_a_listener_cannot_change_the_totals(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_total' => 10000]);

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['total'] = 1;               // no discount, just a different number
            $pricing['tax_total'] = 999;

            return $pricing;
        });

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);

        $this->assertSame(10000, (int) $invoice->total_amount, 'Only a discount may move the total');
        $this->assertSame(0, (int) $invoice->tax_total);
    }

    public function test_the_tax_rate_rows_follow_a_lowered_tax(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_tax_total' => 2000, 'recurring_total' => 12000]);

        // Two rates on the parent, adding up to its 20.00 tax, with an odd
        // cent so the split has to round.
        (new OrderTaxRateFactory())->create(['order_id' => $subscription->parent_order_id, 'tax_rate_id' => 1, 'order_tax' => 1333, 'total_tax' => 1333]);
        (new OrderTaxRateFactory())->create(['order_id' => $subscription->parent_order_id, 'tax_rate_id' => 2, 'order_tax' => 667, 'total_tax' => 667]);

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['discount_total'] = 1000;
            $pricing['tax_total'] = 1800;

            return $pricing;
        });

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);
        $rows = OrderTaxRate::query()->where('order_id', $invoice->id)->orderBy('tax_rate_id')->get();

        $this->assertSame(1800, (int) $invoice->tax_total);
        $this->assertSame(1800, (int) $rows->sum('total_tax'), 'The breakdown adds up to the tax actually charged');
        $this->assertSame(1800, (int) $rows->sum('order_tax'));
        $this->assertSame(1200, (int) $rows[0]->total_tax, 'Each rate keeps its share: 90% of 13.33');
        $this->assertSame(600, (int) $rows[1]->total_tax, 'And the last rate takes the rounding cent');
    }

    public function test_the_tax_rate_rows_add_up_to_the_order_tax_even_when_the_parent_rows_did_not(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_tax_total' => 2000, 'recurring_total' => 12000]);

        // The parent's rows add up to 25.00 — its first order also taxed a
        // signup fee — while the subscription renews with 20.00 of tax.
        (new OrderTaxRateFactory())->create(['order_id' => $subscription->parent_order_id, 'tax_rate_id' => 1, 'order_tax' => 1500, 'shipping_tax' => 500, 'total_tax' => 2000]);
        (new OrderTaxRateFactory())->create(['order_id' => $subscription->parent_order_id, 'tax_rate_id' => 2, 'order_tax' => 500, 'total_tax' => 500]);

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['discount_total'] = 1000;
            $pricing['tax_total'] = 1800;

            return $pricing;
        });

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);
        $rows = OrderTaxRate::query()->where('order_id', $invoice->id)->orderBy('tax_rate_id')->get();

        $this->assertSame(1800, (int) $rows->sum('total_tax'), 'The rows add up to the tax actually charged, not to the parent\'s');
        $this->assertSame(1440, (int) $rows[0]->total_tax, 'Split by each rate\'s share of the parent: 80% of 18.00');
        $this->assertSame(360, (int) $rows[1]->total_tax, 'And 20%');

        foreach ($rows as $row) {
            $this->assertSame((int) $row->total_tax, (int) $row->order_tax + (int) $row->shipping_tax, 'Each row still adds up within itself');
        }
    }

    public function test_a_renewal_uses_the_saved_shipping_base_with_its_own_shipping_rate(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_tax_total' => 2000, 'recurring_total' => 12000]);

        // 15.00 of tax on 100.00 at 15%, and 5.00 on 50.00 of shipping taxed at 10%.
        (new OrderTaxRateFactory())->create(['order_id' => $subscription->parent_order_id, 'tax_rate_id' => 1, 'order_tax' => 1500, 'shipping_tax' => 500, 'total_tax' => 2000, 'meta' => ['label' => 'VAT', 'rate_percent' => 15, 'shipping_taxable_amount' => 5000]]);

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);
        $rows = TaxSummaryHelper::computeTaxSummary($invoice)['foldedRateLines'];

        $this->assertSame(2000, (int) $rows[0]['tax'], 'Fixture: the renewal keeps the parent row');
        // 20.00 / 15% would read 133.33.
        $this->assertSame(15000, (int) $rows[0]['base']);
    }

    public function test_a_lowered_tax_does_not_keep_the_parent_shipping_base(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_tax_total' => 2000, 'recurring_total' => 12000]);

        // A shipping-only rate: 5.00 of tax on 50.00 of shipping at 10%.
        (new OrderTaxRateFactory())->create(['order_id' => $subscription->parent_order_id, 'tax_rate_id' => 1, 'order_tax' => 1500, 'total_tax' => 1500, 'meta' => ['label' => 'VAT', 'rate_percent' => 15]]);
        (new OrderTaxRateFactory())->create(['order_id' => $subscription->parent_order_id, 'tax_rate_id' => 2, 'shipping_tax' => 500, 'total_tax' => 500, 'meta' => ['label' => 'Ship', 'rate_percent' => 10, 'shipping_taxable_amount' => 5000]]);

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['discount_total'] = 1000;
            $pricing['tax_total'] = 1800;

            return $pricing;
        });

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);
        $rows = TaxSummaryHelper::computeTaxSummary($invoice)['foldedRateLines'];
        $shippingRow = end($rows);

        $this->assertSame(450, (int) $shippingRow['tax'], 'Fixture: the shipping tax is lowered with the rest');
        $this->assertSame(4500, (int) $shippingRow['base'], 'The base follows the lowered tax, not the parent\'s 50.00');
    }

    public function test_a_lowered_tax_scales_the_saved_shipping_base(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_tax_total' => 2000, 'recurring_total' => 12000]);

        // 15.00 of tax on 100.00 at 15%, and 5.00 on 50.00 of shipping taxed at 10%.
        (new OrderTaxRateFactory())->create(['order_id' => $subscription->parent_order_id, 'tax_rate_id' => 1, 'order_tax' => 1500, 'shipping_tax' => 500, 'total_tax' => 2000, 'meta' => ['label' => 'VAT', 'rate_percent' => 15, 'shipping_taxable_amount' => 5000]]);

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['discount_total'] = 1000;
            $pricing['tax_total'] = 1800;

            return $pricing;
        });

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);
        $rows = TaxSummaryHelper::computeTaxSummary($invoice)['foldedRateLines'];

        $this->assertSame(1800, (int) $rows[0]['tax'], 'Fixture: 13.50 item tax and 4.50 shipping tax');
        // 13.50 / 15% + 4.50 / 10%. Dividing all 18.00 by 15% would read 120.00.
        $this->assertSame(13500, (int) $rows[0]['base']);
    }

    public function test_a_tiny_tax_split_over_many_rates_never_goes_negative(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_tax_total' => 2000, 'recurring_total' => 12000]);

        // Four equal rates. Each would round its half cent up to 1 cent, so
        // three rows alone would use 3 cents of a 2 cent tax.
        foreach ([1, 2, 3, 4] as $rateId) {
            (new OrderTaxRateFactory())->create(['order_id' => $subscription->parent_order_id, 'tax_rate_id' => $rateId, 'order_tax' => 500, 'total_tax' => 500]);
        }

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['discount_total'] = 9990;
            $pricing['tax_total'] = 2;

            return $pricing;
        });

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);
        $rows = OrderTaxRate::query()->where('order_id', $invoice->id)->get();

        $this->assertSame(2, (int) $rows->sum('total_tax'), 'The rows still add up to the tax charged');

        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(0, (int) $row->total_tax, 'No rate is ever negative');
            $this->assertGreaterThanOrEqual(0, (int) $row->order_tax);
            $this->assertGreaterThanOrEqual(0, (int) $row->shipping_tax);
        }
    }

    public function test_without_a_discount_the_tax_rate_rows_are_copied_as_they_were(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_tax_total' => 2000, 'recurring_total' => 12000]);
        (new OrderTaxRateFactory())->create(['order_id' => $subscription->parent_order_id, 'tax_rate_id' => 1, 'order_tax' => 2000, 'total_tax' => 2000]);

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);

        $this->assertSame(2000, (int) OrderTaxRate::query()->where('order_id', $invoice->id)->sum('total_tax'));
    }

    public function test_the_before_commit_action_reports_what_was_persisted_not_what_was_asked(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_total' => 10000]);

        add_filter('fluent_cart/renewal/before_create_order', function ($pricing) {
            $pricing['discount_total'] = 15000;   // more than the order holds
            $pricing['total'] = 0;
            $pricing['applied_rules'] = [
                ['rule_id' => 7, 'adjustment_amount' => 12000],
                ['rule_id' => 8, 'adjustment_amount' => 3000],
            ];

            return $pricing;
        });

        $seen = null;
        add_action('fluent_cart/renewal/before_commit_order', function ($data) use (&$seen) {
            $seen = $data['pricing'];
        });

        $invoice = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);

        $this->assertSame((int) $invoice->manual_discount_total, $seen['discount_total'], 'The action reports the discount the order holds');
        $this->assertSame((int) $invoice->total_amount, $seen['total']);
        $this->assertSame((int) $invoice->tax_total, $seen['tax_total']);
        $this->assertSame(10000, array_sum(array_column($seen['applied_rules'], 'adjustment_amount')), 'The rules add up to the capped discount');
        $this->assertSame([10000, 0], array_column($seen['applied_rules'], 'adjustment_amount'), 'Filled in order, the first rule taking what it can');
    }

    public function test_a_throwing_pricing_listener_fails_cleanly_and_releases_the_lock(): void
    {
        global $wpdb;

        $subscription = $this->dueSubscription();
        $ordersBefore = Order::query()->where('parent_id', $subscription->parent_order_id)->count();

        add_filter('fluent_cart/renewal/before_create_order', function () {
            throw new \RuntimeException('a pricing listener broke');
        });

        $result = RenewalService::createRenewalOrders($subscription);

        $this->assertInstanceOf(\WP_Error::class, $result, 'The service reports the failure the way it reports any other');
        $this->assertSame('invoice_creation_failed', $result->get_error_code());
        $this->assertSame($ordersBefore, Order::query()->where('parent_id', $subscription->parent_order_id)->count(), 'No half-priced order is written');
        $this->assertSame(
            '1',
            (string) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', 'fc_renewal_' . $subscription->id)),
            'And the subscription lock is released'
        );
    }

    public function test_a_pricing_listener_that_returns_a_non_array_is_ignored(): void
    {
        $subscription = $this->dueSubscription(['recurring_amount' => 10000, 'recurring_total' => 10000]);

        add_filter('fluent_cart/renewal/before_create_order', function () {
            return null;
        });

        $created = RenewalService::createRenewalOrders($subscription);

        $this->assertFalse(is_wp_error($created), 'A broken return does not break the renewal');
        $invoice = Order::query()->find($created[0]->id);
        $this->assertSame(10000, (int) $invoice->total_amount, 'It is treated as no discount');
        $this->assertSame(0, (int) $invoice->manual_discount_total);
    }

    public function test_a_failing_before_commit_listener_rolls_the_whole_order_back(): void
    {
        global $wpdb;

        $subscription = $this->dueSubscription();
        $ordersBefore = Order::query()->where('parent_id', $subscription->parent_order_id)->count();

        $seenOrderId = 0;
        add_action('fluent_cart/renewal/before_commit_order', function ($data) use (&$seenOrderId) {
            $seenOrderId = (int) $data['order']->id;
            throw new \RuntimeException('the audit write failed');
        });

        $result = RenewalService::createRenewalOrders($subscription);

        $this->assertGreaterThan(0, $seenOrderId, 'The listener runs once the order has an id');
        $this->assertInstanceOf(\WP_Error::class, $result, 'A failure before commit fails the renewal');
        $this->assertSame('invoice_creation_failed', $result->get_error_code());
        $this->assertSame($ordersBefore, Order::query()->where('parent_id', $subscription->parent_order_id)->count(), 'The order is rolled back with it');
        $this->assertNull(OrderTransaction::query()->where('order_id', $seenOrderId)->first(), 'And its pending charge');
        $this->assertSame(
            '1',
            (string) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', 'fc_renewal_' . $subscription->id)),
            'And the subscription lock is released'
        );
    }

    private function dueSubscription(array $overrides = [])
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory
            ->withVariantAttributes(['payment_type' => Status::ORDER_TYPE_SUBSCRIPTION])
            ->create();
        $variant = $product->variants->first();
        $order = $this->orderFactory->withAddresses()->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        return $this->subscriptionFactory->create(array_merge([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => $product->ID,
            'variation_id'      => $variant->id,
            'collection_method' => 'manual',
            'billing_interval'  => 'daily',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ], $overrides));
    }
}
