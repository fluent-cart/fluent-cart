<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * query-products: discount / margin-leakage metrics.
 *
 * line_total = subtotal - discount_total on every item, and checkout mirrors the
 * item discounts into the order's coupon total, so the tool can expose
 * list_price_sum (SUM subtotal), discount_amount (SUM discount_total plus any
 * order-level credit share) and net_revenue directly, and discount_amount must
 * equal the gap between list_price_sum and net_revenue when nothing is refunded.
 * These tests seed a 50%-off line and assert that reconciliation, plus that
 * discount_amount is independent of refunds.
 */
class QueryProductsDiscountTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    private const WIN_START = '2025-04-01';
    private const WIN_END   = '2025-04-30';
    private const IN_WINDOW = '2025-04-10 12:00:00';

    private const PROD = 9401;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
    }

    /** One line item with an explicit list price (subtotal), discount and refund. */
    private function orderLine(int $cid, $listDollars, $discountDollars, $refundDollars, int $qty): void
    {
        $list     = Helper::toCent($listDollars);
        $discount = Helper::toCent($discountDollars);
        $line     = $list - $discount;
        $this->orderFactory
            ->withItems([[
                'post_id'        => self::PROD,
                'object_id'      => self::PROD * 10,
                'post_title'     => 'Discounted Product',
                'title'          => 'Discounted Product',
                'quantity'       => $qty,
                'subtotal'       => $list,
                'discount_total' => $discount,
                'line_total'     => $line,
                'refund_total'   => Helper::toCent($refundDollars),
                'payment_type'   => 'onetime',
            ]])
            ->create([
                'customer_id'    => $cid,
                'type'           => Status::ORDER_TYPE_PAYMENT,
                'payment_status' => $refundDollars > 0 ? Status::PAYMENT_PARTIALLY_REFUNDED : Status::PAYMENT_PAID,
                'currency'       => 'USD',
                'coupon_discount_total' => $discount,
                'total_amount'   => $line,
                'total_paid'     => $line,
                // The order records its refund too, as the store writes it
                'total_refund'   => Helper::toCent($refundDollars),
                'created_at'     => self::IN_WINDOW,
            ]);
    }

    private function toCents($decimalString): int
    {
        return (int) round(((float) $decimalString) * 100);
    }

    private function metrics(): array
    {
        $res = ReportTools::queryProducts([
            'currency'   => 'USD',
            'start_date' => self::WIN_START,
            'end_date'   => self::WIN_END,
            'product_id' => self::PROD,
            'dimensions' => ['product'],
            'metrics'    => ['units_sold', 'list_price_sum', 'discount_amount', 'line_revenue', 'net_revenue'],
        ]);
        $row = $res['data']['rows'][0];
        return [
            'list_price_sum'  => $this->toCents($row['list_price_sum']),
            'discount_amount' => $this->toCents($row['discount_amount']),
            'line_revenue'    => $this->toCents($row['line_revenue']),
            'net_revenue'     => $this->toCents($row['net_revenue']),
        ];
    }

    public function test_fifty_percent_off_reconciles(): void
    {
        $cid = $this->customerFactory->create()->id;
        // $200 list, 50% off -> $100 charged, no refund.
        $this->orderLine($cid, 200, 100, 0, 1);
        // A full-price $40 line so the sums aren't trivially one row.
        $this->orderLine($cid, 40, 0, 0, 1);

        $m = $this->metrics();

        $this->assertSame(Helper::toCent(240), $m['list_price_sum'], 'list_price_sum = sum of list prices');
        $this->assertSame(Helper::toCent(100), $m['discount_amount'], 'discount_amount = the 50%-off amount');
        $this->assertSame(Helper::toCent(140), $m['net_revenue'], 'net_revenue = charged (no refunds)');
        // The spec's headline: discount_amount is the gap between list and net.
        $this->assertSame(
            $m['discount_amount'],
            $m['list_price_sum'] - $m['net_revenue'],
            'discount_amount == list_price_sum - net_revenue when nothing is refunded'
        );
    }

    public function test_discount_amount_is_independent_of_refunds(): void
    {
        $cid = $this->customerFactory->create()->id;
        // $200 list, 50% off ($100 charged), then $30 refunded.
        $this->orderLine($cid, 200, 100, 30, 1);

        $m = $this->metrics();

        $this->assertSame(Helper::toCent(100), $m['discount_amount'], 'discount tracks discount_total, not refunds');
        $this->assertSame(Helper::toCent(200), $m['list_price_sum']);
        // net_revenue drops by the refund; discount_amount does not.
        $this->assertSame(Helper::toCent(70), $m['net_revenue'], 'net_revenue = line_revenue - refund');
        $this->assertSame(Helper::toCent(100), $m['line_revenue'], 'line_revenue excludes refunds');
    }
}
