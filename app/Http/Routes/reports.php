<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

use FluentCart\Framework\Http\Router;
use FluentCart\App\Http\Controllers\Reports\ReportingController;
use FluentCart\App\Http\Controllers\Reports\OrderReportController;
use FluentCart\App\Http\Controllers\Reports\SourceReportController;
use FluentCart\App\Http\Controllers\Reports\RefundReportController;
use FluentCart\App\Http\Controllers\Reports\DefaultReportController;
use FluentCart\App\Http\Controllers\Reports\LicenseReportController;
use FluentCart\App\Http\Controllers\Reports\ProductReportController;
use FluentCart\App\Http\Controllers\Reports\RevenueReportController;
use FluentCart\App\Http\Controllers\Reports\CustomerReportController;
use FluentCart\App\Http\Controllers\Reports\OverviewReportController;
use FluentCart\App\Http\Controllers\Reports\SubscriptionReportController;

/**
 * @var $router Router
 */

$router->prefix('reports')
    ->withPolicy('ReportPolicy')
    ->group(function (Router $router) {

        // Overview
        $router->get('overview', [OverviewReportController::class, 'getOverview']);

        // Default
        $router->get('fetch-report-meta', [ReportingController::class, 'getReportMeta']);
        $router->get('quick-order-stats', [ReportingController::class, 'getOrderQuickStats']);
        $router->get('sales-growth', [ReportingController::class, 'getSalesGrowth']);
        $router->get('report-overview', [ReportingController::class, 'getReportOverview']);
        $router->get('search-repeat-customer', [ReportingController::class, 'searchRepeatCustomer']);
        $router->get('top-products-sold', [ReportingController::class, 'getTopProductsSold']); //Using Resource Api

        //Revenue
        $router->get('revenue', [RevenueReportController::class, 'getRevenue']); //d
        $router->get('revenue-by-group', [RevenueReportController::class, 'getRevenueByGroup']); //d

        // Order
        $router->get('order-value-distribution', [OrderReportController::class, 'getOrderValueDistribution']);
        $router->get('fetch-new-vs-returning-customer', [OrderReportController::class, 'getNewVsReturningCustomer']); //d
        $router->get('fetch-order-by-group', [OrderReportController::class, 'getOrderByGroup']); //d
        $router->get('fetch-report-by-day-and-hour', [OrderReportController::class, 'getReportByDayAndHour']); //d
        $router->get('item-count-distribution', [OrderReportController::class, 'getItemCountDistribution']); //d
        $router->get('order-completion-time', [OrderReportController::class, 'getOrderCompletionTime']); //d
        $router->get('order-chart', [OrderReportController::class, 'getOrderChart']); //d

        //Default
        $router->get('sales-report', [DefaultReportController::class, 'getSalesReport']); //d

        $router->get('fetch-default-report', [DefaultReportController::class, 'getDefaultReport']);
        $router->get('fetch-top-sold-products', [DefaultReportController::class, 'getTopSoldProducts']); //d
        $router->get('fetch-failed-orders', [DefaultReportController::class, 'getFailedOrders']);
        $router->get('fetch-top-sold-variants', [DefaultReportController::class, 'getTopSoldVariants']); //d
        $router->get('fetch-default-report-graphs', [DefaultReportController::class, 'getDefaultReportGraphs']);
        $router->get('fetch-default-report-fluctuations', [DefaultReportController::class, 'getDefaultReportFluctuations']);
        $router->get('fetch-frequently-bought-together', [DefaultReportController::class, 'getFrequentlyBoughtTogether']);
        
        //Refund
        $router->get('refund-chart', [RefundReportController::class, 'getRefundChart']); //d
        $router->get('weeks-between-refund', [RefundReportController::class, 'getWeeksBetweenRefund']); //(d)
        $router->get('refund-data-by-group', [RefundReportController::class, 'getRefundDataByGroup']); //(d)

        //License
        $router->get('license-chart', [LicenseReportController::class, 'getLicenseLineChart']);
        $router->get('license-pie-chart', [LicenseReportController::class, 'getLicensePieChart']);
        $router->get('license-summary', [LicenseReportController::class, 'getLicenseSummary']);

        //Dashboard
        $router->get('dashboard-stats', [ReportingController::class, 'getDashboardStats']);
        $router->get('sales-growth-chart', [ReportingController::class, 'getSalesGrowthChart']);
        $router->get('country-heat-map', [ReportingController::class, 'getCountryHeatMap']);
        $router->get('get-recent-orders', [ReportingController::class, 'getRecentOrders']);
        $router->get('get-unfulfilled-orders', [ReportingController::class, 'getUnfulfilledOrders']);
        $router->get('get-recent-activities', [ReportingController::class, 'getRecentActivities']);
        $router->get('get-dashboard-summary', [ReportingController::class, 'getDashBoardSummary']);
        //Cart
        $router->get('cart-report', [ReportingController::class, 'getAbandonedCartItems']);
        
        //Subscription
        $router->get('subscription-chart', [SubscriptionReportController::class, 'getSubscriptionChart']); //d    
        $router->get('daily-signups', [SubscriptionReportController::class, 'getDailySignups']); //d
        $router->get('retention-chart', [SubscriptionReportController::class, 'getRetentionChart']); //d
        $router->get('future-renewals', [SubscriptionReportController::class, 'getFutureRenewals']); //d

        //Product (y)
        $router->get('product-report', [ProductReportController::class, 'getProductReport']); //d
        $router->get('product-performance', [ProductReportController::class, 'getProductPerformance']); //d

        //Customer
        $router->get('customer-report', [CustomerReportController::class, 'index']); //d
        
        //Source Report
        $router->get('sources', [SourceReportController::class, 'index']);
    });
