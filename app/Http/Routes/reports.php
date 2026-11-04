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
use FluentCart\App\Http\Controllers\Reports\RetentionSnapshotController;

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

        //Revenue
        $router->get('revenue', [RevenueReportController::class, 'getRevenue']); //d

        // Order
        $router->get('fetch-new-vs-returning-customer', [OrderReportController::class, 'getNewVsReturningCustomer']); //d
        $router->get('order-chart', [OrderReportController::class, 'getOrderChart']); //d

        //Default

        $router->get('fetch-top-sold-products', [DefaultReportController::class, 'getTopSoldProducts']); //d

        //Refund

        //License
        $router->get('license-chart', [LicenseReportController::class, 'getLicenseLineChart']);
        $router->get('license-pie-chart', [LicenseReportController::class, 'getLicensePieChart']);
        $router->get('license-summary', [LicenseReportController::class, 'getLicenseSummary']);

        //Dashboard
        $router->get('dashboard-stats', [ReportingController::class, 'getDashboardStats']);
        $router->get('sales-growth-chart', [ReportingController::class, 'getSalesGrowthChart']);
        $router->get('country-heat-map', [ReportingController::class, 'getCountryHeatMap']);
        $router->get('get-recent-orders', [ReportingController::class, 'getRecentOrders']);
        $router->get('get-recent-activities', [ReportingController::class, 'getRecentActivities']);
        //Subscription
        $router->get('future-renewals', [SubscriptionReportController::class, 'getFutureRenewals']); //d
        $router->get('subscription-retention', [SubscriptionReportController::class, 'getRetentionData']);
        $router->get('subscription-cohorts', [SubscriptionReportController::class, 'getCohortData']);
        $router->get('installments', [SubscriptionReportController::class, 'getInstallments']);
        $router->get('installments/at-risk', [SubscriptionReportController::class, 'getInstallmentsAtRisk']);
        $router->post('retention-snapshots/generate', [RetentionSnapshotController::class, 'generate']);
        $router->post('retention-snapshots/step', [RetentionSnapshotController::class, 'step']);

        //Product (y)

        //Customer
        
        //Source Report
        $router->get('sources', [SourceReportController::class, 'index']);

        $router->get('subscription-health', [SubscriptionReportController::class, 'getHealth']);
        $router->get('coupons', [\FluentCart\App\Http\Controllers\Reports\CouponReportController::class, 'index']);
        $router->get('customer-insights', [CustomerReportController::class, 'insights']);
        $router->get('payment-methods', [RevenueReportController::class, 'getPaymentMethods']);
        $router->get('failed-orders', [OrderReportController::class, 'getFailedOrders']);
        $router->get('locations', [RevenueReportController::class, 'getLocations']);
        $router->get('order-mix', [OrderReportController::class, 'getOrderMix']);
        $router->get('refunds-overview', [RefundReportController::class, 'getOverview']);
        $router->get('subscriptions-overview', [SubscriptionReportController::class, 'getOverview']);
        $router->get('subscription-variations', [SubscriptionReportController::class, 'getSubscriptionVariations']);
        $router->get('product-sales', [ProductReportController::class, 'getProductSales']);
        $router->get('customer-overview', [CustomerReportController::class, 'overview']);
        $router->get('source-channels', [SourceReportController::class, 'channels']);
        $router->get('coupon-overview', [\FluentCart\App\Http\Controllers\Reports\CouponReportController::class, 'overview']);
    });
