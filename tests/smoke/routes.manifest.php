<?php
/**
 * Phase 1 GET-route smoke manifest.
 *
 * Declarations are keyed to the exact source file and line that registers the
 * route. Additional executable cases capture distinct parameter shapes used by
 * the admin SPA or public frontend. Web and faker surfaces remain statically
 * auditable even when their transport or side effects make invocation unsafe.
 */

$config = require dirname(__DIR__) . '/suite.config.php';
$declarations = [];
$cases = [];

$declare = function ($file, $line, $route, array $case = []) use (&$declarations, &$cases) {
    $id = $file . ':' . $line;
    if (isset($declarations[$id])) {
        throw new RuntimeException('Duplicate smoke declaration: ' . $id);
    }

    $declaration = [
        'id'          => $id,
        'source_file' => $file,
        'source_line' => $line,
        'route'       => ltrim($route, '/'),
        'transport'   => isset($case['transport']) ? $case['transport'] : 'rest',
    ];
    $declarations[$id] = $declaration;

    $cases[] = array_merge([
        'label'          => $id . ' GET /' . ltrim($route, '/'),
        'declaration_id' => $id,
        'source_file'    => $file,
        'source_line'    => $line,
        'route'          => ltrim($route, '/'),
        'transport'      => $declaration['transport'],
        'auth'           => 'admin',
        'params'         => [],
        'ok'             => [200],
        'variation'      => false,
    ], $case);

    return $id;
};

$variation = function ($declarationId, $label, array $params, $consumerFile, $consumerLine, array $extra = []) use (&$declarations, &$cases) {
    if (!isset($declarations[$declarationId])) {
        throw new RuntimeException('Unknown declaration for variation: ' . $declarationId);
    }
    $declaration = $declarations[$declarationId];
    $cases[] = array_merge([
        'label'          => $declarationId . ' ' . $label,
        'declaration_id' => $declarationId,
        'source_file'    => $declaration['source_file'],
        'source_line'    => $declaration['source_line'],
        'consumer_file'  => $consumerFile,
        'consumer_line'  => $consumerLine,
        'route'          => $declaration['route'],
        'transport'      => $declaration['transport'],
        'auth'           => 'admin',
        'params'         => $params,
        'ok'             => [200],
        'variation'      => true,
    ], $extra);
};

$api = 'app/Http/Routes/api.php';

$id = $declare($api, 47, 'widgets');
$variation($id, 'single-order widget payload', [
    'filter' => 'fluent_cart_single_order_page',
    'data'   => ['order_id' => '{order_id}'],
], 'resources/admin/Bits/Components/DynamicTemplates/DynamicTemplates.vue', 65, ['needs' => 'order']);

$declare($api, 53, 'dashboard', [
    'skip' => 'DashboardController::getOnboardingData() calls GlobalPaymentHandler::getAll(); Phase 1 is forbidden from invoking any payment gateway registry.',
]);
$declare($api, 61, 'dashboard/stats');

$declare($api, 67, 'products/variants');
$productsId = $declare($api, 74, 'products', [
    'params' => [
        'per_page'   => 10,
        'page'       => 1,
        'sort_by'    => 'ID',
        'sort_type'  => 'DESC',
        'with'       => ['detail', 'variants:post_id,available'],
        'scopes'     => [],
        'active_view'=> 'all',
        'filter_type'=> 'simple',
        'search'     => '',
        'user_tz'    => 'UTC',
    ],
]);
foreach (['publish', 'draft', 'physical', 'digital', 'subscribable', 'bundle', 'non_bundle'] as $activeView) {
    $variation($productsId, 'table active_view=' . $activeView, [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'ID',
        'sort_type'   => 'DESC',
        'with'        => ['detail', 'variants:post_id,available'],
        'scopes'      => [],
        'active_view' => $activeView,
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ], 'resources/admin/utils/table-new/ProductTable.js', 12);
}
$variation($productsId, 'table search and ordering', [
    'per_page'    => 10,
    'page'        => 1,
    'sort_by'     => 'post_title',
    'sort_type'   => 'ASC',
    'with'        => ['detail', 'variants:post_id,available'],
    'scopes'      => [],
    'active_view' => 'all',
    'filter_type' => 'simple',
    'search'      => '__fc_smoke__',
    'user_tz'     => 'UTC',
], 'resources/admin/utils/table-new/Table.js', 457);
$variation($productsId, 'table advanced relation filter', [
    'active_view'      => 'publish',
    'per_page'         => 10,
    'page'             => 1,
    'filter_type'      => 'advanced',
    'sort_by'          => 'ID',
    'with'             => ['detail.variants.media', 'categories', 'variants.media'],
    'advanced_filters' => '[[{"source":["variations","variation_items"],"filter_type":"relation","operator":"contains","value":["{variation_id}"],"column":"id","relation":"variants"}]]',
], 'resources/admin/elementor/PricingTable/App.vue', 190, ['needs' => 'variation']);
$variation($productsId, 'order editor product selector includes bundle children', [
    'active_view' => 'publish',
    'per_page'    => 10,
    'page'        => 1,
    'search'      => '',
    'filter_type' => 'simple',
    'sort_by'     => 'ID',
    'with'        => ['detail.variants.media', 'detail.variants.bundleChildren', 'categories'],
], 'resources/admin/Modules/Orders/Modals/AddProductItemModal.vue', 434);
$variation($productsId, 'Elementor product selector includes chosen ID', [
    'active_view' => 'publish',
    'per_page'    => 10,
    'page'        => 1,
    'search'      => '',
    'filter_type' => 'simple',
    'sort_by'     => 'ID',
    'with'        => ['detail', 'variants'],
    'include_ids' => ['{product_id}'],
], 'resources/admin/elementor/SearchableSelect.vue', 30, ['needs' => 'product']);
$variation($productsId, 'Elementor variation selector search and scopes', [
    'active_view' => 'publish',
    'per_page'    => 10,
    'page'        => 1,
    'search'      => '__fc_smoke__',
    'filter_type' => 'simple',
    'sort_by'     => 'ID',
    'with'        => ['detail.variants.media', 'categories', 'variants.media'],
    'scopes'      => [],
], 'resources/admin/elementor/VariationSelector/SelectorModel.js', 56);

$declare($api, 78, 'products/get-bundle-info/{productId}', ['needs' => 'product']);
$declare($api, 86, 'products/fetch-term');
$declare($api, 89, 'products/searchProductByName', [
    'params' => ['name' => '__fc_smoke__', 'url_mode' => 'product', 'termId' => 0],
]);
$suggestSkuId = $declare($api, 93, 'products/suggest-sku', [
    'params' => ['title' => 'Smoke Product', 'variant_title' => '', 'exclude_id' => 0],
]);
$variation($suggestSkuId, 'variant title and excluded ID', [
    'title'         => 'Smoke Product',
    'variant_title' => 'Annual',
    'exclude_id'    => '{variation_id}',
], 'resources/admin/Modules/Products/parts/SkuInput.vue', 54, ['needs' => 'variation']);
$searchVariantId = $declare($api, 97, 'products/searchVariantByName', [
    'params' => ['name' => '__fc_smoke__', 'ids' => []],
]);
$variation($searchVariantId, 'report filter selected variation IDs', [
    'name' => '',
    'ids'  => ['{variation_id}'],
], 'resources/admin/Modules/Reports/Components/GlobalReportFilter.vue', 175, ['needs' => 'variation']);
$variantOptionsId = $declare($api, 101, 'products/search-product-variant-options', [
    'params' => ['search' => '', 'include_ids' => [], 'scopes' => []],
]);
$variation($variantOptionsId, 'bundle editor excludes bundle products', [
    'search'      => '__fc_smoke__',
    'include_ids' => ['{variation_id}'],
    'scopes'      => ['nonBundle'],
], 'resources/admin/Modules/Products/parts/ProductBundleSelector.vue', 97, ['needs' => 'variation']);
$declare($api, 105, 'products/findSubscriptionVariants', ['params' => ['name' => '__fc_smoke__']]);
$declare($api, 109, 'products/fetchProductsByIds', [
    'needs'  => 'variation',
    'params' => ['productIds' => '{productIds}'],
]);
$declare($api, 112, 'products/fetchVariationsByIds', [
    'needs'  => 'variation',
    'params' => ['productIds' => '{variationIds}'],
]);
$bulkId = $declare($api, 124, 'products/bulk-edit-data', [
    'params' => ['per_page' => 10, 'page' => 1],
]);
$variation($bulkId, 'constrained product and variation IDs', [
    'per_page'   => 10,
    'page'       => 1,
    'product_ids'=> ['{product_id}'],
    'variant_ids'=> ['{variation_id}'],
], 'resources/admin/Models/BulkEditModel.js', 224, ['needs' => 'variation']);
$variation($bulkId, 'advanced bulk filters', [
    'per_page'         => 10,
    'page'             => 1,
    'filter_type'      => 'advanced',
    'advanced_filters' => '[[]]',
], 'resources/admin/Models/BulkEditModel.js', 237);
$variation($bulkId, 'search a replacement product', [
    'search'   => '{product_id}',
    'per_page' => 1,
], 'resources/admin/Models/BulkEditModel.js', 511, ['needs' => 'product']);
$declare($api, 130, 'products/get-max-excerpt-word-count');
$declare($api, 133, 'products/{product}', ['needs' => 'product']);
$declare($api, 136, 'products/{productId}/related-products', ['needs' => 'product']);
$pricingId = $declare($api, 139, 'products/{productId}/pricing', ['needs' => 'product']);
$variation($pricingId, 'product editor menu relation', [
    'with' => ['product_menu'],
], 'resources/admin/Modules/Products/ProductRoute.vue', 53, ['needs' => 'product']);
$declare($api, 146, 'products/{id}/upgrade-paths', ['needs' => 'product']);
$declare($api, 159, 'products/variation/{variantId}/upgrade-paths', [
    'skip' => 'The admin consumer supplies live order context and this controller enters PlanUpgradeService payment-path eligibility; Phase 1 never touches a payment path.',
]);
$declare($api, 206, 'products/getDownloadableUrl/{downloadableId}', ['needs' => 'product_download']);
$declare($api, 210, 'products/{productId}/pricing-widgets', ['needs' => 'product']);
$declare($api, 281, 'variants');
$declare($api, 291, 'options/attr/groups/library');
$attributeGroupsId = $declare($api, 294, 'options/attr/groups', [
    'params' => [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'active_view' => 'all',
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ],
]);
foreach (['button', 'dropdown'] as $activeView) {
    $variation($attributeGroupsId, 'table active_view=' . $activeView, [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'active_view' => $activeView,
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ], 'resources/admin/utils/table-new/AttrGroupsTable.js', 12);
}
$variation($attributeGroupsId, 'table search and title ordering', [
    'per_page'    => 10,
    'page'        => 1,
    'sort_by'     => 'title',
    'sort_type'   => 'ASC',
    'active_view' => 'all',
    'filter_type' => 'simple',
    'search'      => '__fc_smoke__',
    'user_tz'     => 'UTC',
], 'resources/admin/utils/table-new/Table.js', 457);
$variation($attributeGroupsId, 'attribute editor pagination and search', [
    'per_page' => 20,
    'page'     => 2,
    'search'   => '__fc_smoke__',
], 'resources/admin/Modules/Attributes/AttrGroups.vue', 136);
$declare($api, 305, 'options/attr/group/{group_id}', ['needs' => 'attribute_group']);
$attributeTermsId = $declare($api, 314, 'options/attr/group/{group_id}/terms', [
    'needs'  => 'attribute_group',
    'params' => ['per_page' => 10, 'page' => 1],
]);
$variation($attributeTermsId, 'search terms with expanded page size', [
    'search'   => '__fc_smoke__',
    'per_page' => 50,
], 'resources/admin/Modules/Products/parts/AdvancedVariationConfig.vue', 285, ['needs' => 'attribute_group']);
$variation($attributeTermsId, 'serial ordering table request', [
    'per_page'    => 10,
    'page'        => 1,
    'sort_by'     => 'serial',
    'sort_type'   => 'ASC',
    'filter_type' => 'simple',
    'search'      => '',
    'user_tz'     => 'UTC',
], 'resources/admin/utils/table-new/AttrTermsTable.js', 12, ['needs' => 'attribute_group']);
$variation($attributeTermsId, 'attribute editor second page', [
    'per_page' => 20,
    'page'     => 2,
], 'resources/admin/Modules/Attributes/GroupTermsPanel.vue', 83, ['needs' => 'attribute_group']);

$declare($api, 334, 'integration/addons');
$declare($api, 338, 'integration/global-settings', [
    'skip' => 'The settings_key is extension-defined and its GET filters may validate remote credentials; no safe core key exists to invoke in Phase 1.',
]);
$declare($api, 346, 'integration/global-feeds');
$declare($api, 359, 'integration/global-feeds/settings', [
    'needs'  => 'product_integration',
    'params' => ['integration_id' => 0, 'integration_name' => '{integration_name}'],
]);
$declare($api, 368, 'integration/feed/lists', [
    'skip' => 'The route dispatches an extension-specific merge-field filter that may query a remote integration API; Phase 1 cannot safely choose an arbitrary provider.',
]);
$declare($api, 373, 'integration/feed/dynamic_options', [
    'params' => [
        'option_key'     => 'post_type',
        'sub_option_key' => 'post',
        'search'         => '__fc_smoke__',
        'values'         => [],
    ],
]);

$declare($api, 396, 'settings/payment-methods/paypal/webhook/check', [
    'skip' => 'PaymentMethodController::checkPayPalWebhook() calls Webhook::maybeSetWebhook(), which can contact PayPal and register a live webhook.',
]);
$declare($api, 405, 'settings/payment-methods', [
    'skip' => 'PaymentMethodController::getSettings() resolves a configured payment gateway; payment gateways are forbidden in every Phase 1 mode.',
]);
$declare($api, 411, 'settings/payment-methods/all', [
    'skip' => 'PaymentMethodController::index() enumerates registered payment gateways; payment gateways are forbidden in every Phase 1 mode.',
]);
$declare($api, 419, 'settings/payment-methods/connect/info', [
    'skip' => 'PaymentMethodController::connectInfo() instantiates a gateway and may initiate an account connection; payment gateways are forbidden.',
]);
$declare($api, 440, 'settings/permissions');
$storeSettingsId = $declare($api, 450, 'settings/store');
foreach ([
    ['store_setup', 450],
    ['pages_setup', 458],
    ['single_product_setup', 466],
    ['cart_and_checkout', 474],
    ['subscriptions_setup', 482],
] as $settingsRoute) {
    $variation($storeSettingsId, 'settings form ' . $settingsRoute[0], [
        'settings_name' => $settingsRoute[0],
    ], 'resources/admin/Modules/Settings/StoreSettings.vue', 202, [
        'settings_route_file' => 'resources/admin/routes.js',
        'settings_route_line' => $settingsRoute[1],
    ]);
}
$declare($api, 463, 'settings/modules/plugin-addons');
$declare($api, 475, 'settings/modules');
$declare($api, 483, 'settings/mcp');
$mcpId = $declare($api, 492, 'settings/mcp/config-snippets', ['params' => ['local_dev' => 'no']]);
$variation($mcpId, 'local development snippet', [
    'local_dev' => 'yes',
], 'resources/admin/Bits/Components/Form/Components/McpSettings.vue', 153);
$declare($api, 501, 'settings/confirmation/shortcode');
$declare($api, 506, 'settings/storage-drivers');
$declare($api, 512, 'settings/storage-drivers/active-drivers');
$declare($api, 515, 'settings/storage-drivers/{driver}', [
    'route' => 'settings/storage-drivers/local',
]);

$ordersId = $declare($api, 538, 'orders', [
    'params' => [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'with'        => ['customer.primary_billing_address', 'order_items'],
        'scopes'      => [],
        'active_view' => 'all',
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ],
]);
foreach (['completed', 'processing', 'on-hold', 'paid', 'subscription', 'renewal', 'refunded', 'partially_refunded', 'upgraded_from', 'upgraded_to'] as $activeView) {
    $variation($ordersId, 'table active_view=' . $activeView, [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'with'        => ['customer.primary_billing_address', 'order_items'],
        'scopes'      => [],
        'active_view' => $activeView,
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ], 'resources/admin/utils/table-new/OrderTable.js', 8);
}
$variation($ordersId, 'table search and total ordering', [
    'per_page'    => 10,
    'page'        => 1,
    'sort_by'     => 'total_amount',
    'sort_type'   => 'ASC',
    'with'        => ['customer.primary_billing_address', 'order_items'],
    'scopes'      => [],
    'active_view' => 'all',
    'filter_type' => 'simple',
    'search'      => '__fc_smoke__',
    'user_tz'     => 'UTC',
], 'resources/admin/utils/table-new/Table.js', 457);
$variation($ordersId, 'table advanced filters', [
    'per_page'         => 10,
    'page'             => 1,
    'sort_by'          => 'id',
    'sort_type'        => 'DESC',
    'with'             => ['customer.primary_billing_address', 'order_items'],
    'scopes'           => [],
    'active_view'      => 'all',
    'filter_type'      => 'advanced',
    'advanced_filters' => '[[]]',
    'user_tz'          => 'UTC',
], 'resources/admin/utils/table-new/Table.js', 457);
$orderDetailsId = $declare($api, 569, 'orders/{order_id}', ['needs' => 'order']);
$variation($orderDetailsId, 'single order widgets relation', [
    'with' => ['widgets'],
], 'resources/admin/Modules/Orders/SingleOrder.vue', 1619, ['needs' => 'order']);
$declare($api, 607, 'orders/{order}/transactions', ['needs' => 'order']);
$declare($api, 615, 'orders/{id}/transactions/{transaction_id}', ['needs' => 'order_transaction']);
$shippingMethodsId = $declare($api, 637, 'orders/shipping_methods', [
    'params' => ['country_code' => 'US', 'order_items' => []],
]);
$variation($shippingMethodsId, 'country and state selection', [
    'country_code' => 'US',
    'state'        => 'CA',
    'order_items'  => [],
], 'resources/admin/Modules/Orders/SingleOrder.vue', 1819);
$variation($shippingMethodsId, 'create-order item-aware shipping quote', [
    'country_code' => 'US',
    'order_items'  => [[
        'id'             => '{variation_id}',
        'quantity'       => 1,
        'unit_price'     => 0,
        'discount_total' => 0,
    ]],
], 'resources/admin/Modules/Orders/CreateOrder.vue', 715, ['needs' => 'variation']);
$renewalsId = $declare($api, 648, 'renewals', ['params' => ['page' => 1]]);
$variation($renewalsId, 'payment, parent, and customer filters', [
    'payment_status' => 'pending',
    'parent_id'      => '{order_id}',
    'customer_id'    => 0,
], 'app/Http/Controllers/RenewalController.php', 27, ['needs' => 'order']);
$declare($api, 652, 'renewals/{id}', ['needs' => 'renewal']);
$declare($api, 666, 'labels');

$customersId = $declare($api, 678, 'customers', [
    'params' => [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'with'        => [],
        'scopes'      => [],
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ],
]);
$variation($customersId, 'customer lookup search', [
    'search' => '__fc_smoke__',
], 'resources/admin/Modules/Orders/Components/ChangeOrderCustomer.vue', 49);
$variation($customersId, 'customer lookup with addresses', [
    'search' => '__fc_smoke__',
    'with'   => ['shipping_address', 'billing_address'],
], 'resources/admin/Modules/Orders/OrderCustomerInformation.vue', 86);
$variation($customersId, 'table lifetime-value ordering', [
    'per_page'    => 10,
    'page'        => 1,
    'sort_by'     => 'ltv',
    'sort_type'   => 'ASC',
    'with'        => [],
    'scopes'      => [],
    'filter_type' => 'simple',
    'search'      => '',
    'user_tz'     => 'UTC',
], 'resources/admin/utils/table-new/CustomerTable.js', 20);
$variation($customersId, 'table advanced filters', [
    'per_page'         => 10,
    'page'             => 1,
    'sort_by'          => 'id',
    'sort_type'        => 'DESC',
    'with'             => [],
    'scopes'           => [],
    'filter_type'      => 'advanced',
    'advanced_filters' => '[[]]',
    'user_tz'          => 'UTC',
], 'resources/admin/utils/table-new/Table.js', 457);
$declare($api, 686, 'customers/get-stats/{customer}', ['needs' => 'customer']);
$declare($api, 690, 'customers/attachable-user');
$customerDetailsId = $declare($api, 702, 'customers/{customerId}', ['needs' => 'customer']);
$variation($customerDetailsId, 'single customer relations', [
    'with' => ['shipping_address', 'billing_address', 'labels', 'subscriptions'],
], 'resources/admin/Modules/Customers/SingleCustomer.vue', 274, ['needs' => 'customer']);
$declare($api, 706, 'customers/{customerId}/order', ['needs' => 'customer']);
$customerOrdersId = $declare($api, 722, 'customers/{customerId}/orders', ['needs' => 'customer']);
$variation($customerOrdersId, 'customer orders pagination/search/order', [
    'per_page'  => 10,
    'page'      => 1,
    'with'      => ['order_items'],
    'search'    => '__fc_smoke__',
    'order_by'  => 'id',
    'order_type'=> 'DESC',
], 'resources/admin/Modules/Customers/SingleCustomer.vue', 307, ['needs' => 'customer']);
$customerAddressId = $declare($api, 726, 'customers/{customerId}/address', ['needs' => 'customer']);
$variation($customerAddressId, 'billing address type', [
    'type' => 'billing',
], 'resources/admin/Modules/Customers/Modals/AddOrEditAddressModal.vue', 296, ['needs' => 'customer']);
$variation($customerAddressId, 'shipping address type', [
    'type' => 'shipping',
], 'resources/admin/Modules/Customers/Modals/ManageOrderAddressModal.vue', 199, ['needs' => 'customer']);

$declare($api, 755, 'onboarding');
$declare($api, 762, 'email-notification');
$declare($api, 763, 'email-notification/get-short-codes');
$declare($api, 764, 'email-notification/get-settings');
$declare($api, 766, 'email-notification/reminders');
$declare($api, 768, 'email-notification/digest-settings');
$declare($api, 774, 'email-notification/{notification}', ['needs' => 'notification']);
$declare($api, 780, 'templates/print-templates');
$declare($api, 786, 'coupons/listCoupons');
$couponsId = $declare($api, 790, 'coupons', [
    'params' => [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'with'        => ['appliedCouponsCount'],
        'scopes'      => [],
        'active_view' => 'all',
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ],
]);
foreach (['active', 'expired'] as $activeView) {
    $variation($couponsId, 'table active_view=' . $activeView, [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'with'        => ['appliedCouponsCount'],
        'scopes'      => [],
        'active_view' => $activeView,
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ], 'resources/admin/utils/table-new/CouponTable.js', 8);
}
$variation($couponsId, 'table code search and expiry ordering', [
    'per_page'    => 10,
    'page'        => 1,
    'sort_by'     => 'expiry_date',
    'sort_type'   => 'ASC',
    'with'        => ['appliedCouponsCount'],
    'scopes'      => [],
    'active_view' => 'all',
    'filter_type' => 'simple',
    'search'      => '__fc_smoke__',
    'user_tz'     => 'UTC',
], 'resources/admin/utils/table-new/Table.js', 457);
$declare($api, 793, 'coupons/getSettings');
$declare($api, 796, 'coupons/{id}', ['needs' => 'coupon']);
$filesId = $declare($api, 830, 'files', [
    'needs'  => 'local_storage',
    'params' => ['driver' => '{driver}', 'search' => ''],
]);
$variation($filesId, 'storage file search', [
    'driver' => '{driver}',
    'search' => '__fc_smoke__',
], 'resources/admin/Bits/Components/DownloadableFileSelector/StorageDriverModel.js', 110, [
    'needs' => 'local_storage',
]);
$declare($api, 832, 'files/bucket-list', [
    'needs'  => 'local_storage',
    'params' => ['driver' => '{driver}'],
]);
$declare($api, 843, 'app/init');
$declare($api, 844, 'app/attachments', ['ok' => [200, 423]]);

$activityId = $declare($api, 849, 'activity', [
    'params' => [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'active_view' => 'all',
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ],
]);
foreach (['success', 'warning', 'error', 'failed', 'info', 'api'] as $activeView) {
    $variation($activityId, 'table active_view=' . $activeView, [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'active_view' => $activeView,
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ], 'resources/admin/utils/table-new/LogTable.js', 8);
}
$variation($activityId, 'table content search and date ordering', [
    'per_page'    => 10,
    'page'        => 1,
    'sort_by'     => 'created_at',
    'sort_type'   => 'ASC',
    'active_view' => 'all',
    'filter_type' => 'simple',
    'search'      => '__fc_smoke__',
    'user_tz'     => 'UTC',
], 'resources/admin/utils/table-new/Table.js', 457);

$taxesId = $declare($api, 855, 'taxes', [
    'params' => [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'with'        => ['tax_rate', 'order:id,currency'],
        'scopes'      => ['validOrder'],
        'active_view' => 'all',
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ],
]);
foreach (['filed', 'not_filed'] as $activeView) {
    $variation($taxesId, 'table active_view=' . $activeView, [
        'per_page'    => 10,
        'page'        => 1,
        'sort_by'     => 'id',
        'sort_type'   => 'DESC',
        'with'        => ['tax_rate', 'order:id,currency'],
        'scopes'      => ['validOrder'],
        'active_view' => $activeView,
        'filter_type' => 'simple',
        'search'      => '',
        'user_tz'     => 'UTC',
    ], 'resources/admin/utils/table-new/TaxesTable.js', 8);
}
$variation($taxesId, 'table country ordering and advanced filter', [
    'per_page'         => 10,
    'page'             => 1,
    'sort_by'          => 'country',
    'sort_type'        => 'ASC',
    'with'             => ['tax_rate', 'order:id,currency'],
    'scopes'           => ['validOrder'],
    'active_view'      => 'all',
    'filter_type'      => 'advanced',
    'advanced_filters' => '[[]]',
    'user_tz'          => 'UTC',
], 'resources/admin/utils/table-new/Table.js', 457);

$declare($api, 860, 'address-info/countries');
$countryInfoId = $declare($api, 861, 'address-info/get-country-info', [
    'params' => ['country_code' => 'US'],
]);
$variation($countryInfoId, 'store/customer address country selection', [
    'country_code' => 'GB',
], 'resources/admin/Bits/Components/Address/AddressComponent.vue', 55);
$declare($api, 867, 'products/{product_id}/integrations/{integration_name}/settings', [
    'needs' => 'product_integration',
]);
$declare($api, 883, 'products/{productId}/integrations', ['needs' => 'product']);
$declare($api, 893, 'tax/classes');
$declare($api, 904, 'tax/rates');
$taxCountryRatesId = $declare($api, 907, 'tax/rates/country/rates/{country_code}', [
    'route' => 'tax/rates/country/rates/US',
]);
$variation($taxCountryRatesId, 'tax class rate selection', [
    'class_id' => 0,
], 'resources/admin/Modules/Tax/Components/EUVatTaxOverrideModal.vue', 137, [
    'route' => 'tax/rates/country/rates/US',
]);
$declare($api, 928, 'tax/country-tax-id/{country_code}', [
    'route' => 'tax/country-tax-id/US',
]);
$declare($api, 936, 'tax/product-overrides/{country_code}', [
    'route' => 'tax/product-overrides/US',
]);
$declare($api, 949, 'tax/configuration/rates');
$declare($api, 955, 'tax/configuration/settings');
$declare($api, 967, 'tax/configuration/settings/eu-vat/product-overrides');
$declare($api, 970, 'tax/configuration/settings/eu-vat/oss-rates');
$declare($api, 979, 'checkout-fields/get-fields');

$reports = 'app/Http/Routes/reports.php';
$reportParams = [
    'params' => [
        'orderStatus'     => ['all'],
        'paymentStatus'   => ['all'],
        'currency'        => '{report_currency}',
        'startDate'       => '{report_start}',
        'endDate'         => '{report_end}',
        'rangeKey'        => 'Custom',
        'variation_ids'   => [],
        'filterMode'      => '',
        'storeMode'       => '',
        'firstOrderDate'  => '{report_start}',
        'compareType'     => 'previous_period',
        'compareDate'     => '{report_start}',
        'subscriptionType'=> '',
    ],
];
$reportCase = function ($line, $route, array $extra = []) use ($declare, $reports, $reportParams) {
    return $declare($reports, $line, 'reports/' . $route, array_merge([
        'needs'  => 'report',
        'params' => $reportParams,
    ], $extra));
};
$withReportGroup = function (array $params, $groupKey) {
    $params['params']['groupKey'] = $groupKey;
    return $params;
};

$declare($reports, 27, 'reports/overview');
$reportMetaId = $reportCase(30, 'fetch-report-meta');
$variation($reportMetaId, 'order type/status/date/currency filter state', [
    'params' => [
        'orderStatus'     => ['completed'],
        'paymentStatus'   => ['paid'],
        'orderTypes'      => ['subscription', 'renewal'],
        'currency'        => '{report_currency}',
        'startDate'       => '{report_start}',
        'endDate'         => '{report_end}',
        'rangeKey'        => 'Custom',
        'variation_ids'   => ['{variation_id}'],
        'filterMode'      => 'advanced',
        'storeMode'       => '',
        'firstOrderDate'  => '{report_start}',
        'compareType'     => 'no_comparison',
        'compareDate'     => '{report_start}',
        'subscriptionType'=> 'automatic',
    ],
], 'resources/admin/Models/Reports/ReportFilterModel.js', 145, ['needs' => ['report', 'variation']]);
$reportCase(31, 'quick-order-stats');
$reportCase(32, 'report-overview');
$declare($reports, 33, 'reports/search-repeat-customer', [
    'needs'  => 'report',
    'params' => [
        'params' => [
            'search'        => '',
            'order_status'  => 'completed',
            'per_page'      => 10,
            'current_page'  => 1,
            'created_at'    => [
                'column'   => 'created_at',
                'operator' => 'between',
                'value'    => ['{report_start}', '{report_end}'],
            ],
        ],
    ],
]);
$reportCase(34, 'top-products-sold');

$revenueId = $reportCase(37, 'revenue', [
    'params' => $withReportGroup($reportParams, 'default'),
]);
foreach (['monthly', 'yearly'] as $groupKey) {
    $variation($revenueId, 'chart groupKey=' . $groupKey, $withReportGroup($reportParams, $groupKey), 'resources/admin/Modules/Reports/Revenue/RevenueLineChart.vue', 112, ['needs' => 'report']);
}
$revenueGroupId = $reportCase(38, 'revenue-by-group', [
    'params' => $withReportGroup($reportParams, 'billing_country'),
]);
foreach (['shipping_country', 'payment_method'] as $groupKey) {
    $variation($revenueGroupId, 'dimension groupKey=' . $groupKey, $withReportGroup($reportParams, $groupKey), 'resources/admin/Modules/Reports/Revenue/RevenueGroupedBy.vue', 30, ['needs' => 'report']);
}

$reportCase(41, 'order-value-distribution');
$reportCase(42, 'fetch-new-vs-returning-customer');
$orderGroupId = $reportCase(43, 'fetch-order-by-group', [
    'params' => $withReportGroup($reportParams, 'billing_country'),
]);
foreach (['shipping_country', 'payment_method'] as $groupKey) {
    $variation($orderGroupId, 'dimension groupKey=' . $groupKey, $withReportGroup($reportParams, $groupKey), 'resources/admin/Modules/Reports/Order/OrderGroupedBy.vue', 36, ['needs' => 'report']);
}
$reportCase(44, 'fetch-report-by-day-and-hour');
$reportCase(45, 'item-count-distribution');
$reportCase(46, 'order-completion-time');
$orderChartId = $reportCase(47, 'order-chart', [
    'params' => $withReportGroup($reportParams, 'default'),
]);
foreach (['monthly', 'yearly'] as $groupKey) {
    $variation($orderChartId, 'chart groupKey=' . $groupKey, $withReportGroup($reportParams, $groupKey), 'resources/admin/Modules/Reports/Order/OrderLineChart.vue', 57, ['needs' => 'report']);
}

$reportCase(50, 'sales-report');
$reportCase(52, 'fetch-top-sold-products');
$reportCase(53, 'fetch-top-sold-variants');
$refundChartId = $reportCase(65, 'refund-chart', [
    'params' => $withReportGroup($reportParams, 'default'),
]);
foreach (['monthly', 'yearly'] as $groupKey) {
    $variation($refundChartId, 'chart groupKey=' . $groupKey, $withReportGroup($reportParams, $groupKey), 'resources/admin/Modules/Reports/Refund/RefundLineChart.vue', 66, ['needs' => 'report']);
}
$reportCase(66, 'weeks-between-refund');
$refundGroupId = $reportCase(67, 'refund-data-by-group', [
    'params' => $withReportGroup($reportParams, 'billing_country'),
]);
foreach (['shipping_country', 'payment_method'] as $groupKey) {
    $variation($refundGroupId, 'dimension groupKey=' . $groupKey, $withReportGroup($reportParams, $groupKey), 'resources/admin/Modules/Reports/Refund/RefundTable.vue', 80, ['needs' => 'report']);
}

$reportCase(70, 'license-chart');
$reportCase(71, 'license-pie-chart');
$reportCase(72, 'license-summary');
$reportCase(75, 'dashboard-stats');
$reportCase(76, 'sales-growth-chart');
$reportCase(77, 'country-heat-map');
$reportCase(78, 'get-recent-orders');
$recentActivityId = $reportCase(79, 'get-recent-activities');
$variation($recentActivityId, 'dashboard daily activity grouping', $withReportGroup($reportParams, 'daily'), 'resources/admin/Pages/Dashboard/Components/RecentActivity.vue', 54, ['needs' => 'report']);
$reportCase(80, 'get-dashboard-summary');

$subscriptionChartId = $reportCase(82, 'subscription-chart', [
    'params' => $withReportGroup($reportParams, 'default'),
]);
foreach (['monthly', 'yearly'] as $groupKey) {
    $variation($subscriptionChartId, 'chart groupKey=' . $groupKey, $withReportGroup($reportParams, $groupKey), 'resources/admin/Modules/Reports/Subscription/SubscriptionChartNew.vue', 113, ['needs' => 'report']);
}
$reportCase(83, 'daily-signups');
$reportCase(84, 'retention-chart');
$reportCase(85, 'future-renewals', [
    'params' => $withReportGroup($reportParams, 'monthly'),
]);
$reportCase(86, 'subscription-retention', [
    'params' => $withReportGroup($reportParams, 'monthly'),
]);
$retentionKnownFailure = [
    'known_failure' => [
        'db_match' => "fct_retention_snapshots' doesn't exist",
        'reason'   => 'The upgraded local install lacks the configured fct_retention_snapshots table; SubscriptionReportService queries it unconditionally at app/Services/Report/SubscriptionReportService.php:364/443.',
    ],
];
$cohortId = $reportCase(87, 'subscription-cohorts', array_merge([
    'params' => array_replace_recursive($reportParams, [
        'params' => ['groupBy' => 'year', 'metric' => 'subscribers'],
    ]),
], $retentionKnownFailure));
foreach ([
    ['year', 'mrr'],
    ['month', 'subscribers'],
    ['month', 'mrr'],
] as $cohortVariation) {
    $variation(
        $cohortId,
        'cohort groupBy=' . $cohortVariation[0] . ' metric=' . $cohortVariation[1],
        array_replace_recursive($reportParams, [
            'params' => ['groupBy' => $cohortVariation[0], 'metric' => $cohortVariation[1]],
        ]),
        'resources/admin/Modules/Reports/Subscription/Cohort.vue',
        458,
        array_merge(['needs' => 'report'], $retentionKnownFailure)
    );
}
$retentionStatusId = $declare($reports, 89, 'reports/retention-snapshots/status', [
    'params' => ['params' => ['job_id' => '__fc_smoke_missing_job__']],
]);
$variation($retentionStatusId, 'polling status query shape', [
    'params' => ['job_id' => '__fc_smoke_missing_job__'],
], 'resources/admin/Modules/Reports/Subscription/Cohort.vue', 719);

$productReportId = $reportCase(92, 'product-report', [
    'params' => $withReportGroup($reportParams, 'default'),
]);
foreach (['monthly', 'yearly'] as $groupKey) {
    $variation($productReportId, 'chart groupKey=' . $groupKey, $withReportGroup($reportParams, $groupKey), 'resources/admin/Modules/Reports/Product/ProductReportChart.vue', 106, ['needs' => 'report']);
}
$productPerformanceId = $reportCase(93, 'product-performance');
$variation($productPerformanceId, 'product performance pagination and ordering', [
    'params'   => $reportParams['params'],
    'page'     => 1,
    'per_page' => 10,
    'sort_by'  => 'gross_sale',
    'sort_type'=> 'DESC',
    'search'   => '__fc_smoke__',
], 'resources/admin/Modules/Reports/Product/ProductTopChart.vue', 397, ['needs' => 'report']);
$customerReportId = $reportCase(96, 'customer-report', [
    'params' => $withReportGroup($reportParams, 'default'),
]);
foreach (['monthly', 'yearly'] as $groupKey) {
    $variation($customerReportId, 'chart groupKey=' . $groupKey, $withReportGroup($reportParams, $groupKey), 'resources/admin/Modules/Reports/Customer/CustomerReportChart.vue', 112, ['needs' => 'report']);
}
$reportCase(99, 'sources');

$advance = 'app/Http/Routes/advance_filter_routes.php';
$advanceFilterId = $declare($advance, 22, 'advance_filter/get-filter-options', [
    'params' => ['remote_data_key' => 'attr_groups'],
]);
$variation($advanceFilterId, 'attribute terms cascading selection', [
    'remote_data_key' => 'attr_terms',
    'parent_id'       => '{group_id}',
], 'resources/admin/Bits/Components/TableNew/Components/AdvancedFilter/FilterItems/CascadingSelect.vue', 61, ['needs' => 'attribute_group']);
$variation($advanceFilterId, 'remote tree search/include/limit', [
    'search'          => '__fc_smoke__',
    'remote_data_key' => 'product_variations',
    'include_ids'     => ['{variation_id}'],
    'limit'           => 20,
], 'resources/admin/Bits/Components/TableNew/Components/AdvancedFilter/FilterItems/RemoteTreeSelect.vue', 31, ['needs' => 'variation']);
$declare($advance, 25, 'forms/search_options', [
    'params' => ['search_by' => '__fc_smoke__', 'search_for' => 'store_country'],
]);

$declare($api, 985, 'reviews', [
    'params' => ['per_page' => 10, 'page' => 1],
]);
$declare($api, 988, 'reviews/stats');
$declare($api, 991, 'reviews/{id}', ['needs' => 'review']);

$frontend = 'app/Http/Routes/frontend_routes.php';
$publicProductsId = $declare($frontend, 21, 'public/products', [
    'auth'   => 'anonymous',
    'params' => [
        'with'              => ['licensesMeta', 'detail', 'variants', 'categories'],
        'filters'           => [],
        'default_filters'   => [],
        'per_page'          => 10,
        'price_format'      => 'starts_from',
        'order_type'        => 'DESC',
        'template_provider' => '',
    ],
]);
$variation($publicProductsId, 'shortcode include/exclude/type/sale filters', [
    'with'             => ['licensesMeta', 'detail', 'variants', 'categories'],
    'filters'          => ['wildcard' => '__fc_smoke__'],
    'default_filters'  => ['sort_by' => 'latest'],
    'per_page'         => 10,
    'price_format'     => 'starts_from',
    'order_type'       => 'ASC',
    'include_ids'      => ['{product_id}'],
    'exclude_ids'      => [],
    'product_type'     => 'digital',
    'on_sale'          => 1,
    'hide_excerpt'     => 1,
    'allow_out_of_stock'=> true,
], 'resources/public/product-page/Paginator/Paginator.js', 228, ['needs' => 'product', 'auth' => 'anonymous']);
$publicViewsId = $declare($frontend, 22, 'public/product-views', [
    'auth'   => 'anonymous',
    'params' => [
        'current_page'      => 1,
        'per_page'          => 10,
        'search'            => '',
        'with'              => ['licensesMeta', 'detail', 'variants', 'categories'],
        'filters'           => [],
        'default_filters'   => [],
        'price_format'      => 'starts_from',
        'order_type'        => 'DESC',
        'template_provider' => '',
    ],
]);
$variation($publicViewsId, 'filtered product-card rendering', [
    'current_page'      => 1,
    'per_page'          => 5,
    'search'            => '',
    'with'              => ['licensesMeta', 'detail', 'variants', 'categories'],
    'filters'           => ['wildcard' => '__fc_smoke__'],
    'default_filters'   => ['sort_by' => 'price_low_to_high'],
    'price_format'      => 'range',
    'order_type'        => 'ASC',
    'template_provider' => '',
    'include_ids'       => ['{product_id}'],
    'hide_excerpt'      => 1,
], 'resources/public/product-page/Paginator/Paginator.js', 228, ['needs' => 'product', 'auth' => 'anonymous']);
$variation($publicViewsId, 'transient/template-provider product rendering', [
    'current_page'      => 1,
    'per_page'          => 10,
    'client_id'         => '{client_id}',
    'template_provider' => '{template_provider}',
], 'resources/public/product-page/Paginator/Paginator.js', 243, [
    'auth' => 'anonymous',
    'skip' => 'Non-empty client_id/template_provider values come from live rendered DOM and extension-owned preload filters; Phase 1 has no safe static value and does not invoke an arbitrary extension renderer.',
]);
// Dispatchable since ShopController::searchProduct() switched from
// Response::json() (wp_send_json hard-die) to a proper WP_REST_Response.
$declare($frontend, 23, 'public/product-search', [
    'auth'   => 'anonymous',
    'params' => ['post_title' => ''],
]);

$declare($frontend, 38, 'checkout/get-order-info', [
    'auth' => 'anonymous',
    'skip' => 'CheckoutController::getOrderInfo() resolves the requested payment gateway and calls its order-info handler; all gateway invocation is forbidden.',
]);
$declare($frontend, 39, 'checkout/get-checkout-summary-view', [
    'auth'   => 'anonymous',
    'needs'  => 'cart',
    'params' => ['fct_cart_hash' => '{fct_cart_hash}', 'shipping_method_id' => 0],
]);
$availableShippingId = $declare($frontend, 40, 'checkout/get-available-shipping-methods', [
    'auth'   => 'anonymous',
    'params' => ['country_code' => 'US', 'state' => 'CA'],
]);
$variation($availableShippingId, 'timezone country inference', [
    'timezone' => 'America/New_York',
    'state'    => 'NY',
], 'app/Modules/Shipping/Http/Controllers/Frontend/ShippingFrontendController.php', 17, ['auth' => 'anonymous']);
$shippingListId = $declare($frontend, 41, 'checkout/get-shipping-methods-list-view', [
    'auth'   => 'anonymous',
    'params' => ['country_code' => 'US', 'state' => 'CA'],
]);
$variation($shippingListId, 'timezone-rendered method list', [
    'timezone' => 'Europe/London',
], 'app/Modules/Shipping/Http/Controllers/Frontend/ShippingFrontendController.php', 99, ['auth' => 'anonymous']);
$checkoutCountryId = $declare($frontend, 42, 'checkout/get-country-info', [
    'auth'   => 'anonymous',
    'params' => ['country_code' => 'US'],
]);
$variation($checkoutCountryId, 'timezone country information', [
    'timezone' => 'Europe/London',
], 'app/Modules/Shipping/Http/Controllers/Frontend/ShippingFrontendController.php', 125, ['auth' => 'anonymous']);

// The frontend GET customers/{customerId} and customers/{customerId}/orders
// declarations were removed with their routes: they duplicated the admin
// customers group at identical paths and were never dispatchable (the admin
// registration wins per path+method). Customer self-service reads live under
// customer-profile below.
$declare($frontend, 59, 'customers/{customerAddressId}/update-address-select', [
    'auth' => 'customer',
    'skip' => 'Despite GET, CustomerController::updateAddressSelect() writes checkout_data and saves the cart at app/Http/Controllers/FrontendControllers/CustomerController.php:107-125.',
]);

$declare($frontend, 64, 'customer-profile', [
    'auth'  => 'customer',
    'needs' => 'customer_context',
]);
$downloadsId = $declare($frontend, 65, 'customer-profile/downloads', [
    'auth'   => 'customer',
    'needs'  => 'customer_context',
    'params' => ['per_page' => 10, 'page' => 1],
]);
$variation($downloadsId, 'download pagination', [
    'per_page' => 5,
    'page'     => 2,
], 'resources/public/customer-profile/Vue/Downloads.vue', 86, [
    'auth'  => 'customer',
    'needs' => 'customer_context',
]);
$declare($frontend, 67, 'customer-profile/profile', [
    'auth'  => 'customer',
    'needs' => 'customer_context',
]);
// The add-on section extension point. `filter` is required and allowlisted
// against CustomerProfileController::PORTAL_SECTION_FILTERS; an unlisted value
// is a 422 rather than an arbitrary hook name built from caller input.
$profileSectionsId = $declare($frontend, 68, 'customer-profile/sections', [
    'auth'   => 'customer',
    'needs'  => 'customer_context',
    'params' => ['filter' => 'profile_sections'],
]);
$variation(
    $profileSectionsId,
    'profile_sections group as the portal sends it',
    ['filter' => 'profile_sections'],
    'resources/public/customer-profile/Vue/parts/PortalSections.vue',
    34,
    ['auth' => 'customer', 'needs' => 'customer_context']
);
$variation(
    $profileSectionsId,
    'unlisted section group is refused',
    ['filter' => 'fct-unlisted-section-group'],
    'resources/public/customer-profile/Vue/parts/PortalSections.vue',
    34,
    ['auth' => 'customer', 'needs' => 'customer_context', 'ok' => [422]]
);
$profileOrdersId = $declare($frontend, 79, 'customer-profile/orders', [
    'auth'   => 'customer',
    'needs'  => 'customer_context',
    'params' => ['per_page' => 10, 'page' => 1, 'search' => ''],
]);
$variation($profileOrdersId, 'purchase-history search and pagination', [
    'per_page' => 10,
    'page'     => 1,
    'search'   => '__fc_smoke__',
], 'resources/public/customer-profile/Vue/PurchaseHistory.vue', 127, [
    'auth'  => 'customer',
    'needs' => 'customer_context',
]);
$declare($frontend, 80, 'customer-profile/orders/{order_uuid}', [
    'auth'  => 'customer',
    'needs' => 'customer_order',
]);
$declare($frontend, 81, 'customer-profile/orders/{order_uuid}/upgrade-paths', [
    'auth'   => 'customer',
    'needs'  => 'customer_upgrade',
    'params' => ['variation_id' => '{variation_id}'],
]);
$declare($frontend, 84, 'customer-profile/orders/{transaction_uuid}/billing-address', [
    'auth'  => 'customer',
    'needs' => 'customer_transaction',
]);
$declare($frontend, 89, 'customer-profile/subscriptions', [
    'auth' => 'customer',
    'skip' => 'The list transforms each subscription through OrderService::transformSubscription(), which calls Subscription gateway capability methods at app/Services/OrderService.php:623-624.',
]);
$declare($frontend, 90, 'customer-profile/subscriptions/{subscription_uuid}', [
    'auth' => 'customer',
    'skip' => 'The detail calls canUpdatePaymentMethod(), canSwitchPaymentMethod(), switchablePaymentMethods(), canPause(), and canResume(), each resolving a payment gateway.',
]);
$declare($frontend, 91, 'customer-profile/subscriptions/{subscription_uuid}/setup-intent-attempts', [
    'auth' => 'customer',
    'skip' => 'The controller constructs Stripe SubscriptionsManager and checks a vendor-customer rate limit; this is a payment-gateway path and may reach Stripe.',
]);

$declare($frontend, 106, 'public/reviews/{postId}', [
    'auth'  => 'anonymous',
    'needs' => 'product',
    'params' => ['page' => 1],
]);
$declare($frontend, 107, 'public/reviews/{postId}/summary', [
    'auth'  => 'anonymous',
    'needs' => 'product',
]);
$declare($frontend, 110, 'public/reviews/{postId}/{reviewId}/replies', [
    'auth'  => 'anonymous',
    'needs' => 'review',
]);

$web = 'app/Http/Routes/WebRoutes.php';
$declare($web, 86, '?fluent-cart=instant_checkout', [
    'transport' => 'web',
    'auth'      => 'anonymous',
    'skip'      => 'Web-only handler creates and persists an instant-checkout cart, may apply coupons, redirects, and exits; Phase 1 is read-only and forbids real HTTP.',
]);
$declare($web, 249, '?fluent-cart=fluent_cart_payment_authenticate', [
    'transport' => 'web',
    'auth'      => 'anonymous',
    'skip'      => 'Web-only PayPal partner authentication renderer is a live payment-gateway surface and cannot be invoked in any Phase 1 mode.',
]);
$declare($web, 254, '?fluent-cart=download-by-id', [
    'transport' => 'web',
    'auth'      => 'anonymous',
    'skip'      => 'Binary/download web handler requires real request headers, authorization token, and exit semantics; loopback HTTP is forbidden.',
]);
$declare($web, 255, '?fluent-cart=download-file', [
    'transport' => 'web',
    'auth'      => 'anonymous',
    'skip'      => 'Binary/download web handler requires real request headers, authorization token, and exit semantics; loopback HTTP is forbidden.',
]);
$declare($web, 260, '?fluent-cart=receipt', [
    'transport' => 'web',
    'auth'      => 'anonymous',
    'skip'      => 'Receipt web handler renders a full document and terminates via WebRoutes::registerRoutes(); it is not an in-process REST route and loopback HTTP is forbidden.',
]);
foreach ([
    288 => 'print-invoice',
    291 => 'print-packing-slip',
    294 => 'print-delivery-slip',
    297 => 'print-shipping-slip',
    300 => 'print-dispatch-slip',
] as $line => $page) {
    $declare($web, $line, '?fluent-cart=' . $page, [
        'transport' => 'web',
        'auth'      => 'admin',
        'skip'      => 'Authenticated print web handler writes document output and relies on full web/exit semantics; no real or loopback HTTP is allowed in Phase 1.',
    ]);
}
$declare($web, 303, '?fluent-cart=modal_checkout', [
    'transport' => 'web',
    'auth'      => 'anonymous',
    'skip'      => 'Web-only modal checkout generates and persists a cart, renders a full document, and dies; Phase 1 is read-only and forbids real HTTP.',
]);

$faker = 'app/Http/Routes/FakerRoutes.php';
$declare($faker, 23, '?fluent-cart=migrate-fresh', [
    'transport' => 'faker',
    'auth'      => 'admin',
    'skip'      => 'Dangerous faker route executes migrate_fresh and can drop/recreate plugin tables and optionally seed data.',
]);
$declare($faker, 27, '?fluent-cart=migrate', [
    'transport' => 'faker',
    'auth'      => 'admin',
    'skip'      => 'Dangerous faker route executes database migrations and can mutate schema/data.',
]);
$declare($faker, 33, '?fluent-cart=seed', [
    'transport' => 'faker',
    'auth'      => 'admin',
    'skip'      => 'Dangerous faker route performs bulk data generation (default batch 1000) and must never be used for fixtures.',
]);

return [
    'route_files'  => $config['routes_files'],
    'declarations' => array_values($declarations),
    'cases'        => $cases,
];
