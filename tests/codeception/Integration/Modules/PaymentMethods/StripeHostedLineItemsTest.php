<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;

/**
 * Stripe DERIVES a Checkout Session total from line_items — there is no total to
 * assert and no subtractive field. So the sum of what we send is what the buyer
 * is charged, and a breakdown that is one unit off does not error, it mischarges.
 *
 * Every test here asserts the same invariant: either the line items sum EXACTLY
 * to the charge amount, or the builder declined and the aggregate fallback (which
 * is the charge amount by construction) is used. There is no third outcome.
 */
class StripeHostedLineItemsTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
    }

    /**
     * @return array|null
     */
    private function build(Order $order, string $currency, int $chargeAmount)
    {
        $method = new \ReflectionMethod(Processor::class, 'buildHostedLineItems');
        $method->setAccessible(true);

        return $method->invoke(new Processor(), $order, $currency, $chargeAmount);
    }

    private function aggregate(Order $order, string $currency, int $chargeAmount): array
    {
        $method = new \ReflectionMethod(Processor::class, 'aggregateHostedLineItems');
        $method->setAccessible(true);

        return $method->invoke(new Processor(), $order, $currency, $chargeAmount);
    }

    private function sumOf(array $lineItems): int
    {
        $sum = 0;
        foreach ($lineItems as $item) {
            $sum += (int) $item['price_data']['unit_amount'] * (int) $item['quantity'];
        }

        return $sum;
    }

    private function makeOrder(array $orderAttrs, array $items): Order
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(array_merge(['customer_id' => $customer->id], $orderAttrs));

        foreach ($items as $item) {
            $this->itemFactory->create($order->id, $item);
        }

        return Order::query()->find($order->id);
    }

    public function test_single_item_order_itemises_and_sums_to_the_charge(): void
    {
        $order = $this->makeOrder(
            ['total_amount' => 2999],
            [['post_title' => 'Widget', 'title' => 'Large', 'quantity' => 1, 'subtotal' => 2999, 'line_total' => 2999]]
        );

        $lineItems = $this->build($order, 'USD', 2999);

        $this->assertNotNull($lineItems, 'A plain single-item order must produce a breakdown');
        $this->assertCount(1, $lineItems, 'No reconciliation line is needed when the item already sums to the charge');
        $this->assertSame('Widget Large', $lineItems[0]['price_data']['product_data']['name']);
        $this->assertSame('usd', $lineItems[0]['price_data']['currency'], 'Stripe requires a lowercase currency code');
        $this->assertSame(2999, $this->sumOf($lineItems), 'The breakdown must sum to the charge amount');
    }

    public function test_multi_item_order_with_quantities_sums_to_the_charge(): void
    {
        $order = $this->makeOrder(
            ['total_amount' => 7000],
            [
                ['post_title' => 'A', 'title' => 'v1', 'quantity' => 3, 'subtotal' => 3000, 'line_total' => 3000],
                ['post_title' => 'B', 'title' => 'v2', 'quantity' => 2, 'subtotal' => 4000, 'line_total' => 4000],
            ]
        );

        $lineItems = $this->build($order, 'USD', 7000);

        $this->assertNotNull($lineItems);
        $this->assertSame(1000, $lineItems[0]['price_data']['unit_amount'], 'Per-unit amount must be the line total divided by quantity');
        $this->assertSame(3, $lineItems[0]['quantity']);
        $this->assertSame(7000, $this->sumOf($lineItems));
    }

    /**
     * 1000 / 3 leaves a remainder. intdiv floors, so the shortfall must reappear
     * as a reconciliation line — never be silently dropped or rounded up.
     */
    public function test_indivisible_quantity_remainder_is_reconciled_not_lost(): void
    {
        $order = $this->makeOrder(
            ['total_amount' => 1000],
            [['post_title' => 'Odd', 'title' => 'Bundle', 'quantity' => 3, 'subtotal' => 1000, 'line_total' => 1000]]
        );

        $lineItems = $this->build($order, 'USD', 1000);

        $this->assertNotNull($lineItems);
        $this->assertSame(333, $lineItems[0]['price_data']['unit_amount'], 'Per-unit must floor, never round up past the charge');
        $this->assertCount(2, $lineItems, 'The floored remainder must appear as its own line');
        $this->assertSame(1, $lineItems[1]['price_data']['unit_amount'], 'The reconciliation line carries exactly the shortfall');
        $this->assertSame(1000, $this->sumOf($lineItems), 'The breakdown must still sum to the charge amount');
    }

    public function test_shipping_and_exclusive_tax_are_separate_additive_lines(): void
    {
        $order = $this->makeOrder(
            [
                'total_amount'   => 6000,
                'shipping_total' => 1000,
                'tax_total'      => 500,
                'shipping_tax'   => 0,
                'tax_behavior'   => 1,
            ],
            [['post_title' => 'Boxed', 'title' => 'Item', 'quantity' => 1, 'subtotal' => 4500, 'line_total' => 4500]]
        );

        $lineItems = $this->build($order, 'USD', 6000);

        $this->assertNotNull($lineItems);
        $this->assertCount(3, $lineItems, 'Item, shipping and tax must each be their own line');
        $this->assertSame(1000, $lineItems[1]['price_data']['unit_amount'], 'Shipping must be charged at order.shipping_total');
        $this->assertSame(500, $lineItems[2]['price_data']['unit_amount'], 'Exclusive tax must be charged at order.tax_total');
        $this->assertSame(6000, $this->sumOf($lineItems));
    }

    /**
     * Inclusive tax is already inside line_total. Adding a tax line would charge
     * it a second time.
     */
    public function test_inclusive_tax_adds_no_tax_line(): void
    {
        $order = $this->makeOrder(
            ['total_amount' => 5000, 'tax_total' => 500, 'tax_behavior' => 2],
            [['post_title' => 'Inclusive', 'title' => 'Item', 'quantity' => 1, 'subtotal' => 5000, 'line_total' => 5000]]
        );

        $lineItems = $this->build($order, 'USD', 5000);

        $this->assertNotNull($lineItems);
        $this->assertCount(1, $lineItems, 'Inclusive tax must not produce an additive tax line');
        $this->assertSame(5000, $this->sumOf($lineItems), 'Inclusive tax must not inflate the charge');
    }

    /**
     * line_total is already subtotal minus discount_total, so a coupon needs no
     * line of its own — and must not push the breakdown above the charge.
     */
    public function test_item_discount_is_already_inside_line_total(): void
    {
        $order = $this->makeOrder(
            ['total_amount' => 4000, 'coupon_discount_total' => 1000],
            [['post_title' => 'Sale', 'title' => 'Item', 'quantity' => 1, 'subtotal' => 5000, 'discount_total' => 1000, 'line_total' => 4000]]
        );

        $lineItems = $this->build($order, 'USD', 4000);

        $this->assertNotNull($lineItems);
        $this->assertSame(4000, $this->sumOf($lineItems), 'The discounted line total is what the buyer must be charged');
    }

    /**
     * Zero-decimal currencies are stored x100 like every other currency but go on
     * the wire as whole units. Each part must be converted individually and the
     * SUM reconciled — converting after summing would disagree with Stripe, which
     * only ever sees the per-item figures.
     */
    public function test_zero_decimal_currency_reconciles_in_wire_units(): void
    {
        $order = $this->makeOrder(
            ['currency' => 'JPY', 'total_amount' => 500000, 'shipping_total' => 50000, 'tax_behavior' => 0],
            [
                ['post_title' => 'JP A', 'title' => 'v', 'quantity' => 1, 'subtotal' => 250050, 'line_total' => 250050],
                ['post_title' => 'JP B', 'title' => 'v', 'quantity' => 1, 'subtotal' => 199950, 'line_total' => 199950],
            ]
        );

        $chargeAmount = intdiv(500000, 100); // what handleHostedCheckout sends

        $lineItems = $this->build($order, 'JPY', $chargeAmount);

        $this->assertNotNull($lineItems);
        $this->assertSame(2500, $lineItems[0]['price_data']['unit_amount'], 'Zero-decimal amounts go on the wire as whole units');
        $this->assertSame($chargeAmount, $this->sumOf($lineItems), 'The wire-unit breakdown must sum to the wire-unit charge');
    }

    /**
     * Nothing subtractive exists in Checkout line items, so an over-sum can only
     * be resolved by declining. Charging more than the order is the one outcome
     * that must be impossible.
     */
    public function test_items_exceeding_the_charge_decline_the_breakdown(): void
    {
        $order = $this->makeOrder(
            ['total_amount' => 1000],
            [['post_title' => 'Too big', 'title' => 'Item', 'quantity' => 1, 'subtotal' => 9999, 'line_total' => 9999]]
        );

        $this->assertNull(
            $this->build($order, 'USD', 1000),
            'A breakdown that would overcharge must be declined, never trimmed'
        );
    }

    public function test_order_with_no_chargeable_items_declines_the_breakdown(): void
    {
        $order = $this->makeOrder(
            ['total_amount' => 1000],
            [['post_title' => 'Free', 'title' => 'Item', 'quantity' => 1, 'subtotal' => 0, 'line_total' => 0]]
        );

        $this->assertNull(
            $this->build($order, 'USD', 1000),
            'Stripe rejects an empty line_items array, so this must fall back'
        );
    }

    public function test_item_without_a_name_declines_the_breakdown(): void
    {
        $order = $this->makeOrder(
            ['total_amount' => 1000],
            [['post_title' => '', 'title' => '', 'quantity' => 1, 'subtotal' => 1000, 'line_total' => 1000]]
        );

        $this->assertNull(
            $this->build($order, 'USD', 1000),
            'Stripe requires a non-empty product name'
        );
    }

    public function test_breakdown_is_declined_past_the_line_item_cap(): void
    {
        $items = [];
        for ($i = 0; $i < Processor::MAX_HOSTED_LINE_ITEMS + 5; $i++) {
            $items[] = ['post_title' => 'Item ' . $i, 'title' => 'v', 'quantity' => 1, 'subtotal' => 100, 'line_total' => 100];
        }

        $order = $this->makeOrder(['total_amount' => 100 * count($items)], $items);

        $this->assertNull(
            $this->build($order, 'USD', 100 * count($items)),
            'Stripe caps a session at 100 line items; past the cap the aggregate line must be used'
        );
    }

    /**
     * The fallback is the safety net for every decline above, so it must itself
     * be exactly the charge amount.
     */
    public function test_aggregate_fallback_is_exactly_the_charge_amount(): void
    {
        $order = $this->makeOrder(
            ['total_amount' => 1234],
            [['post_title' => 'Whatever', 'title' => 'Item', 'quantity' => 1, 'subtotal' => 1234, 'line_total' => 1234]]
        );

        $lineItems = $this->aggregate($order, 'USD', 1234);

        $this->assertCount(1, $lineItems);
        $this->assertSame(1234, $this->sumOf($lineItems), 'The aggregate fallback must charge exactly the transaction total');
        $this->assertNotSame('', $lineItems[0]['price_data']['product_data']['name']);
    }

    /**
     * The idempotency fingerprint hashes line_items, so a varying sequence would
     * roll the key on a genuine duplicate submission and reopen the double-charge
     * window the key exists to close.
     */
    public function test_line_item_order_is_stable_across_builds(): void
    {
        $order = $this->makeOrder(
            ['total_amount' => 6000],
            [
                ['post_title' => 'Zeta', 'title' => 'v', 'quantity' => 1, 'subtotal' => 1000, 'line_total' => 1000],
                ['post_title' => 'Alpha', 'title' => 'v', 'quantity' => 1, 'subtotal' => 2000, 'line_total' => 2000],
                ['post_title' => 'Mid', 'title' => 'v', 'quantity' => 1, 'subtotal' => 3000, 'line_total' => 3000],
            ]
        );

        $first = $this->build($order, 'USD', 6000);
        $second = $this->build(Order::query()->find($order->id), 'USD', 6000);

        $this->assertSame(
            wp_json_encode($first),
            wp_json_encode($second),
            'Two builds of one cart must produce an identical body or the idempotency key rolls'
        );
    }
}
