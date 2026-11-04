<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;

/**
 * MCP report tools hide the FluentCart Pro report parts unless Pro answers the same filters as REST.
 */
class ReportProGatesTest extends DatabaseTestCase
{
    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/report/installment_forecast');
        remove_all_filters('fluent_cart/report/locations');
        parent::tearDown();
    }

    public function test_installment_forecast_and_variations_need_pro(): void
    {
        $free = ReportTools::getInstallmentReport([])['data'];
        $this->assertArrayNotHasKey('schedule', $free);
        $this->assertArrayNotHasKey('starts', $free);
        $this->assertArrayNotHasKey('by_variation', $free);
        $this->assertArrayNotHasKey('collection_rate', $free['summary']);

        add_filter('fluent_cart/report/installment_forecast', '__return_true');
        $pro = ReportTools::getInstallmentReport([])['data'];
        $this->assertArrayHasKey('schedule', $pro);
        $this->assertArrayHasKey('starts', $pro);
        $this->assertArrayHasKey('by_variation', $pro);
        $this->assertArrayHasKey('collection_rate', $pro['summary']);
    }

    public function test_state_grouping_needs_pro(): void
    {
        remove_all_filters('fluent_cart/report/locations');
        $orders = ReportTools::queryOrders(['dimensions' => ['state']]);
        $customers = ReportTools::queryCustomers(['dimensions' => ['state']]);
        $this->assertSame('pro_required', is_wp_error($orders) ? $orders->get_error_code() : null);
        $this->assertSame('pro_required', is_wp_error($customers) ? $customers->get_error_code() : null);
        $this->assertArrayHasKey('data', ReportTools::queryOrders(['dimensions' => ['country']]));

        add_filter('fluent_cart/report/locations', function ($report) {
            return $report;
        });
        $this->assertArrayHasKey('data', ReportTools::queryOrders(['dimensions' => ['state']]));
        $this->assertArrayHasKey('data', ReportTools::queryCustomers(['dimensions' => ['state']]));
    }
}
