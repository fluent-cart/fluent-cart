<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

/**
 * All registered action's handlers should be in app\Hooks\Handlers,
 * addAction is similar to add_action and addCustomAction is just a
 * wrapper over add_action which will add a prefix to the hook name
 * using the plugin slug to make it unique in all WordPress plugins,
 * ex: $app->addCustomAction('foo', ['FooHandler', 'handleFoo']) is
 * equivalent to add_action('slug-foo', ['FooHandler', 'handleFoo']).
 */

/**
 * @var $app FluentCart\Framework\Foundation\Application
 */

use FluentCart\App\App;
use FluentCart\App\Services\FileSystem\Drivers\Local\LocalDriver;
use FluentCart\Framework\Support\Arr;

(new \FluentCart\App\CPT\FluentProducts)->register();
(new \FluentCart\App\Services\Email\EmailNotificationMailer)->register();
(new \FluentCart\App\Hooks\Handlers\CPTHandler)->register();
(new \FluentCart\App\Hooks\Handlers\MenuHandler)->register();
(new \FluentCart\App\Hooks\Handlers\AdminMenuBarHandler)->register();
(new \FluentCart\App\Hooks\Handlers\FluentCartHandler)->register();

(new \FluentCart\App\Hooks\Handlers\ShortCodes\ShopAppHandler)->register();
(new \FluentCart\App\Hooks\Handlers\ExportHandler)->register();

(new FluentCart\App\Hooks\Handlers\CustomCheckout\CustomCheckout())->register();

// Tax Module Init
(new \FluentCart\App\Modules\Tax\TaxModule())->register();

// Register Pro Gateways Promo
(new \FluentCart\App\Hooks\Handlers\PromoGatewaysHandler())->register();

// Register Addon Gateways
(new \FluentCart\App\Hooks\Handlers\AddonGatewaysHandler())->register();


// Web Checkout
(new \FluentCart\App\Hooks\Cart\WebCheckoutHandler())->register();


\FluentCart\App\Hooks\Handlers\BlockEditors\ShopApp\ShopAppBlockEditor::register();
\FluentCart\App\Hooks\Handlers\ShortCodes\SearchBarShortCode::register();
\FluentCart\App\Hooks\Handlers\BlockEditors\SearchBarBlockEditor::register();
\FluentCart\App\Hooks\Handlers\BlockEditors\PricingTableBlockEditor::register();
\FluentCart\App\Hooks\Handlers\BlockEditors\CustomerProfileBlockEditor::register();
\FluentCart\App\Hooks\Handlers\ShortCodes\CustomerProfileHandler::register();
\FluentCart\App\Hooks\Handlers\BlockEditors\CheckoutBlockEditor::register();
\FluentCart\App\Hooks\Handlers\ShortCodes\CartShortcode::register();
\FluentCart\App\Hooks\Handlers\ShortCodes\PricingTableShortCode::register();
\FluentCart\App\Hooks\Handlers\ShortCodes\Checkout\CheckoutPageHandler::register();
\FluentCart\App\Hooks\Handlers\BlockEditors\ProductCardBlockEditor::register();
\FluentCart\App\Hooks\Handlers\BlockEditors\ProductGalleryBlockEditor::register();
\FluentCart\App\Hooks\Handlers\BlockEditors\ProductInfoBlockEditor::register();
\FluentCart\App\Hooks\Handlers\BlockEditors\BuySectionBlockEditor::register();
\FluentCart\App\Hooks\Handlers\ShortCodes\ProductCardShortCode::register();

if (\FluentCart\Api\ModuleSettings::isActive('stock_management')) {
    \FluentCart\App\Hooks\Handlers\BlockEditors\StockBlock::register();
}

(new \FluentCart\App\Hooks\Cart\CartLoader)->register();
(new \FluentCart\App\Hooks\Handlers\GlobalPaymentHandler)->register();

\FluentCart\App\Http\Routes\WebRoutes::register();

(new \FluentCart\App\Modules\IntegrationActions\GlobalIntegrationActionHandler())->register();
(new \FluentCart\App\Hooks\Handlers\GlobalStorageHandler)->register();


//Register Page Handlers
(new \FluentCart\App\Hooks\Handlers\ShortCodes\ReceiptHandler)->register();

(new \FluentCart\App\Modules\Coupon\CouponHandler)->register();

\FluentCart\App\Services\Theme\AdminTheme::applyTheme();
\FluentCart\App\Services\Theme\FrontendTheme::applyTheme();

(new \FluentCart\App\CPT\Pages)->handlePageDelete();
(new \FluentCart\App\Services\FileSystem\DownloadService)->register();

(new \FluentCart\App\Hooks\Handlers\UserHandler())->register();

\FluentCart\App\Http\Routes\AjaxRoute::register();


add_action('fluent_cart/order_paid_ansyc_private_handle', function ($data) {
    $orderId = \FluentCart\Framework\Support\Arr::get($data, 'order_id');

    if (!$orderId) {
        return;
    }

    $order = \FluentCart\App\Models\Order::find($orderId);
    if (!$order || $order->payment_status !== 'paid' || !$order->getMeta('action_scheduler_id')) {
        return;
    }

    $order->deleteMeta('action_scheduler_id');

    $transaction = \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', $order->id)
        ->where('status', \FluentCart\App\Helpers\Status::TRANSACTION_SUCCEEDED)
        ->orderBy('id', 'DESC')
        ->first();

    $eventData = [
        'order'       => $order,
        'transaction' => $transaction,
        'customer'    => $order->customer
    ];

    if ($order->type === 'subscription' || $order->type === 'renewal') {
        $subscription = \FluentCart\App\Models\Subscription::query()->where('parent_order_id', $order->id)->first();
        if ($subscription) {
            $eventData['subscription'] = $subscription;
        }
    }

    do_action('fluent_cart/order_paid_done', $eventData);

}, 1, 1);


//
//$app->addAction('fluent_cart/orders_filter_customer', function ($query, $filters) {
//    return (new \FluentCart\App\Models\Order)->buildCustomerFilterQuery($query, $filters);
//}, 10, 2);

// require the CLI
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('fluent_cart', '\FluentCart\App\Hooks\CLI\Commands');

}

\FluentCart\App\Modules\Subscriptions\SubscriptionModule::register();
\FluentCart\App\Modules\Shipping\ShippingModule::register();


$app->ready(function () use ($app) {
    \FluentCart\App\Models\Connection\ConnectionManager::connect($this->app);
});


// Add to your theme's functions.php or a custom plugin
// For Elementor preview URL


(new \FluentCart\App\Services\Integration)->register();

$app->addAction('fluent_cart/integration/schedule_feed', function ($queueId) use ($app) {
    (new \FluentCart\App\Modules\Integrations\GlobalNotificationHandler())->processIntegrationAction($queueId);
});

// Schedulers
(new \FluentCart\App\Hooks\Scheduler\AutoSchedules\FiveMinuteScheduler())->register();
(new \FluentCart\App\Hooks\Scheduler\AutoSchedules\HourlyScheduler())->register();
(new \FluentCart\App\Hooks\Scheduler\AutoSchedules\DailyScheduler())->register();


/**
 * Theme Hooks
 */

add_action('init', [\FluentCart\App\Modules\Templating\TemplateLoader::class, 'init']);
add_action('after_setup_theme', function () {
    \FluentCart\App\Modules\Templating\TemplateLoader::registerBlockParts();
    (new \FluentCart\App\Modules\Templating\Bricks\BricksLoader())->register();
});

add_action('init', function () {
});

/**
 * Development Hooks
 */
