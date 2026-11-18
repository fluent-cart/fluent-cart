<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\Tools\ProductFinancialsTools;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * A currency the tool cannot use is an error, not a silent empty report.
 */
class ReportCurrencyParamTest extends DatabaseTestCase
{
    public function test_unusable_currency_is_an_error(): void
    {
        $productId = (int) (new ProductFactory())->create()->ID;
        $calls = [
            function ($c) { return ReportTools::getSalesTrend(['currency' => $c]); },
            function ($c) { return ReportTools::queryOrders(['currency' => $c]); },
            function ($c) { return ReportTools::queryProducts(['currency' => $c]); },
            function ($c) { return ReportTools::getTopProducts(['currency' => $c]); },
            function ($c) use ($productId) { return ProductFinancialsTools::getProductFinancials(['product_id' => $productId, 'currency' => $c]); },
        ];
        foreach ($calls as $i => $call) {
            foreach (['__all__', 'US'] as $currency) {
                $res = $call($currency);
                $this->assertTrue(is_wp_error($res), "call {$i} with {$currency}");
                $this->assertSame('invalid_param', $res->get_error_code());
            }
            $this->assertFalse(is_wp_error($call('usd')), "call {$i} accepts a lower-case code");
        }
    }

    public function test_converting_tools_reject_a_bad_code_and_accept_all(): void
    {
        $calls = [
            function ($c) { return ReportTools::getSalesReport(['currency' => $c, 'interval' => 'day']); },
            function ($c) { return ReportTools::getRefundReport(['currency' => $c]); },
            function ($c) { return ReportTools::querySources(['currency' => $c]); },
            function ($c) { return ReportTools::queryOrders(['currency' => $c, 'view' => 'payment_methods']); },
            function ($c) { return ReportTools::querySubscriptions(['currency' => $c, 'view' => 'overview']); },
            function ($c) { return ReportTools::queryCustomers(['currency' => $c, 'view' => 'overview']); },
            function ($c) { return ReportTools::getReport(['currency' => $c, 'report' => 'installments']); },
        ];
        foreach ($calls as $i => $call) {
            $res = $call('US');
            $this->assertSame('invalid_param', is_wp_error($res) ? $res->get_error_code() : null, "call {$i}");
            $this->assertFalse(is_wp_error($call('__all__')), "call {$i} converts __all__");
        }
    }

    public function test_cohorts_take_one_iso_currency(): void
    {
        foreach (['US', '__all__'] as $currency) {
            $res = ReportTools::getReport(['report' => 'cohorts', 'currency' => $currency]);
            $this->assertSame('invalid_param', is_wp_error($res) ? $res->get_error_code() : null, $currency);
        }
    }
}
