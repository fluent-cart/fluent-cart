<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

/**
 * @var $router Router
 */

use FluentCart\App\Http\Controllers\AddonsController;
use FluentCart\App\Http\Controllers\AddressInfoController;
use FluentCart\App\Http\Controllers\AppControllers\AppController;
use FluentCart\App\Http\Controllers\AttributesController;
use FluentCart\App\Http\Controllers\CheckoutFieldsController;
use FluentCart\App\Http\Controllers\CustomerController;
use FluentCart\App\Http\Controllers\DashboardController;
use FluentCart\App\Http\Controllers\EmailNotificationController;
use FluentCart\App\Http\Controllers\FileUploadController;
use FluentCart\App\Http\Controllers\IntegrationController;
use FluentCart\App\Http\Controllers\LabelController;
use FluentCart\App\Http\Controllers\ModuleSettingsController;
use FluentCart\App\Http\Controllers\NotesController;
use FluentCart\App\Http\Controllers\OnboardingController;
use FluentCart\App\Http\Controllers\OrderController;
use FluentCart\App\Http\Controllers\ProductController;
use FluentCart\App\Http\Controllers\ProductDownloadablesController;
use FluentCart\App\Http\Controllers\ProductIntegrationsController;
use FluentCart\App\Http\Controllers\ProductVariationController;
use FluentCart\App\Http\Controllers\StorageController;
use FluentCart\App\Http\Controllers\SettingsController;
use FluentCart\App\Http\Controllers\CouponsController;
use FluentCart\App\Http\Controllers\TaxClassController;
use FluentCart\App\Http\Controllers\TaxConfigurationController;
use FluentCart\App\Http\Controllers\TaxRateController;
use FluentCart\App\Http\Controllers\TemplateController;
use FluentCart\App\Http\Controllers\VariantController;
use FluentCart\App\Http\Controllers\WidgetsController;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\ConnectConfig;
use FluentCart\Framework\Http\Router;
use \FluentCart\App\Http\Controllers\PaymentMethodController;
use \FluentCart\App\Http\Controllers\ActivityController;
use \FluentCart\App\Http\Controllers\TaxController;
use \FluentCart\App\Http\Controllers\TaxEUController;

$router->get('/welcome', 'WelcomeController@index');

$router->get('widgets', [WidgetsController::class, '__invoke']);

$router->prefix('dashboard')->withPolicy('AdminPolicy')->group(function (Router $router) {
    $router->get('/', [DashboardController::class, 'getOnboardingData']);
});

$router->prefix('dashboard')->withPolicy('DashboardPolicy')->group(function (Router $router) {
    $router->get('/stats', [DashboardController::class, 'getDashboardStats'])->meta([
        'permissions' => 'dashboard_stats/view'
    ]);
});
$router->prefix('products')->withPolicy('ProductPolicy')->group(function (Router $router) {

    $router->get('/variants', [ProductVariationController::class, 'index'])->meta([
        'permissions' => [
            'products/view',
            'products/view'
        ]
    ]);

    $router->get('/', [ProductController::class, 'index'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->get('/fetch-term', [ProductController::class, 'getProductTermsList'])->meta([
        'permissions' => 'products/view'
    ]);
    $router->get('/searchProductByName', [ProductController::class, 'searchProductByName'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->get('/searchVariantByName', [ProductController::class, 'searchVariantByName'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->get('/search-product-variant-options', [ProductController::class, 'searchProductVariantOptions'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->get('/findSubscriptionVariants', [ProductController::class, 'findSubscriptionVariants'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->get('/fetchProductsByIds', [ProductController::class, 'fetchProductsByIds'])->meta([
        'permissions' => 'products/view'
    ]);
    $router->get('/fetchVariationsByIds', [ProductController::class, 'fetchVariationsByIds'])->meta([
        'permissions' => 'products/view'
    ]);
    $router->post('/fetch-term-by-parent', [ProductController::class, 'getProductTermListByParent'])->meta([
        'permissions' => 'products/view'
    ]);
    $router->post('/', [ProductController::class, 'create'])->meta([
        'permissions' => 'products/create'
    ]);
    $router->get('/get-max-excerpt-word-count', [ProductController::class, 'getMaxExcerptWordCount'])->meta([
        'permissions' => 'products/view'
    ]);
    $router->get('/{product}', [ProductController::class, 'find'])->meta([
        'permissions' => 'products/view'
    ]);
    $router->get('/{productId}/pricing', [ProductController::class, 'get'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->get('/{id}/upgrade-paths', [ProductController::class, 'getUpgradeSettings'])->int('id')->meta([
        'permissions' => 'products/view'
    ]);
    $router->post('/{id}/upgrade-path', [ProductController::class, 'saveUpgradeSetting'])->int('id')->meta([
        'permissions' => 'products/edit'
    ]);
    $router->post('/upgrade-path/{id}/update', [ProductController::class, 'updateUpgradePath'])->int('id')->meta([
        'permissions' => 'products/edit'
    ]);
    $router->delete('/upgrade-path/{id}/delete', [ProductController::class, 'deleteUpgradePath'])->int('id')->meta([
        'permissions' => 'products/delete'
    ]);

    $router->get('/variation/{variantId}/upgrade-paths', [ProductController::class, 'getUpgradePaths'])->int('id')->meta([
        'permissions' => 'products/view'
    ]);

    $router->post('/{postId}/pricing', [ProductController::class, 'update'])->meta([
        'permissions' => 'products/edit'
    ]);

    $router->post('/{postId}/update-long-desc-editor-mode', [ProductController::class, 'updateLongDescEditorMode'])->meta([
        'permissions' => 'products/edit'
    ]);
    $router->post('/{postId}/tax-class', [ProductController::class, 'updateTaxClass'])->meta([
        'permissions' => 'products/edit'
    ]);
    $router->post('/{postId}/tax-class/remove', [ProductController::class, 'removeTaxClass'])->meta([
        'permissions' => 'products/edit'
    ]);


    $router->post('/{postId}/sync-downloadable-files', [ProductDownloadablesController::class, 'syncDownloadableFiles'])->meta([
        'permissions' => 'products/edit'
    ]);
    $router->put('/{downloadableId}/update', [ProductDownloadablesController::class, 'update'])->meta([
        'permissions' => 'products/edit'
    ]);
    $router->delete('/{downloadableId}/delete', [ProductDownloadablesController::class, 'delete'])->meta([
        'permissions' => 'products/delete'
    ]);
    $router->get('getDownloadableUrl/{downloadableId}', [ProductDownloadablesController::class, 'getDownloadableUrl'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->get('/{productId}/pricing-widgets', [ProductController::class, 'getPricingWidgets'])->meta([
        'permissions' => 'products/view'
    ]);
    $router->get('/{variantId}/thumbnail', [ProductController::class, 'setProductImage'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->post('/{postId}/update-variant-option', [ProductController::class, 'updateVariantOption'])->int('id')->meta([
        'permissions' => 'products/edit'
    ]);
    $router->post('/add-product-terms', [ProductController::class, 'addProductTerms'])->meta([
        'permissions' => 'products/edit'
    ]);
    $router->delete('/{product}', [ProductController::class, 'delete'])->meta([
        'permissions' => 'products/delete'
    ]);

    $router->post('/do-bulk-action', [ProductController::class, 'handleBulkActions'])->meta([
        'permissions' => 'products/edit'
    ]);

    $router->post('/create-dummy', [ProductController::class, 'createDummyProducts'])->meta([
        'permissions' => 'products/create'
    ]);

    $router->post('/sync-taxonomy-term/{postId}', [ProductController::class, 'syncTaxonomyTerms'])->meta([
        'permissions' => 'products/edit'
    ]);
    $router->post('/delete-taxonomy-term/{postId}', [ProductController::class, 'deleteTaxonomyTerms'])->meta([
        'permissions' => 'products/edit'
    ]);

    $router->post('/detail/{detailId}', [ProductController::class, 'updateProductDetail'])->meta([
        'permissions' => 'products/edit'
    ]);

    $router->post('/variants', [ProductVariationController::class, 'create'])->meta([
        'permissions' => 'products/create'
    ]);

    $router->post('/variants/{variantId}', [ProductVariationController::class, 'update'])->meta([
        'permissions' => 'products/edit'
    ]);

    $router->delete('/variants/{variantId}', [ProductVariationController::class, 'delete'])->meta([
        'permissions' => 'products/delete'
    ]);

    $router->post('/variants/{variantId}/setMedia', [ProductVariationController::class, 'setMedia'])->meta([
        'permissions' => 'products/edit'
    ]);

    $router->put('/variants/{variantId}/pricing-table', [ProductVariationController::class, 'updatePricingTable'])->meta([
        'permissions' => 'products/edit'
    ]);
});


$router->prefix('variants')->withPolicy('ProductPolicy')->group(function (Router $router) {
    $router->get('/', [VariantController::class, 'index'])->meta([
        'permissions' => 'products/view'
    ]);
});
//Integration route declaration
$router->prefix('integration')->withPolicy('IntegrationPolicy')->group(function (Router $router) {

    $router->get('addons', [AddonsController::class, 'getAddons'])->meta([
        'permissions' => 'integrations/view'
    ]);

    $router->get('global-settings', [IntegrationController::class, 'getGlobalSettings'])->meta([
        'permissions' => 'integrations/view'
    ]);

    $router->post('global-settings', [IntegrationController::class, 'setGlobalSettings'])->meta([
        'permissions' => 'integrations/manage'
    ]);

    $router->get('global-feeds', [IntegrationController::class, 'getFeeds'])->meta([
        'permissions' => 'integrations/view'
    ]);

    $router->post('global-feeds/change-status/{integration_id}', [IntegrationController::class, 'changeStatus'])->int('integration_id')->meta([
        'permissions' => 'integrations/manage'
    ]);

    $router->delete('global-feeds/{integration_id}', [IntegrationController::class, 'deleteSettings'])->int('integration_id')->meta([
        'permissions' => 'integrations/delete'
    ]);

    // For product specific integration feed editor. New or existing integration
    $router->get('global-feeds/settings', [IntegrationController::class, 'getSettings'])->meta([
        'permissions' => 'integrations/view'
    ]);

    // For integration feed editor. For new or existing integration
    $router->post('global-feeds/settings', [IntegrationController::class, 'saveSettings'])->meta([
        'permissions' => 'integrations/manage'
    ]);

    $router->get('feed/lists', [IntegrationController::class, 'lists'])->meta([
        'permissions' => 'integrations/view'
    ]);


    $router->get('feed/dynamic_options', [IntegrationController::class, 'getDynamicOptions'])->meta([
        'permissions' => 'integrations/view'
    ]);

    $router->post('feed/chained', [IntegrationController::class, 'chained'])->meta([
        'permissions' => 'integrations/manage'
    ]);

    $router->post('feed/install-plugin', [AddonsController::class, 'installAndActivate'])->meta([
        'permissions' => 'integrations/manage'
    ]);

});

//End integration route declaration

$router->post('settings/payment-methods/paypal/seller-auth-token', [ConnectConfig::class, 'getSellerAuthToken'])->withPolicy('AdminPolicy')->meta([
    'permissions' => 'super_admin'
]);

$router->post('settings/payment-methods/paypal/webhook/setup', [PaymentMethodController::class, 'setPayPalWebhook'])->withPolicy('AdminPolicy')->meta([
    'permissions' => 'super_admin'
]);
$router->get('settings/payment-methods/paypal/webhook/check', [PaymentMethodController::class, 'checkPayPalWebhook'])->withPolicy('AdminPolicy')->meta([
    'permissions' => 'super_admin'
]);


$router->prefix('settings/')
    ->withPolicy('StoreSettingsPolicy')
    ->group(function (Router $router) {

        $router->get('payment-methods', [PaymentMethodController::class, 'getSettings'])->meta([
            'permissions' => 'is_super_admin'
        ]);
        $router->post('payment-methods', [PaymentMethodController::class, 'store'])->meta([
            'permissions' => 'is_super_admin'
        ]);
        $router->get('payment-methods/all', [PaymentMethodController::class, 'index'])->meta([
            'permissions' => 'is_super_admin'
        ]);
        
        $router->post('payment-methods/reorder', [PaymentMethodController::class, 'reorder'])->meta([
            'permissions' => 'is_super_admin'
        ]);

        $router->get('payment-methods/connect/info', [PaymentMethodController::class, 'connectInfo'])->meta([
            'permissions' => 'is_super_admin'
        ]);
        $router->post('payment-methods/disconnect', [PaymentMethodController::class, 'disconnect'])->meta([
            'permissions' => 'is_super_admin'
        ]);

        $router->post('payment-methods/design', [PaymentMethodController::class, 'saveDesign'])->meta([
            'permissions' => 'is_super_admin'
        ]);

        $router->post('payment-methods/install-addon', [PaymentMethodController::class, 'installAddon'])->meta([
            'permissions' => 'is_super_admin'
        ]);

        $router->post('payment-methods/activate-addon', [PaymentMethodController::class, 'activateAddon'])->meta([
            'permissions' => 'is_super_admin'
        ]);

        // permissions get and store

        $router->get('/permissions', [SettingsController::class, 'getPermissions'])
            ->meta([
                'permissions' => 'is_super_admin'
            ]);

        $router->post('/permissions', 'SettingsController@savePermissions')->meta([
            'permissions' => 'is_super_admin'
        ]);;


        $router->get('/store', [SettingsController::class, 'getStore'])
            ->meta([
                'permissions' => 'store/settings'
            ]);
        $router->post('/store', [SettingsController::class, 'saveStore'])
            ->meta([
                'permissions' => 'store/settings'
            ]);


        $router->get('modules/', [ModuleSettingsController::class, 'getSettings'])->meta([
            'permissions' => 'is_supper_admin'
        ]);
        $router->post('modules/', [ModuleSettingsController::class, 'saveSettings'])->meta([
            'permissions' => 'is_supper_admin'
        ]);


        $router->post('confirmation', [SettingsController::class, 'saveConfirmation'])->meta([
            'permissions' => 'is_supper_admin'
        ]);
        //shortcode get
        $router->get('confirmation/shortcode', [SettingsController::class, 'getShortcode'])->meta([
            'permissions' => 'is_supper_admin'
        ]);


        $router->get('storage-drivers', [StorageController::class, 'index'])->meta([
            'permissions' => 'is_super_admin'
        ]);
        $router->post('storage-drivers', [StorageController::class, 'store'])->meta([
            'permissions' => 'is_super_admin'
        ]);
        $router->get('storage-drivers/active-drivers', [StorageController::class, 'getActiveDrivers'])->meta([
            'permissions' => 'is_super_admin'
        ]);
        $router->get('storage-drivers/{driver}', [StorageController::class, 'getSettings'])->meta([
            'permissions' => 'is_super_admin'
        ]);
        $router->post('storage-drivers/verify-info', [StorageController::class, 'verifyConnectInfo'])->meta([
            'permissions' => 'is_super_admin'
        ]);


    });

$router->prefix('orders')->withPolicy('OrderPolicy')->group(function (Router $router) {
    $router->get('/', [OrderController::class, 'index'])->meta([
        'permissions' => 'orders/view'
    ]);

    $router->post('/calculate-shipping', [OrderController::class, 'updateShipping']);

    $router->post('/', [OrderController::class, 'store'])->meta([
        'permissions' => 'orders/create'
    ]);

    $router->post('/do-bulk-action', [OrderController::class, 'handleBulkActions'])->meta([
        'permissions' => 'orders/manage'
    ]);

    $router->post('/{order}/mark-as-paid', [OrderController::class, 'markAsPaid'])->meta([
        'permissions' => 'orders/manage'
    ]);

    $router->post('/{order}/generate-missing-licenses', [OrderController::class, 'generateMissingLicenses'])->meta([
        'permissions' => 'orders/manage'
    ]);

    $router->get('/{order_id}', [OrderController::class, 'getDetails'])->int('order_id')->meta([
        'permissions' => 'orders/view'
    ]);

    $router->post('/{order_id}', [OrderController::class, 'updateOrder'])
        ->int('order_id')
        ->meta([
            'permissions' => 'orders/manage'
        ]);

    $router->post('/{order_id}/update-address-id', [OrderController::class, 'updateOrderAddressId'])
        ->int('order_id')
        ->meta([
            'permissions' => 'orders/manage'
        ]);

    $router->post('/{order_id}/refund', [OrderController::class, 'refundOrder'])
        ->int('order_id')
        ->meta([
            'permissions' => 'orders/can_refund'
        ]);

    $router->post('/{order_id}/change-customer', [OrderController::class, 'changeCustomer'])->meta([
        'permissions' => 'orders/manage'
    ]);

    $router->post('/{order_id}/create-and-change-customer', [OrderController::class, 'createAndChangeCustomer'])->meta([
        'permissions' => 'orders/manage'
    ])->int('order_id');

    $router->delete('/{order_id}', [OrderController::class, 'deleteOrder'])->meta([
        'permissions' => 'orders/delete'
    ])->int('order_id');;

    $router->put('/{order}/statuses', [OrderController::class, 'updateStatuses'])->meta([
        'permissions' => 'orders/manage_statuses'
    ]);

    $router->get('/{order}/transactions', [OrderController::class, 'getDetails'])->meta([
        'permissions' => 'orders/view'
    ]);

    $router->post('/{order}/transactions/{transaction_id}/accept-dispute/', [OrderController::class, 'acceptDispute'])->meta([
        'permissions' => 'orders/view'
    ]);

    $router->get('/{id}/transactions/{transaction_id}', [OrderController::class, 'getDetails'])->meta([
        'permissions' => 'orders/view'
    ]);

    $router->put('/{order}/address/{id}', [OrderController::class, 'updateOrderAddress'])->meta([
        'permissions' => 'orders/manage'
    ]);

    $router->put('/{order}/transactions/{transaction}/status', [OrderController::class, 'updateTransactionStatus'])->meta([
        'permissions' => 'orders/manage'
    ]);

    $router->post('/{order}/create-custom', [OrderController::class, 'createCustom'])->int('id')->meta([
        'permissions' => 'orders/create'
    ]);

    $router->get('/shipping_methods', [OrderController::class, 'getShippingMethods'])->meta([
        'permissions' => 'orders/manage'
    ]);

});


$router->prefix('labels')->withPolicy('LabelPolicy')->group(function (Router $router) {
    $router->get('/', [LabelController::class, 'index'])->meta([
        'permissions' => 'labels/view'
    ]);
    $router->post('/', [LabelController::class, 'create'])->meta([
        'permissions' => 'labels/manage'
    ]);
    $router->post('/update-label-selections', [LabelController::class, 'updateSelections'])->meta([
        'permissions' => 'labels/manage'
    ]);
});

$router->prefix('customers')->withPolicy('CustomerPolicy')->group(function (Router $router) {
    $router->get('/', [CustomerController::class, 'index'])->meta([
        'permissions' => 'customers/view'
    ]);

    $router->post('/', [CustomerController::class, 'store'])->meta([
        'permissions' => 'customers/manage'
    ]);

    $router->get('/get-stats/{customer}', [CustomerController::class, 'getStats'])->meta([
        'permissions' => 'customers/view'
    ]);

    $router->get('/attachable-user', [CustomerController::class, 'getAttachableUser'])->meta([
        'permissions' => 'customers/manage'
    ]);

    $router->post('/{customerId}/attachable-user', [CustomerController::class, 'setAttachableUser'])->meta([
        'permissions' => 'customers/manage'
    ]);

    $router->post('/{customerId}/detach-user', [CustomerController::class, 'detachCustomer'])->meta([
        'permissions' => 'customers/manage'
    ]);

    $router->get('/{customerId}', [CustomerController::class, 'find'])->int('customerId')->meta([
        'permissions' => 'customers/view'
    ]);

    $router->get('/{customerId}/order', [CustomerController::class, 'findOrder'])->int('customerId')->meta([
        'permissions' => 'customers/view'
    ]);

    $router->put('/{customerId}', [CustomerController::class, 'update'])->meta([
        'permissions' => 'customers/manage'
    ]);

    $router->put('/{customerId}/additional-info', [CustomerController::class, 'updateAdditionalInfo'])->meta([
        'permissions' => 'customers/manage'
    ]);

    $router->post('/do-bulk-action', [CustomerController::class, 'handleBulkActions'])->meta([
        'permissions' => 'customers/manage'
    ]);

    $router->get('/{customerId}/orders', [CustomerController::class, 'getCustomerOrders'])->meta([
        'permissions' => 'customers/view'
    ]);

    $router->get('/{customerId}/address', [CustomerController::class, 'getAddress'])->meta([
        'permissions' => 'customers/view'
    ]);

    $router->put('/{customerId}/address', [CustomerController::class, 'updateAddress'])->meta([
        'permissions' => 'customers/manage'
    ]);

    $router->post('/{customerId}/address', [CustomerController::class, 'createAddress'])->meta([
        'permissions' => 'customers/manage'
    ]);

    $router->delete('/{customerId}/address', [CustomerController::class, 'removeAddress'])->meta([
        'permissions' => 'customers/delete'
    ]);

    $router->post('/{customerId}/address/make-primary', [CustomerController::class, 'setAddressPrimary'])->meta([
        'permissions' => 'customers/manage'
    ]);

});


$router->prefix('options')->withPolicy('ProductPolicy')->group(function (Router $router) {
    $router->get('attr/groups', [AttributesController::class, 'getGroups'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->post('attr/group/', [AttributesController::class, 'createGroup'])->meta([
        'permissions' => 'products/create'
    ]);

    $router->get('attr/group/{group_id}', [AttributesController::class, 'getGroup'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->put('attr/group/{group_id}', [AttributesController::class, 'updateGroup'])->meta([
        'permissions' => 'products/edit'
    ]);

    $router->delete('attr/group/{group_id}', [AttributesController::class, 'deleteGroup'])->meta([
        'permissions' => 'products/delete'
    ]);

    $router->get('attr/group/{group_id}/terms', [AttributesController::class, 'getTerms'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->post('attr/group/{group_id}/term', [AttributesController::class, 'createTerm'])->meta([
        'permissions' => 'products/create'
    ]);

    $router->post('attr/group/{group_id}/term/{term_id}', [AttributesController::class, 'updateTerm'])->meta([
        'permissions' => 'products/edit'
    ]);

    $router->delete('attr/group/{group_id}/term/{term_id}', [AttributesController::class, 'deleteTerm'])->meta([
        'permissions' => 'products/delete'
    ]);

    $router->post('attr/group/{group_id}/term/{term_id}/serial', [AttributesController::class, 'changeTermSerial'])->meta([
        'permissions' => 'products/edit'
    ]);
});


$router->prefix('onboarding')->withPolicy('AdminPolicy')->group(function (Router $router) {
    $router->get('/', [OnboardingController::class, 'index']);
    $router->post('/', [OnboardingController::class, 'saveSettings']);
    $router->post('/create-pages', [OnboardingController::class, 'createPages']);
    $router->post('/create-page', [OnboardingController::class, 'createPage']);
});
$router->prefix('email-notification')->withPolicy('StoreSensitivePolicy')->group(function (Router $router) {
    $router->get('/', [EmailNotificationController::class, 'index']);
    $router->get('/get-short-codes', [EmailNotificationController::class, 'getShortCodes']);
    $router->get('/get-settings', [EmailNotificationController::class, 'getSettings']);
    $router->post('/save-settings', [EmailNotificationController::class, 'saveSettings']);
    $router->post('/enable-notification/{name}', [EmailNotificationController::class, 'enableNotification']);
    $router->post('/get-template', [EmailNotificationController::class, 'getTemplate']);

    $router->get('/{notification}', [EmailNotificationController::class, 'find']);
    $router->put('/{notification}', [EmailNotificationController::class, 'update']);

});

$router->prefix('templates')->withPolicy('AdminPolicy')->group(function (Router $router) {
    $router->get('/print-templates', [TemplateController::class, 'getPrintTemplates']);
    $router->put('/print-templates', [TemplateController::class, 'savePrintTemplates']);
});


$router->prefix('coupons')->withPolicy('CouponPolicy')->group(function ($router) {
    $router->get('/listCoupons', [CouponsController::class, 'listCoupons'])->meta([
        'permissions' => ['orders/create', 'orders/manage', 'coupons/view']
    ]);

    $router->get('/', [CouponsController::class, 'index'])->meta([
        'permissions' => 'coupons/view'
    ]);
    $router->get('/getSettings', [CouponsController::class, 'getSettings'])->meta([
        'permissions' => 'coupons/view'
    ]);
    $router->get('/{id}', [CouponsController::class, 'viewDetails'])->meta([
        'permissions' => 'coupons/view'
    ]);
    $router->post('/', [CouponsController::class, 'create'])->meta([
        'permissions' => 'coupons/manage'
    ]);
    $router->put('/{id}', [CouponsController::class, 'update'])->meta([
        'permissions' => 'coupons/manage'
    ]);
    $router->delete('/{id}', [CouponsController::class, 'delete'])->meta([
        'permissions' => 'coupons/delete'
    ]);
    $router->post('/apply', [CouponsController::class, 'applyCoupon'])->meta([
        'permissions' => ['orders/create', 'orders/manage'],
    ]);
    // $router->post('/apply', [CouponsController::class, 'calculateFinalAmount']);
    $router->post('/cancel', [CouponsController::class, 'cancelCoupon'])->meta([
        'permissions' => ['orders/create', 'orders/manage'],
    ]);

    $router->post('/re-apply', [CouponsController::class, 'reapplyCoupon'])->meta([
        'permissions' => ['orders/create', 'orders/manage'],
    ]);

    $router->post('/checkProductEligibility', [CouponsController::class, 'isProductEligible'])->meta([
        'permissions' => ['orders/create', 'orders/manage'],
    ]);

    $router->post('/storeCouponSettings', [CouponsController::class, 'storeCouponSettings'])->meta([
        'permissions' => 'coupons/manage'
    ]);
});

$router->prefix('files')->withPolicy('StoreSensitivePolicy')->group(function (Router $router) {
    $router->get('/', [FileUploadController::class, 'index']);
    $router->post('/upload', [FileUploadController::class, 'upload']);
    $router->get('/bucket-list', [FileUploadController::class, 'getBucketList']);
    $router->delete('/delete', [FileUploadController::class, 'deleteFile']);
});

$router->post('/upload-editor-file', [FileUploadController::class, 'uploadEditorFile'])->withPolicy('AdminPolicy');

$router->prefix('notes')->withPolicy('AdminPolicy')->group(function (Router $router) {
    $router->post('/attach', [NotesController::class, 'attach']);
});

$router->prefix('app')->withPolicy('AdminPolicy')->group(function (Router $router) {
    $router->get('init', [AppController::class, 'init']);
    $router->get('attachments', [AppController::class, 'attachments']);
    $router->post('upload-attachments', [AppController::class, 'uploadAttachments']);
});

$router->prefix('activity')->withPolicy('AdminPolicy')->group(function (Router $router) {
    $router->get('/', [ActivityController::class, 'index']);
    $router->delete('/{id}', [ActivityController::class, 'delete']);
    $router->put('/{id}/mark-read', [ActivityController::class, 'markReadUnread']);
});

$router->prefix('taxes')->withPolicy('AdminPolicy')->group(function (Router $router) {
    $router->get('/', [TaxController::class, 'index']);
    $router->post('/', [TaxController::class, 'markAsFiled']);
});

$router->prefix('address-info')->withPolicy('CustomerPolicy')->group(function ($router) {
    $router->get('/countries', [AddressInfoController::class, 'countriesOption']);
    $router->get('/get-country-info', [AddressInfoController::class, 'getCountryInfo']);
});

// Add these new routes for product-specific integration settings
$router->prefix('products')->withPolicy('ProductPolicy')->group(function (Router $router) {
    // New routes for product integrations
    $router->get('/{product_id}/integrations/{integration_name}/settings', [ProductIntegrationsController::class, 'getProductIntegrationSettings'])->meta([
        'permissions' => 'products/view'
    ]);

    $router->post('/{product_id}/integrations', [ProductIntegrationsController::class, 'saveProductIntegration'])->meta([
        'permissions' => 'products/manage'
    ]);

    $router->delete('/{product_id}/integrations/{integration_id}', [ProductIntegrationsController::class, 'deleteProductIntegration'])->meta([
        'permissions' => 'products/manage'
    ]);

    $router->post('/{product_id}/integrations/feed/change-status', [ProductIntegrationsController::class, 'changeStatus'])->meta([
        'permissions' => 'products/manage'
    ]);

    $router->get('/{productId}/integrations', [ProductIntegrationsController::class, 'getFeeds'])->meta([
        'permissions' => 'products/view'
    ]);

});


// Tax Routes
$router->prefix('tax')->withPolicy('StoreSensitivePolicy')->group(function (Router $router) {
    // TaxClasses routes
    $router->get('classes', [TaxClassController::class, 'index']);
    $router->post('classes', [TaxClassController::class, 'store']);
    $router->put('classes/{id}', [TaxClassController::class, 'update'])->int('id');
    $router->delete('classes/{id}', [TaxClassController::class, 'delete'])->int('id');

    // TaxRates routes
    $router->get('rates', [TaxRateController::class, 'index']);
    $router->get('rates/country/rates/{country_code}', [TaxRateController::class, 'show']);
    $router->post('rates/country/override', [TaxRateController::class, 'saveShippingOverride']);
    $router->delete('rates/country/override/{id}', [TaxRateController::class, 'deleteShippingOverride'])->int('id');
    $router->put('country/rate/{id}', [TaxRateController::class, 'update'])->int('id');
    $router->post('country/rate', [TaxRateController::class, 'store']);
    $router->delete('country/rate/{id}', [TaxRateController::class, 'delete'])->int('id');
    $router->delete('country/{country_code}', [TaxRateController::class, 'deleteCountry']);
    $router->get('country-tax-id/{country_code}', [TaxRateController::class, 'getCountryTaxId']);
    $router->post('country-tax-id/{country_code}', [TaxRateController::class, 'saveCountryTaxId']);

//    $router->post('rates/country', [TaxRateController::class, 'addCountry']);

    // TaxConfiguration routes
    $router->get('configuration/rates', [TaxConfigurationController::class, 'getTaxRates']);
    $router->post('configuration/countries', [TaxConfigurationController::class, 'saveConfiguredCountries']);
    $router->get('configuration/settings', [TaxConfigurationController::class, 'getSettings']);
    $router->post('configuration/settings', [TaxConfigurationController::class, 'saveSettings']);
    $router->post('configuration/settings/eu-vat', [TaxEUController::class, 'saveEuVatSettings']);
    $router->get('configuration/settings/eu-vat/rates', [TaxEUController::class, 'getEuTaxRates']);
    $router->post('configuration/settings/eu-vat/oss/override', [TaxEUController::class, 'saveOssTaxOverride']);
    $router->post('configuration/settings/eu-vat/oss/shipping-override', [TaxEUController::class, 'saveOssShippingOverride']);
    $router->delete('configuration/settings/eu-vat/oss/override', [TaxEUController::class, 'deleteOssTaxOverride'])->int('id');
    $router->delete('configuration/settings/eu-vat/oss/shipping-override', [TaxEUController::class, 'deleteOssShippingOverride'])->int('id');
});

$router->prefix('checkout-fields')->withPolicy('StoreSensitivePolicy')->group(function (Router $router) {
    $router->get('get-fields', [CheckoutFieldsController::class, 'getFields']);
    $router->post('save-fields', [CheckoutFieldsController::class, 'saveFields']);
});
