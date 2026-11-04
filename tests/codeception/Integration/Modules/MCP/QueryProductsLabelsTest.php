<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * query-products: inline human-readable labels.
 *
 * Every analytics row must be self-describing — product_name always, and
 * variation_label when grouped by variation — so an agent never has to make a
 * second call to map ids to names. These tests assert the labels are present
 * and correct across the product / variation / product+variation groupings,
 * and that the pre-existing product_title key is preserved (backward compat).
 */
class QueryProductsLabelsTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    private const WIN_START = '2025-04-01';
    private const WIN_END   = '2025-04-30';
    private const IN_WINDOW = '2025-04-10 12:00:00';

    private const PROD   = 9301;
    private const VAR_RED  = 93011;
    private const VAR_BLUE = 93012;

    private const PRODUCT_NAME = 'Acme Widget';
    private const LABEL_RED    = 'Red / Large';
    private const LABEL_BLUE   = 'Blue / Small';

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
    }

    private function seed(): void
    {
        $cid = $this->customerFactory->create()->id;
        foreach ([[self::VAR_RED, self::LABEL_RED], [self::VAR_BLUE, self::LABEL_BLUE]] as [$objectId, $label]) {
            $this->orderFactory
                ->withItems([[
                    'post_id'      => self::PROD,
                    'object_id'    => $objectId,
                    'post_title'   => self::PRODUCT_NAME,
                    'title'        => $label,
                    'quantity'     => 1,
                    'line_total'   => Helper::toCent(50),
                    'refund_total' => 0,
                    'payment_type' => 'onetime',
                ]])
                ->create([
                    'customer_id'    => $cid,
                    'type'           => Status::ORDER_TYPE_PAYMENT,
                    'payment_status' => Status::PAYMENT_PAID,
                    'currency'       => 'USD',
                    'total_amount'   => Helper::toCent(50),
                    'total_paid'     => Helper::toCent(50),
                    'created_at'     => self::IN_WINDOW,
                ]);
        }
    }

    private function baseParams(array $extra): array
    {
        return array_merge([
            'currency'   => 'USD',
            'start_date' => self::WIN_START,
            'end_date'   => self::WIN_END,
            'product_id' => self::PROD,
        ], $extra);
    }

    public function test_product_grouping_has_product_name_and_keeps_product_title(): void
    {
        $this->seed();
        $rows = ReportTools::queryProducts($this->baseParams([
            'dimensions' => ['product'],
            'metrics'    => ['units_sold'],
        ]))['data']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame(self::PRODUCT_NAME, $rows[0]['product_name']);
        // Backward-compatible alias must still be present.
        $this->assertSame(self::PRODUCT_NAME, $rows[0]['product_title']);
    }

    public function test_variation_grouping_is_self_describing(): void
    {
        $this->seed();
        $rows = ReportTools::queryProducts($this->baseParams([
            'dimensions' => ['variation'],
            'metrics'    => ['units_sold'],
        ]))['data']['rows'];

        $byVar = [];
        foreach ($rows as $row) {
            // Even without the product dimension, the parent product is named.
            $this->assertSame(self::PROD, (int) $row['product_id']);
            $this->assertSame(self::PRODUCT_NAME, $row['product_name']);
            $this->assertArrayHasKey('variation_label', $row);
            $byVar[(int) $row['variation_id']] = $row['variation_label'];
        }
        $this->assertSame(self::LABEL_RED, $byVar[self::VAR_RED]);
        $this->assertSame(self::LABEL_BLUE, $byVar[self::VAR_BLUE]);
    }

    public function test_product_and_variation_grouping_has_both_labels(): void
    {
        $this->seed();
        $rows = ReportTools::queryProducts($this->baseParams([
            'dimensions' => ['product', 'variation'],
            'metrics'    => ['units_sold'],
        ]))['data']['rows'];

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(self::PRODUCT_NAME, $row['product_name']);
            $this->assertSame(self::PRODUCT_NAME, $row['product_title']);
            $this->assertContains($row['variation_label'], [self::LABEL_RED, self::LABEL_BLUE]);
        }
    }
}
