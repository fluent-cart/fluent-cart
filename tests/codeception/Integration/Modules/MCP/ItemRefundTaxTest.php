<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ProductFinancialsTools;
use FluentCart\App\Modules\MCP\Tools\ProductTools;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\ProductReportService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * An item's refund_total is the charged amount, tax included. MCP product revenue must match
 * the admin product report: line net of its refund share and of any tax kept inside the line.
 */
class ItemRefundTaxTest extends DatabaseTestCase
{
    private const WIN_START = '2026-07-01';
    private const WIN_END   = '2026-07-07';
    private const IN_WINDOW = '2026-07-05 12:00:00';

    private int $customerId;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerId = (new CustomerFactory())->create()->id;
    }

    private function product(): int
    {
        return (int) (new ProductFactory())->create(['post_title' => 'Tax Item ' . wp_generate_password(6, false)])->ID;
    }

    private function item(int $productId, array $overrides): array
    {
        return array_merge([
            'post_id'      => $productId,
            'object_id'    => $productId * 10,
            'post_title'   => 'Product ' . $productId,
            'title'        => 'Variation ' . $productId,
            'quantity'     => 1,
            'subtotal'     => 1000,
            'discount_total' => 0,
            'tax_amount'   => 210,
            'line_total'   => 1000,
            'refund_total' => 0,
            'payment_type' => 'onetime',
        ], $overrides);
    }

    private function order(array $items, array $overrides): void
    {
        // Payment totals as checkout writes them; the product report shares what the order paid over its lines
        $paid = 0;
        $refund = 0;
        $tax = 0;
        foreach ($items as $item) {
            $line = $item['line_total'] ?? 1000;
            $itemTax = $item['tax_amount'] ?? 210;
            // A line priced with its tax already holds it
            $paid += $line >= ($item['subtotal'] ?? 1000) + $itemTax ? $line : $line + $itemTax;
            $refund += $item['refund_total'] ?? 0;
            $tax += $itemTax;
        }
        // An order-level credit (upgrade proration) is not charged
        $paid -= $overrides['manual_discount_total'] ?? 0;
        if (($overrides['payment_status'] ?? null) === Status::PAYMENT_REFUNDED) {
            $refund = max($refund, $paid);
        }
        $overrides += ['total_amount' => $paid, 'total_paid' => $paid, 'total_refund' => $refund, 'tax_total' => $tax];
        (new OrderFactory())->withItems($items)->create(array_merge([
            'customer_id'    => $this->customerId,
            'type'           => Status::ORDER_TYPE_PAYMENT,
            'payment_status' => Status::PAYMENT_PAID,
            'status'         => Status::ORDER_COMPLETED,
            'tax_behavior'   => 1,
            'currency'       => 'USD',
            'created_at'     => self::IN_WINDOW,
            'completed_at'   => self::IN_WINDOW,
        ], $overrides));
    }

    private function partial(): array
    {
        return ['payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED, 'status' => Status::ORDER_PROCESSING];
    }

    private function refunded(): array
    {
        return ['payment_status' => Status::PAYMENT_REFUNDED, 'status' => Status::ORDER_CANCELED];
    }

    private function cents($decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    private function queryProduct(int $productId, array $metrics, array $dimensions = ['product']): array
    {
        $res = ReportTools::queryProducts([
            'currency'   => 'USD',
            'start_date' => self::WIN_START,
            'end_date'   => self::WIN_END,
            'product_id' => $productId,
            'metrics'    => $metrics,
            'dimensions' => $dimensions,
        ]);
        $this->assertArrayHasKey('data', $res, 'query-products returned an error: ' . wp_json_encode($res));
        $this->assertCount(1, $res['data']['rows']);

        return $res['data']['rows'][0];
    }

    private function assertNetAndRefund(int $productId, int $net, int $refund): void
    {
        $row = $this->queryProduct($productId, ['net_revenue', 'refund_amount']);
        // MCP gives the admin Products page's figures; the literals below pin what that page counts.
        // That page shares each order's payment, refund (tax included) and kept tax over its lines by line amount.
        $admin = $this->adminProduct($productId);
        $this->assertSame($admin['net'], $this->cents($row['net_revenue']), 'net_revenue = Products page net');
        $this->assertSame($admin['refunds'], $this->cents($row['refund_amount']), 'refund_amount = Products page refunds');
        $this->assertSame($net, $this->cents($row['net_revenue']), 'net_revenue');
        $this->assertSame($refund, $this->cents($row['refund_amount']), 'refund_amount');
    }

    private function adminProduct(int $productId): array
    {
        $rows = ProductReportService::make()->getProductSales([
            'startDate'     => new DateTime(self::WIN_START . ' 00:00:00', new \DateTimeZone('UTC')),
            'endDate'       => new DateTime(self::WIN_END . ' 23:59:59', new \DateTimeZone('UTC')),
            'currency'      => 'USD',
            'paymentStatus' => Status::getReportStatuses(),
            'groupKey'      => 'monthly',
        ])['products'];
        foreach ($rows as $row) {
            if ((int) $row['product_id'] === $productId) {
                return ['net' => (int) $row['net'], 'refunds' => (int) $row['refunds']];
            }
        }

        return ['net' => 0, 'refunds' => 0];
    }

    public function test_tax_on_top_half_refund_takes_only_the_line_share(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, ['refund_total' => 605])], $this->partial());

        $this->assertNetAndRefund($p, 500, 605);
    }

    public function test_tax_on_top_full_refund_is_zero_not_negative(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, ['refund_total' => 1210])], $this->refunded());

        $this->assertNetAndRefund($p, 0, 1210);
    }

    public function test_full_line_refund_on_an_order_that_keeps_other_items(): void
    {
        $p = $this->product();
        $kept = $this->product();
        $this->order([
            $this->item($p, ['refund_total' => 1210]),
            $this->item($kept, []),
        ], $this->partial());

        $this->assertNetAndRefund($p, 500, 605);
        $this->assertNetAndRefund($kept, 500, 605);
    }

    public function test_inclusive_line_excludes_its_tax(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, ['line_total' => 1210])], ['tax_behavior' => 2]);

        $this->assertNetAndRefund($p, 1000, 0);
    }

    public function test_inclusive_half_refund_splits_refund_and_kept_tax(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, ['line_total' => 1210, 'refund_total' => 605])], array_merge($this->partial(), ['tax_behavior' => 2]));

        $this->assertNetAndRefund($p, 500, 605);
    }

    public function test_refunded_order_counts_the_whole_line_as_refunded(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, [])], $this->refunded());

        $this->assertNetAndRefund($p, 0, 1210);
    }

    public function test_line_inclusive_flag_wins_over_order_tax_behavior(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, [
            'line_total' => 1210,
            'line_meta'  => ['tax_config' => ['inclusive' => true]],
        ])], ['tax_behavior' => 1]);

        $this->assertNetAndRefund($p, 1000, 0);
    }

    public function test_excluded_tax_inclusion_wins_over_inclusive_order(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, [
            'refund_total' => 605,
            'other_info'   => ['tax_inclusion' => 'excluded'],
        ])], array_merge($this->partial(), ['tax_behavior' => 2]));

        $this->assertNetAndRefund($p, 500, 605);
    }

    public function test_renewal_line_with_tax_in_line_total_counts_as_inclusive(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, [
            'line_total'   => 1210,
            'payment_type' => 'subscription',
        ])], ['type' => Status::ORDER_TYPE_RENEWAL, 'tax_behavior' => 1]);

        $this->assertNetAndRefund($p, 1000, 0);

        $row = $this->queryProduct($p, ['net_revenue'], ['product', 'order_type']);
        $this->assertSame(1000, $this->cents($row['net_revenue']));
    }

    public function test_single_refund_metric_selects_cleanly(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, ['refund_total' => 605])], $this->partial());

        $this->assertSame(605, $this->cents($this->queryProduct($p, ['refund_amount'])['refund_amount']));
        $this->assertSame(500, $this->cents($this->queryProduct($p, ['net_revenue'])['net_revenue']));
    }

    public function test_top_products_ranks_by_net_revenue(): void
    {
        $onTop = $this->product();
        $inclusive = $this->product();
        $this->order([$this->item($onTop, ['refund_total' => 605])], $this->partial());
        $this->order([$this->item($inclusive, ['line_total' => 1210])], ['tax_behavior' => 2]);

        $res = ReportTools::getTopProducts(['currency' => 'USD', 'start_date' => self::WIN_START, 'end_date' => self::WIN_END]);
        $byId = [];
        foreach ($res['data']['products'] as $row) {
            $byId[$row['product_id']] = $this->cents($row['revenue']);
        }

        $this->assertSame([$inclusive => 1000, $onTop => 500], $byId);
    }

    public function test_get_product_sales_matches_net_revenue(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, ['refund_total' => 605])], $this->partial());

        $res = ProductTools::getProduct(['product_id' => $p, 'include' => ['sales']]);

        $this->assertSame(500, $res['data']['sales']['revenue']['amount_cents']);
    }

    private function upgradeOrder(int $productId, array $item = [], array $order = []): void
    {
        $this->order([$this->item($productId, array_merge(['tax_amount' => 0], $item))], array_merge([
            'manual_discount_total' => 300,
            'config'                => ['prorate_credit' => 300],
        ], $order));
    }

    public function test_upgrade_credit_on_the_order_comes_off_product_revenue(): void
    {
        $p = $this->product();
        $this->upgradeOrder($p);

        $row = $this->queryProduct($p, ['line_revenue', 'net_revenue', 'list_price_sum', 'discount_amount', 'avg_unit_price']);
        $this->assertSame(700, $this->cents($row['line_revenue']), 'line_revenue');
        $this->assertSame(700, $this->cents($row['net_revenue']), 'net_revenue');
        $this->assertSame(1000, $this->cents($row['list_price_sum']), 'list_price_sum');
        $this->assertSame(300, $this->cents($row['discount_amount']), 'discount_amount');
        $this->assertSame(700, $this->cents($row['avg_unit_price']), 'avg_unit_price');

        $res = ReportTools::getTopProducts(['currency' => 'USD', 'start_date' => self::WIN_START, 'end_date' => self::WIN_END]);
        $byId = array_column($res['data']['products'], 'revenue', 'product_id');
        $this->assertSame(700, $this->cents($byId[$p]));

        $sales = ProductTools::getProduct(['product_id' => $p, 'include' => ['sales']]);
        $this->assertSame(700, $sales['data']['sales']['revenue']['amount_cents']);
    }

    public function test_upgrade_credit_is_split_across_the_order_lines(): void
    {
        $a = $this->product();
        $b = $this->product();
        $this->order([
            $this->item($a, ['tax_amount' => 0, 'subtotal' => 3000, 'line_total' => 3000]),
            $this->item($b, ['tax_amount' => 0]),
        ], ['manual_discount_total' => 400, 'config' => ['prorate_credit' => 400]]);

        $this->assertNetAndRefund($a, 2700, 0);
        $this->assertNetAndRefund($b, 900, 0);
    }

    public function test_fully_refunded_upgrade_order_nets_to_zero(): void
    {
        $p = $this->product();
        $this->upgradeOrder($p, ['refund_total' => 700], $this->refunded());

        $this->assertNetAndRefund($p, 0, 700);
    }

    public function test_financials_one_time_is_cash_basis_in_either_tax_mode(): void
    {
        $onTop = $this->product();
        $inclusive = $this->product();
        $this->order([$this->item($onTop, ['refund_total' => 605])], $this->partial());
        $this->order([$this->item($inclusive, ['line_total' => 1210, 'refund_total' => 605])], array_merge($this->partial(), ['tax_behavior' => 2]));

        foreach ([$onTop, $inclusive] as $p) {
            $res = ProductFinancialsTools::getProductFinancials(['product_id' => $p, 'currency' => 'USD']);
            $oneTime = $res['data']['one_time'];

            $this->assertSame(1210, $oneTime['gross_collected']['amount_cents'], 'gross_collected');
            $this->assertSame(605, $oneTime['refunds']['amount_cents'], 'refunds');
            $this->assertSame(605, $oneTime['net_collected']['amount_cents'], 'net_collected');
            $this->assertSame(1210, $oneTime['aov']['amount_cents'], 'aov');
        }
    }

    private function oneTime(int $productId): array
    {
        $res = ProductFinancialsTools::getProductFinancials(['product_id' => $productId, 'currency' => 'USD']);
        $this->assertArrayHasKey('data', $res, 'product financials returned an error: ' . wp_json_encode($res));

        return $res['data']['one_time'];
    }

    private function assertCollected(int $productId, int $gross, int $refunds, int $net): void
    {
        $oneTime = $this->oneTime($productId);
        $this->assertSame($gross, $oneTime['gross_collected']['amount_cents'], 'gross_collected');
        $this->assertSame($refunds, $oneTime['refunds']['amount_cents'], 'refunds');
        $this->assertSame($net, $oneTime['net_collected']['amount_cents'], 'net_collected');
    }

    public function test_financials_counts_a_fully_refunded_one_time_order(): void
    {
        $p = $this->product();
        $this->order([$this->item($p, ['refund_total' => 1210])], $this->refunded());

        $this->assertCollected($p, 1210, 1210, 0);
        $oneTime = $this->oneTime($p);
        $this->assertSame(1, $oneTime['units'], 'units');
        $this->assertSame(1, $oneTime['orders'], 'orders');
    }

    public function test_financials_one_time_upgrade_collects_what_was_paid_after_the_credit(): void
    {
        $p = $this->product();
        $this->upgradeOrder($p, ['tax_amount' => 210, 'refund_total' => 455], $this->partial());

        $this->assertCollected($p, 910, 455, 455);
    }

    public function test_financials_fully_refunded_upgrade_order_nets_to_zero(): void
    {
        $p = $this->product();
        $this->upgradeOrder($p, ['refund_total' => 700], $this->refunded());

        $this->assertCollected($p, 700, 700, 0);
    }

    public function test_financials_fully_refunded_credited_order_with_mixed_tax_nets_to_zero(): void
    {
        $taxed = $this->product();
        $untaxed = $this->product();
        // 12.00 paid after a 10.00 order-level credit; the refund spread it by line + tax, 6.55 / 5.45
        $this->order([
            $this->item($taxed, ['tax_amount' => 200, 'refund_total' => 655]),
            $this->item($untaxed, ['tax_amount' => 0, 'refund_total' => 545]),
        ], array_merge($this->refunded(), ['manual_discount_total' => 1000]));

        $this->assertCollected($taxed, 700, 700, 0);
        $this->assertCollected($untaxed, 500, 500, 0);
    }

    public function test_partial_refund_on_a_credited_order_is_shared_over_what_was_paid(): void
    {
        $p = $this->product();
        // 7.00 after the 3.00 credit + 2.10 tax = 9.10 paid; half of it came back
        $this->upgradeOrder($p, ['tax_amount' => 210, 'refund_total' => 455], $this->partial());

        $this->assertNetAndRefund($p, 350, 455);
    }

    public function test_refund_above_a_credited_line_charge_moves_the_excess_to_the_other_lines(): void
    {
        $refunded = $this->product();
        $kept = $this->product();
        // Each line paid 6.00 after an 8.00 order credit; the admin refunded 10.00 on the first line
        $this->order([
            $this->item($refunded, ['tax_amount' => 0, 'refund_total' => 1000]),
            $this->item($kept, ['tax_amount' => 0]),
        ], array_merge($this->partial(), ['manual_discount_total' => 800]));

        $this->assertNetAndRefund($refunded, 100, 500);
        $this->assertNetAndRefund($kept, 100, 500);
        $this->assertCollected($refunded, 600, 600, 0);
        $this->assertCollected($kept, 600, 400, 200);
    }

    public function test_excess_left_after_flooring_goes_to_the_first_line_with_room(): void
    {
        $refunded = $this->product();
        $first = $this->product();
        $second = $this->product();
        // Each line paid 5.00 after a 15.00 order credit; 7.01 refunded on the first line leaves 2.01 to move
        $this->order([
            $this->item($refunded, ['tax_amount' => 0, 'refund_total' => 701]),
            $this->item($first, ['tax_amount' => 0]),
            $this->item($second, ['tax_amount' => 0]),
        ], array_merge($this->partial(), ['manual_discount_total' => 1500]));

        $this->assertNetAndRefund($refunded, 265, 235);
        $this->assertNetAndRefund($first, 267, 233);
        $this->assertNetAndRefund($second, 267, 233);
    }
}
