<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

/**
 * Priority-1 regression: fully-refunded orders must not vanish from refund metrics.
 *
 * The bug: refund aggregation gated on payment_status IN (paid, partially_paid,
 * partially_refunded) silently dropped every order that was FULLY refunded and
 * flipped to payment_status 'refunded' / status 'canceled'. Refund totals, refund
 * count, refund rate, and per-variation refund_amount all undercounted.
 *
 * Modeled on production order 7637892 (WPMN-252133): variation sold, then fully
 * refunded, ending as status canceled / payment_status refunded, total_refund =
 * full amount. Seeded here as order C alongside a still-paid partial refund (B) and
 * a clean paid order (A) so every metric can be reconciled exactly.
 *
 * The fix includes 'refunded' in the paid-ever set. get-refund-report now reads the
 * admin Refunds report, which counts refund transactions, so the fixture writes them.
 */
class RefundMetricsTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    private const WIN_START = '2026-07-01';
    private const WIN_END   = '2026-07-07';
    private const IN_WINDOW = '2026-07-05 12:00:00';

    // Distinct products/variations so the fully-refunded order's contribution is
    // isolatable — mirrors the doc's "variation 123 refund_amount" acceptance.
    private const PROD_PAID = 7701;   // clean paid order
    private const VAR_PAID  = 77011;
    private const PROD_PART = 7701;   // partial refund shares the product
    private const VAR_PART  = 77012;
    private const PROD_FULL = 7702;   // fully-refunded order — its own product/variation
    private const VAR_FULL  = 77023;  // the "variation 123" analog

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
    }

    /**
     * Seed one order with a single line item and explicit paid/refund totals.
     */
    private function order(int $cid, int $postId, int $objectId, int $paidCents, int $refundCents, string $paymentStatus, string $orderStatus): void
    {
        $order = $this->orderFactory
            ->withItems([[
                'post_id'      => $postId,
                'object_id'    => $objectId,
                'post_title'   => 'Product ' . $postId,
                'title'        => 'Variation ' . $objectId,
                'quantity'     => 1,
                'subtotal'     => $paidCents,
                'line_total'   => $paidCents,
                'refund_total' => $refundCents,
                'payment_type' => 'onetime',
            ]])
            ->create([
                'customer_id'    => $cid,
                'type'           => Status::ORDER_TYPE_PAYMENT,
                'payment_status' => $paymentStatus,
                'status'         => $orderStatus,
                'currency'       => 'USD',
                'total_amount'   => $paidCents,
                'total_paid'     => $paidCents,
                'total_refund'   => $refundCents,
                'created_at'     => self::IN_WINDOW,
            ]);

        // A real refund writes its transaction; the refund report counts those
        if ($refundCents > 0) {
            (new OrderTransactionFactory())->create($order->id, [
                'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
                'status'           => Status::TRANSACTION_REFUNDED,
                'total'            => $refundCents,
                'created_at'       => self::IN_WINDOW,
            ]);
        }
    }

    private function seed(): void
    {
        $cid = $this->customerFactory->create()->id;

        // A: clean paid order, $100, no refund.
        $this->order($cid, self::PROD_PAID, self::VAR_PAID, Helper::toCent(100), 0, Status::PAYMENT_PAID, Status::ORDER_COMPLETED);

        // B: partial refund on a still-paid order — $200 paid, $50 refunded.
        // This is the kind of refund that WAS reported before the fix.
        $this->order($cid, self::PROD_PART, self::VAR_PART, Helper::toCent(200), Helper::toCent(50), Status::PAYMENT_PARTIALLY_REFUNDED, Status::ORDER_PROCESSING);

        // C: the one that used to vanish — fully refunded, canceled. $800 paid, $800
        // refunded, payment_status 'refunded', status 'canceled'.
        $this->order($cid, self::PROD_FULL, self::VAR_FULL, Helper::toCent(800), Helper::toCent(800), Status::PAYMENT_REFUNDED, Status::ORDER_CANCELED);
    }

    private function baseParams(array $extra): array
    {
        return array_merge([
            'currency'   => 'USD',
            'start_date' => self::WIN_START,
            'end_date'   => self::WIN_END,
        ], $extra);
    }

    private function toCents($decimalString): int
    {
        return (int) round(((float) $decimalString) * 100);
    }

    public function test_refund_report_counts_the_fully_refunded_order(): void
    {
        $this->seed();

        $res  = ReportTools::getRefundReport($this->baseParams([]));
        $data = $res['data'];

        // Both B (partial) and C (full) are refunded orders. Pre-fix only B counted.
        $this->assertSame(2, $data['refunded_order_count'], 'Fully-refunded order C is counted alongside the partial refund B.');

        // Refund total = $50 (B) + $800 (C) = $850. Pre-fix it was $50.
        $this->assertSame(Helper::toCent(850), $data['total_refunded']['amount_cents'], 'total_refunded includes the fully-refunded $800.');

        // Denominator counts every order that captured payment, incl. the fully
        // refunded one (documented decision): A + B + C = 3.
        $this->assertSame(3, $data['paid_order_count'], 'Fully-refunded order is in the paid denominator too.');

        // rate = 2 / 3 * 100 = 66.67 (both numerator and denominator include C).
        $this->assertEqualsWithDelta(66.67, $data['refund_rate_percent'], 0.01);
    }

    public function test_sales_report_refunded_includes_full_refund_and_net_is_unchanged(): void
    {
        $this->seed();

        $res     = ReportTools::getSalesReport($this->baseParams(['compare' => false]));
        $metrics = $res['data']['metrics'];

        // refunded now covers the $800 full refund: $50 + $800 = $850.
        $this->assertSame(Helper::toCent(850), $metrics['refunded']['amount_cents'], 'sales-report refunded includes the fully-refunded order.');

        // net_revenue is UNCHANGED by including the fully-refunded order: it nets to
        // zero (paid 800 - refund 800). A(100) + B(200-50) + C(0) = 250.
        $this->assertSame(Helper::toCent(250), $metrics['net_revenue']['amount_cents'], 'net_revenue is unaffected — the fully-refunded order nets to zero.');

        // Sanity: the displayed identity holds — paid - refunded == net.
        $this->assertSame(
            $metrics['net_revenue']['amount_cents'],
            $metrics['paid']['amount_cents'] - $metrics['refunded']['amount_cents'],
            'paid - refunded == net on the reported figures.'
        );
    }

    public function test_query_products_refund_amount_shows_the_fully_refunded_variation(): void
    {
        $this->seed();

        $res  = ReportTools::queryProducts($this->baseParams([
            'dimensions' => ['variation'],
            'metrics'    => ['refund_amount'],
        ]));

        $byVariation = [];
        foreach ($res['data']['rows'] as $row) {
            $byVariation[(int) $row['variation_id']] = $this->toCents($row['refund_amount']);
        }

        // The fully-refunded variation appears with its $800 refund. Pre-fix its
        // parent order was excluded, so the row was absent / zero.
        $this->assertArrayHasKey(self::VAR_FULL, $byVariation, 'The fully-refunded variation is present.');
        $this->assertSame(Helper::toCent(800), $byVariation[self::VAR_FULL], 'refund_amount for the fully-refunded variation is the full $800.');

        // The partial refund is still reported too.
        $this->assertSame(Helper::toCent(50), $byVariation[self::VAR_PART]);
    }
}
