<?php
/**
 * Per-plugin test suite configuration.
 *
 * This is the ONLY configuration file that contains plugin-specific values.
 * Everything else in tests/ reads from here, so porting the suite to another
 * WPFluent plugin means editing this file.
 *
 * Fill it in with reference/BOOTSTRAP.md step 1.
 */

return [
    // Plugin identity ------------------------------------------------------
    'plugin_slug'         => 'fluent-cart',
    'app_namespace'       => 'FluentCart\\App',
    'framework_namespace' => 'FluentCart\\Framework',

    // Substring used to decide whether a PHP notice/warning belongs to US.
    // Without it, sibling plugins make the diagnostics-as-failures rule useless.
    'plugin_dir_hint' => 'plugins/fluent-cart',

    // REST -----------------------------------------------------------------
    // Prefixed to every route in the smoke manifest.
    'rest_namespace' => 'fluent-cart/v2',
    'routes_file'    => 'app/Http/Routes/api.php',
    'routes_files'   => [
        'app/Http/Routes/api.php',
        'app/Http/Routes/routes.php',
        'app/Http/Routes/reports.php',
        'app/Http/Routes/index.php',
        'app/Http/Routes/advance_filter_routes.php',
        'app/Http/Routes/frontend_routes.php',
        'app/Http/Routes/WebRoutes.php',
        'app/Http/Routes/FakerRoutes.php',
    ],
    'route_file_types' => [
        'app/Http/Routes/api.php'                   => 'rest',
        'app/Http/Routes/routes.php'                => 'rest',
        'app/Http/Routes/reports.php'               => 'rest',
        'app/Http/Routes/index.php'                 => 'rest',
        'app/Http/Routes/advance_filter_routes.php' => 'rest',
        'app/Http/Routes/frontend_routes.php'       => 'rest',
        'app/Http/Routes/WebRoutes.php'             => 'web',
        'app/Http/Routes/FakerRoutes.php'           => 'faker',
    ],

    // Route containers that must never be used as fixture helpers.
    'dangerous_routes_files' => [
        'app/Http/Routes/FakerRoutes.php',
    ],

    // Database -------------------------------------------------------------
    // The plugin's own table prefix, WITHOUT the WordPress prefix.
    // Also the regex in lint/raw-sql-prefix.php.
    'table_prefix' => 'fct_',

    // Captured at each tier's run start and compared at tier end; any change
    // is a hard failure. Counts are deliberately not pinned to one install.
    'protected_tables' => ['fct_orders', 'fct_customers'],

    // Real-model integration fixtures -------------------------------------
    // The helper owns rows by immediately captured primary IDs plus exact
    // identity markers. Identity markers are assertions only, never broad
    // mutation selectors.
    'integration_fixture' => [
        'model_class'     => 'FluentCart\\App\\Models\\Customer',
        'table'           => 'fct_customers',
        'identity_column' => 'email',
        'identity_domain' => 'example.invalid',
        'order'           => [
            'model_class'     => 'FluentCart\\App\\Models\\Order',
            'resource_class'  => 'FluentCart\\Api\\Resource\\OrderResource',
            'table'           => 'fct_orders',
            'identity_column' => 'note',
            'identity_prefix' => 'Owned Phase Five order fixture ',
            'activity_table'  => 'fct_activity',
            'related_rows'    => [
                [
                    'table'       => 'fct_activity',
                    'foreign_key' => 'module_id',
                    'where'       => [
                        'module_type' => 'FluentCart\\App\\Models\\Order',
                    ],
                ],
                ['table' => 'fct_order_meta', 'foreign_key' => 'order_id'],
                ['table' => 'fct_order_operations', 'foreign_key' => 'order_id'],
                ['table' => 'fct_order_addresses', 'foreign_key' => 'order_id'],
                ['table' => 'fct_order_items', 'foreign_key' => 'order_id'],
                ['table' => 'fct_order_transactions', 'foreign_key' => 'order_id'],
                ['table' => 'fct_order_tax_rate', 'foreign_key' => 'order_id'],
                ['table' => 'fct_applied_coupons', 'foreign_key' => 'order_id'],
                ['table' => 'fct_order_download_permissions', 'foreign_key' => 'order_id'],
                ['table' => 'fct_subscriptions', 'foreign_key' => 'parent_order_id'],
                ['table' => 'fct_carts', 'foreign_key' => 'order_id'],
                ['table' => 'fct_licenses', 'foreign_key' => 'order_id'],
                [
                    'table'       => 'fct_scheduled_actions',
                    'foreign_key' => 'object_id',
                    'where'       => [
                        'object_type' => 'order',
                    ],
                ],
            ],
            'status_hook_allowlist' => [
                'fluent_cart/order_status_changed_to_completed' => [],
                'fluent_cart/order_status_changed' => [
                    'FluentCart\\App\\Modules\\StockManagement\\StockManagement::manageStockOnOrderStatusChanged',
                ],
            ],
        ],
        'reports'         => [
            'window' => [
                'start'          => '2001-02-03 00:00:00',
                'end'            => '2001-02-05 23:59:59',
                'future'         => '2099-01-02 12:00:00',
                'future_floor'   => '2099-01-01 00:00:00',
                'currency'       => 'USD',
                'payment_status' => 'paid',
            ],
            'rows' => [
                'order_item' => [
                    'model_class' => 'FluentCart\\App\\Models\\OrderItem',
                    'table'       => 'fct_order_items',
                ],
                'order_address' => [
                    'model_class' => 'FluentCart\\App\\Models\\OrderAddress',
                    'table'       => 'fct_order_addresses',
                ],
                'order_operation' => [
                    'model_class' => 'FluentCart\\App\\Models\\OrderOperation',
                    'table'       => 'fct_order_operations',
                ],
            ],
            'cleanup_order' => [
                'order_item',
                'order_address',
                'order_operation',
            ],
        ],
        'shared'          => [
            'coupon' => [
                'model_class'     => 'FluentCart\\App\\Models\\Coupon',
                'table'           => 'fct_coupons',
                'identity_column' => 'code',
                'identity_prefix' => 'P6-',
            ],
            'rows' => [
                'activity' => [
                    'model_class' => 'FluentCart\\App\\Models\\Activity',
                    'table'       => 'fct_activity',
                ],
                'label' => [
                    'model_class' => 'FluentCart\\App\\Models\\Label',
                    'table'       => 'fct_label',
                ],
                'label_relationship' => [
                    'model_class' => 'FluentCart\\App\\Models\\LabelRelationship',
                    'table'       => 'fct_label_relationships',
                ],
                'meta' => [
                    'model_class' => 'FluentCart\\App\\Models\\Meta',
                    'table'       => 'fct_meta',
                ],
                'product_meta' => [
                    'model_class' => 'FluentCart\\App\\Models\\ProductMeta',
                    'table'       => 'fct_product_meta',
                ],
                'customer_address' => [
                    'model_class' => 'FluentCart\\App\\Models\\CustomerAddresses',
                    'table'       => 'fct_customer_addresses',
                ],
                'tax_rate' => [
                    'model_class' => 'FluentCart\\App\\Models\\TaxRate',
                    'table'       => 'fct_tax_rates',
                ],
            ],
            'cleanup_order' => [
                'activity',
                'label_relationship',
                'meta',
                'product_meta',
                'customer_address',
                'tax_rate',
                'label',
            ],
        ],
        'domain'          => [
            'product_post_type' => 'fluent-products',
            'product_model_class' => 'FluentCart\\App\\Models\\Product',
            'rows' => [
                'product_detail' => [
                    'model_class' => 'FluentCart\\App\\Models\\ProductDetail',
                    'table'       => 'fct_product_details',
                ],
                'product_variation' => [
                    'model_class' => 'FluentCart\\App\\Models\\ProductVariation',
                    'table'       => 'fct_product_variations',
                ],
                'scheduled_action' => [
                    'model_class' => 'FluentCart\\App\\Models\\ScheduledAction',
                    'table'       => 'fct_scheduled_actions',
                ],
                'subscription' => [
                    'model_class' => 'FluentCart\\App\\Models\\Subscription',
                    'table'       => 'fct_subscriptions',
                ],
                'subscription_meta' => [
                    'model_class' => 'FluentCart\\App\\Models\\SubscriptionMeta',
                    'table'       => 'fct_subscription_meta',
                ],
                'activity' => [
                    'model_class' => 'FluentCart\\App\\Models\\Activity',
                    'table'       => 'fct_activity',
                ],
                'license' => [
                    'model_class' => 'FluentCartPro\\App\\Modules\\Licensing\\Models\\License',
                    'table'       => 'fct_licenses',
                ],
            ],
            'license_handler_class' => 'FluentCartPro\\App\\Modules\\Licensing\\Hooks\\Handlers\\LicenseGenerationHandler',
        ],
        'automation'      => [
            'cart' => [
                'model_class' => 'FluentCart\\App\\Models\\Cart',
                'table'       => 'fct_carts',
                'primary_key' => 'cart_hash',
            ],
        ],
        'public_surface'  => [
            'identity_domain' => 'example.invalid',
            'identity_prefix' => 'wp-plugin-phase18-',
            'rows' => [
                'customer' => [
                    'model_class' => 'FluentCart\\App\\Models\\Customer',
                    'table'       => 'fct_customers',
                ],
                'order' => [
                    'model_class' => 'FluentCart\\App\\Models\\Order',
                    'table'       => 'fct_orders',
                ],
                'transaction' => [
                    'model_class' => 'FluentCart\\App\\Models\\OrderTransaction',
                    'table'       => 'fct_order_transactions',
                ],
                'subscription' => [
                    'model_class' => 'FluentCart\\App\\Models\\Subscription',
                    'table'       => 'fct_subscriptions',
                ],
                'subscription_meta' => [
                    'table' => 'fct_subscription_meta',
                ],
                'activity' => [
                    'table' => 'fct_activity',
                ],
                'order_address' => [
                    'table' => 'fct_order_addresses',
                ],
                'order_meta' => [
                    'table' => 'fct_order_meta',
                ],
                'order_operation' => [
                    'table' => 'fct_order_operations',
                ],
                'order_item' => [
                    'table' => 'fct_order_items',
                ],
                'order_tax_rate' => [
                    'table' => 'fct_order_tax_rate',
                ],
                'applied_coupon' => [
                    'table' => 'fct_applied_coupons',
                ],
                'download_permission' => [
                    'table' => 'fct_order_download_permissions',
                ],
            ],
            'activity_module_types' => [
                'order'        => 'FluentCart\\App\\Models\\Order',
                'subscription' => 'FluentCart\\App\\Models\\Subscription',
            ],
            'cleanup_order_rows' => [
                'order_address',
                'order_meta',
                'order_operation',
                'order_item',
                'order_tax_rate',
                'applied_coupon',
                'download_permission',
            ],
        ],
        'throughput'      => [
            'identity_prefix'   => 'phase16-',
            'identity_domain'   => 'example.invalid',
            'product_post_type' => 'fluent-products',
            'product_model_class' => 'FluentCart\\App\\Models\\Product',
            'rows' => [
                'customer' => [
                    'model_class' => 'FluentCart\\App\\Models\\Customer',
                    'table'       => 'fct_customers',
                ],
                'customer_address' => [
                    'model_class' => 'FluentCart\\App\\Models\\CustomerAddresses',
                    'table'       => 'fct_customer_addresses',
                ],
                'activity' => [
                    'model_class' => 'FluentCart\\App\\Models\\Activity',
                    'table'       => 'fct_activity',
                ],
                'product_detail' => [
                    'model_class' => 'FluentCart\\App\\Models\\ProductDetail',
                    'table'       => 'fct_product_details',
                ],
                'product_variation' => [
                    'model_class' => 'FluentCart\\App\\Models\\ProductVariation',
                    'table'       => 'fct_product_variations',
                ],
            ],
            'cleanup_order' => [
                'activity',
                'customer_address',
                'product_variation',
                'product_detail',
                'customer',
            ],
        ],
        'crud'            => [
            'route_file' => 'app/Http/Routes/api.php',
            'rows' => [
                'customer' => [
                    'model_class'     => 'FluentCart\\App\\Models\\Customer',
                    'table'           => 'fct_customers',
                    'primary_key'     => 'id',
                    'identity_column' => 'email',
                ],
                'order' => [
                    'model_class' => 'FluentCart\\App\\Models\\Order',
                    'table'       => 'fct_orders',
                    'primary_key' => 'id',
                ],
                'order_item' => [
                    'model_class' => 'FluentCart\\App\\Models\\OrderItem',
                    'table'       => 'fct_order_items',
                    'primary_key' => 'id',
                ],
                'product_detail' => [
                    'model_class' => 'FluentCart\\App\\Models\\ProductDetail',
                    'table'       => 'fct_product_details',
                    'primary_key' => 'id',
                ],
                'product_variation' => [
                    'model_class'     => 'FluentCart\\App\\Models\\ProductVariation',
                    'table'           => 'fct_product_variations',
                    'primary_key'     => 'id',
                    'identity_column' => 'variation_title',
                ],
                'coupon' => [
                    'model_class'     => 'FluentCart\\App\\Models\\Coupon',
                    'table'           => 'fct_coupons',
                    'primary_key'     => 'id',
                    'identity_column' => 'code',
                ],
                'label' => [
                    'model_class'     => 'FluentCart\\App\\Models\\Label',
                    'table'           => 'fct_label',
                    'primary_key'     => 'id',
                    'identity_column' => 'value',
                ],
                'label_relationship' => [
                    'model_class' => 'FluentCart\\App\\Models\\LabelRelationship',
                    'table'       => 'fct_label_relationships',
                    'primary_key' => 'id',
                ],
                'activity' => [
                    'model_class' => 'FluentCart\\App\\Models\\Activity',
                    'table'       => 'fct_activity',
                    'primary_key' => 'id',
                ],
            ],
            'cleanup_order' => [
                'activity',
                'label_relationship',
                'product_variation',
                'coupon',
                'label',
                'customer',
            ],
        ],
    ],

    // Framework wiring -----------------------------------------------------
    // Global function returning the plugin's app container.
    'app_bootstrap' => 'fluentCart',
    'request_class' => 'FluentCart\\Framework\\Http\\Request\\Request',

    // Any class that only exists when the plugin is loaded — proves the
    // harness booted against the right install before anything else runs.
    'sentinel_class' => 'FluentCart\\App\\Models\\Order',

    // Caches ---------------------------------------------------------------
    // Flushed before every suite. A warm transient can return 200 over
    // completely broken SQL — this is not optional.
    'cache_groups' => [],

    // Prefixes for plugin-owned transient names. The harness expands each to
    // the normal and timeout option-name forms before clearing caches.
    'transient_prefixes' => ['fluent_cart_', 'fct_'],

    // Optional seams -------------------------------------------------------
    // Filter the plugin fires before a background loopback request, so tests
    // can observe intent without HTTP. Leave empty when the plugin has no seam;
    // adding one requires a separate, explicitly authorized production change.
    'loopback_filter' => '',
];
