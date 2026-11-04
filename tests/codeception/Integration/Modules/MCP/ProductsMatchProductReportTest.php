<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\ProductReportService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * query-products and its top-products call give the admin Products page's net sales and units.
 */
class ProductsMatchProductReportTest extends DatabaseTestCase
{
    private array $products = [];

    public function setUp(): void
    {
        parent::setUp();
        Order::query()->delete();
        $cid = (new CustomerFactory())->create()->id;
        foreach (['a', 'b'] as $key) {
            $this->products[$key] = (int) (new ProductFactory())->create()->ID;
        }
        $line = function ($productId, array $extra = []) {
            return array_merge([
                'post_id' => $productId, 'object_id' => $productId * 10, 'post_title' => 'P' . $productId,
                'title' => 'V' . $productId, 'quantity' => 2, 'subtotal' => 2000, 'line_total' => 2000,
                'tax_amount' => 200, 'refund_total' => 0, 'payment_type' => 'onetime',
            ], $extra);
        };

        // Two products, tax on top, a partial refund and a coupon on the order
        (new OrderFactory())->withItems([
            $line($this->products['a'], ['discount_total' => 200, 'line_total' => 1800, 'tax_amount' => 180, 'refund_total' => 990]),
            $line($this->products['b']),
        ])->create([
            'customer_id' => $cid, 'type' => Status::ORDER_TYPE_PAYMENT, 'currency' => 'USD',
            'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED, 'status' => Status::ORDER_PROCESSING,
            'tax_behavior' => 1, 'coupon_discount_total' => 200, 'tax_total' => 380,
            'total_amount' => 4180, 'total_paid' => 4180, 'total_refund' => 990,
            'created_at' => '2025-06-10 10:00:00',
        ]);
        (new OrderFactory())->withItems([$line($this->products['b'], ['quantity' => 1, 'subtotal' => 1000, 'line_total' => 1000, 'tax_amount' => 100])])->create([
            'customer_id' => $cid, 'type' => Status::ORDER_TYPE_PAYMENT, 'currency' => 'USD',
            'payment_status' => Status::PAYMENT_PAID, 'status' => Status::ORDER_COMPLETED,
            'tax_behavior' => 1, 'tax_total' => 100, 'total_amount' => 1100, 'total_paid' => 1100,
            'created_at' => '2025-06-12 10:00:00',
        ]);
    }

    private function admin(): array
    {
        $rows = ProductReportService::make()->getProductSales([
            'startDate'     => new DateTime('2025-06-01 00:00:00', new \DateTimeZone('UTC')),
            'endDate'       => new DateTime('2025-06-30 23:59:59', new \DateTimeZone('UTC')),
            'currency'      => 'USD',
            'paymentStatus' => Status::getReportStatuses(),
            'groupKey'      => 'monthly',
        ])['products'];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['product_id']] = ['net' => (int) $row['net'], 'units' => (int) $row['units']];
        }
        ksort($out);

        return $out;
    }

    public function test_query_products_net_and_units_equal_the_products_page(): void
    {
        $res = ReportTools::queryProducts([
            'currency' => 'USD', 'start_date' => '2025-06-01', 'end_date' => '2025-06-30',
            'metrics' => ['net_revenue', 'units_sold'], 'dimensions' => ['product'],
        ]);
        $mcp = [];
        foreach ($res['data']['rows'] as $row) {
            $mcp[(int) $row['product_id']] = ['net' => (int) round($row['net_revenue'] * 100), 'units' => (int) $row['units_sold']];
        }
        ksort($mcp);

        $this->assertSame($this->admin(), $mcp);
    }

    public function test_top_products_revenue_equals_the_products_page_net(): void
    {
        $res = ReportTools::getTopProducts(['currency' => 'USD', 'start_date' => '2025-06-01', 'end_date' => '2025-06-30']);
        $mcp = [];
        foreach ($res['data']['products'] as $row) {
            $mcp[(int) $row['product_id']] = (int) round($row['revenue'] * 100);
        }
        ksort($mcp);

        $this->assertSame(array_column($this->admin(), 'net'), array_values($mcp));
    }
}
