<?php
/**
 * Exact-ID fixture ownership for real FluentCart integration tests.
 *
 * Adapted from the wp-plugin-test-suite factory asset. Customer and Order
 * creation/read/deletion use the real configured FluentCart models. Raw wpdb
 * is limited to exact-key related-row cleanup plus residue/protected-count
 * assertions.
 */

class FcFixture
{
    /** @var string|null */
    private static $identity = null;

    /** @var array{id:int,identity:string}|null */
    private static $customer = null;

    /** @var array<int,array{id:int,identity:string,customer_id:int,uuid:string}> */
    private static $orders = [];

    /** @var array<int,array{id:int,identity:string,customer_id:int,uuid:string}> */
    private static $orderHistory = [];

    /** @var array{id:int,identity:string,code:string}|null */
    private static $coupon = null;

    /** @var array<int,array{id:int,identity:string,code:string}> */
    private static $couponHistory = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    private static $sharedRows = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    private static $sharedRowHistory = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    private static $reportRows = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    private static $reportRowHistory = [];

    /** @var array<string,mixed>|null */
    private static $config = null;

    /**
     * Set or generate the unmistakable identity for this process.
     *
     * @param string|null $identity
     * @return string
     */
    public static function initialize($identity = null)
    {
        if (self::$identity !== null) {
            if ($identity !== null && $identity !== self::$identity) {
                throw new LogicException('Fixture identity cannot change during a run.');
            }

            return self::$identity;
        }

        $fixtureConfig = self::fixtureConfig();
        if ($identity === null || $identity === '') {
            $identity = 'wp-plugin-phase18-'
                . strtolower(wp_generate_password(16, false, false))
                . '@' . $fixtureConfig['identity_domain'];
        }

        $identity = strtolower((string) $identity);
        $domain = preg_quote($fixtureConfig['identity_domain'], '/');
        if (
            strlen($identity) > 192
            || !preg_match('/^wp-plugin-phase(?:[4-9]|10|14|16|18|27)-[a-z0-9-]+@' . $domain . '$/', $identity)
        ) {
            throw new InvalidArgumentException(
                'Fixture identity must be an unmistakable '
                . 'wp-plugin-phase4/5/6/7/8/9/10/14/16/18/27 address.'
            );
        }

        self::$identity = $identity;

        return self::$identity;
    }

    /**
     * Return this process's exact fixture identity.
     *
     * @return string
     */
    public static function identity()
    {
        return self::initialize();
    }

    /**
     * Create one inert Customer through the real FluentCart model stack.
     *
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function customer(array $attributes = [])
    {
        if (self::$customer !== null) {
            throw new LogicException('This Phase 4 fixture process already owns a Customer.');
        }

        $identity = self::identity();
        if (self::residueCount($identity) !== 0) {
            throw new RuntimeException(
                'Refusing to create a Customer over an existing exact fixture identity: '
                . $identity
            );
        }

        $defaults = [
            'email'          => $identity,
            'first_name'     => 'Phase Four',
            'last_name'      => 'Fixture',
            'status'         => 'active',
            'purchase_value' => [
                'currency' => 'USD',
                'gross'    => 12345,
            ],
            'purchase_count' => 0,
            'ltv'            => 0,
            'notes'          => 'Owned integration fixture ' . $identity,
            'country'        => 'BD',
            'city'           => 'Dhaka',
            'state'          => 'C',
            'postcode'       => '1205',
        ];
        $data = array_merge($defaults, $attributes, ['email' => $identity]);

        $modelClass = self::fixtureConfig()['model_class'];
        $errorOffset = self::databaseErrorOffset();

        try {
            $customer = $modelClass::query()->create($data);
            $id = isset($customer->id) ? (int) $customer->id : 0;

            // Capture ownership immediately, before any assertion or later query.
            if ($id > 0) {
                self::$customer = [
                    'id'       => $id,
                    'identity' => $identity,
                ];
            }

            self::throwOnDatabaseErrors('Customer model create', $errorOffset);

            if ($id <= 0) {
                throw new RuntimeException('Customer model create returned no positive primary ID.');
            }

            return $customer;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Customer model/DB round-trip create failed for exact identity '
                . $identity . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Reload the exact owned Customer through the real model/query builder.
     *
     * @return object
     */
    public static function reloadCustomer()
    {
        if (self::$customer === null) {
            throw new LogicException('No owned Customer ID is available to reload.');
        }

        $modelClass = self::fixtureConfig()['model_class'];
        $errorOffset = self::databaseErrorOffset();

        try {
            $customer = $modelClass::query()->find(self::$customer['id']);
            self::throwOnDatabaseErrors('Customer model read-back', $errorOffset);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Customer model/DB round-trip read-back failed for ID '
                . self::$customer['id'] . ': ' . $e->getMessage(),
                0,
                $e
            );
        }

        if (!$customer) {
            throw new RuntimeException(
                'Customer model read-back did not find exact owned ID '
                . self::$customer['id'] . '.'
            );
        }

        return $customer;
    }

    /**
     * Create one inert, pending-payment Order owned by the current Customer.
     *
     * Multiple orders may share the per-run note marker; every mutation and
     * cleanup still uses the immediately captured primary ID.
     *
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function order(array $attributes = [])
    {
        if (self::$customer === null) {
            throw new LogicException('Create the owned Customer before creating an Order.');
        }

        $identity = self::identity();
        if (!self::$orders && self::orderResidueCount($identity) !== 0) {
            throw new RuntimeException(
                'Refusing to create an Order over an existing exact fixture marker: '
                . self::orderMarker($identity)
            );
        }

        foreach (['payment_status', 'total_paid', 'payment_method'] as $forbiddenOverride) {
            if (array_key_exists($forbiddenOverride, $attributes)) {
                throw new InvalidArgumentException(
                    'Order fixture safety field cannot be overridden: ' . $forbiddenOverride
                );
            }
        }

        $marker = self::orderMarker($identity);
        $defaults = [
            'status'                 => 'processing',
            'parent_id'              => null,
            'invoice_no'             => '',
            'receipt_number'         => null,
            'fulfillment_type'       => 'digital',
            'type'                   => 'payment',
            'customer_id'            => self::$customer['id'],
            'payment_method'         => '',
            'payment_method_title'   => '',
            'payment_status'         => 'pending',
            'currency'               => 'USD',
            'subtotal'               => 10000,
            'discount_tax'           => 0,
            'manual_discount_total'  => 0,
            'coupon_discount_total'  => 0,
            'shipping_tax'           => 0,
            'shipping_total'         => 0,
            'fee_total'              => 0,
            'tax_total'              => 123,
            'tax_behavior'           => 0,
            'total_amount'           => 10123,
            'rate'                   => 1,
            'note'                   => $marker,
            'ip_address'             => '192.0.2.1',
            'completed_at'           => null,
            'refunded_at'            => null,
            'total_refund'           => 0,
            'total_paid'             => 0,
            'mode'                   => 'test',
            'shipping_status'        => '',
            'config'                 => [
                'fixture_identity' => $identity,
                'fixture_owner'    => 'wp-plugin-test-suite',
            ],
        ];

        $data = array_merge($defaults, $attributes);
        $data['customer_id'] = self::$customer['id'];
        $data['payment_method'] = '';
        $data['payment_status'] = 'pending';
        $data['total_paid'] = 0;
        $data['note'] = $marker;
        $data['config'] = array_merge(
            $defaults['config'],
            isset($attributes['config']) && is_array($attributes['config'])
                ? $attributes['config']
                : [],
            ['fixture_identity' => $identity]
        );

        $modelClass = self::orderConfig()['model_class'];
        $errorOffset = self::databaseErrorOffset();

        try {
            $order = $modelClass::query()->create($data);
            $id = isset($order->id) ? (int) $order->id : 0;

            // Capture ownership immediately, before any assertion or later query.
            if ($id > 0) {
                $owned = [
                    'id'          => $id,
                    'identity'    => $identity,
                    'customer_id' => self::$customer['id'],
                    'uuid'        => isset($order->uuid) ? (string) $order->uuid : '',
                ];
                self::$orders[$id] = $owned;
                self::$orderHistory[$id] = $owned;
            }

            self::throwOnDatabaseErrors('Order model create', $errorOffset);

            if ($id <= 0) {
                throw new RuntimeException('Order model create returned no positive primary ID.');
            }

            return $order;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Order model/DB round-trip create failed for exact marker '
                . $marker . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Reload one exact owned Order through the real model/query builder.
     *
     * @param int $id
     * @return object
     */
    public static function reloadOrder($id)
    {
        $id = (int) $id;
        if (!isset(self::$orders[$id])) {
            throw new LogicException('No owned Order is registered for ID ' . $id . '.');
        }

        $modelClass = self::orderConfig()['model_class'];
        $errorOffset = self::databaseErrorOffset();

        try {
            $order = $modelClass::query()->find($id);
            self::throwOnDatabaseErrors('Order model read-back', $errorOffset);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Order model/DB round-trip read-back failed for exact owned ID '
                . $id . ': ' . $e->getMessage(),
                0,
                $e
            );
        }

        if (!$order) {
            throw new RuntimeException(
                'Order model read-back did not find exact owned ID ' . $id . '.'
            );
        }

        return $order;
    }

    /**
     * Adopt an Order created inside the checkout service after proving its exact
     * customer, note marker, and inert payment state.
     *
     * @param int $id
     * @return object
     */
    public static function captureCheckoutOrder($id)
    {
        $id = (int) $id;
        if ($id <= 0 || self::$customer === null) {
            throw new LogicException('Checkout Order capture requires an owned Customer and positive ID.');
        }
        if (isset(self::$orders[$id])) {
            return self::reloadOrder($id);
        }

        $modelClass = self::orderConfig()['model_class'];
        $order = $modelClass::query()->find($id);
        if (!$order) {
            throw new RuntimeException('Checkout Order capture could not find exact ID ' . $id . '.');
        }
        if (
            (string) $order->{self::orderConfig()['identity_column']} !== self::orderMarker()
            || (int) $order->customer_id !== (int) self::$customer['id']
            || (string) $order->payment_status !== 'pending'
            || (int) $order->total_paid !== 0
        ) {
            throw new LogicException(
                'Refusing checkout Order capture because exact inert ownership checks failed for ID '
                . $id . '.'
            );
        }

        $owned = [
            'id'          => $id,
            'identity'    => self::identity(),
            'customer_id' => (int) self::$customer['id'],
            'uuid'        => isset($order->uuid) ? (string) $order->uuid : '',
        ];
        self::$orders[$id] = $owned;
        self::$orderHistory[$id] = $owned;

        return $order;
    }

    /**
     * Return the configured deterministic report window.
     *
     * @return array<string,string>
     */
    public static function reportWindow()
    {
        return self::reportConfig()['window'];
    }

    /**
     * Return an exact identity-derived marker for Phase 7 report rows.
     *
     * @param string $suffix
     * @return string
     */
    public static function reportMarker($suffix)
    {
        $suffix = preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $suffix));
        if ($suffix === '') {
            throw new InvalidArgumentException('Report fixture marker suffix cannot be empty.');
        }

        return 'phase7-' . substr(hash('sha256', self::identity()), 0, 20) . '-' . $suffix;
    }

    /**
     * Refuse to use a deterministic report range that already contains data.
     *
     * The check is read-only and runs before fixture creation in every Phase 7
     * behavioral case. The future floor also makes global recent-list assertions
     * deterministic without touching an existing row.
     *
     * @return void
     */
    public static function assertReportRangesEmpty()
    {
        global $wpdb;

        $window = self::reportWindow();
        foreach ([
            'fct_orders'      => 'created_at',
            'fct_customers'   => 'created_at',
            'fct_order_items' => 'created_at',
        ] as $table => $column) {
            $tableName = self::validatedIdentifier($wpdb->prefix . $table, 'report window table');
            $columnName = self::validatedIdentifier($column, 'report window column');
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$tableName}` WHERE `{$columnName}` BETWEEN %s AND %s",
                $window['start'],
                $window['end']
            ));
            if ($wpdb->last_error !== '') {
                throw new RuntimeException(
                    'Report window collision query failed for ' . $table . ': ' . $wpdb->last_error
                );
            }
            if ($count !== 0) {
                throw new RuntimeException(
                    'Refusing Phase 7 fixtures because deterministic range is not empty: '
                    . $table . ' count=' . $count
                );
            }
        }

        foreach (['fct_orders', 'fct_activity'] as $table) {
            $tableName = self::validatedIdentifier($wpdb->prefix . $table, 'future report table');
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$tableName}` WHERE `created_at` >= %s",
                $window['future_floor']
            ));
            if ($wpdb->last_error !== '') {
                throw new RuntimeException(
                    'Future report collision query failed for ' . $table . ': ' . $wpdb->last_error
                );
            }
            if ($count !== 0) {
                throw new RuntimeException(
                    'Refusing Phase 7 recent-list fixture because future range is not empty: '
                    . $table . ' count=' . $count
                );
            }
        }
    }

    /**
     * Move the exact owned Customer into the isolated report window.
     *
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function prepareReportCustomer(array $attributes)
    {
        if (self::$customer === null) {
            throw new LogicException('Create the owned Customer before preparing report fields.');
        }

        $allowed = [
            'created_at',
            'updated_at',
            'first_purchase_date',
            'last_purchase_date',
            'purchase_count',
            'ltv',
            'aov',
        ];

        self::updateExactOwnedRow(
            self::fixtureConfig()['table'],
            self::$customer['id'],
            self::fixtureConfig()['identity_column'],
            self::$customer['identity'],
            $attributes,
            $allowed,
            'Customer report scalar preparation'
        );

        return self::reloadCustomer();
    }

    /**
     * Create an Order through the real model, then set inert report-only scalar
     * fields with one exact-ID SQL update. This deliberately bypasses Order's
     * paid-creation receipt/invoice hooks and every payment service.
     *
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function reportOrder(array $attributes)
    {
        if (
            isset($attributes['parent_id'])
            && !in_array((int) $attributes['parent_id'], self::ownedOrderIds(), true)
        ) {
            throw new LogicException(
                'Report Order parent_id must reference an exact owned Order.'
            );
        }

        $order = self::order([
            'config' => ['fixture_case' => 'phase7-reports'],
        ]);
        $id = (int) $order->id;

        $allowed = [
            'status',
            'parent_id',
            'payment_status',
            'payment_method',
            'payment_method_title',
            'currency',
            'subtotal',
            'manual_discount_total',
            'coupon_discount_total',
            'shipping_tax',
            'shipping_total',
            'fee_total',
            'tax_total',
            'total_amount',
            'total_paid',
            'total_refund',
            'completed_at',
            'refunded_at',
            'created_at',
            'updated_at',
            'type',
            'mode',
            'shipping_status',
        ];

        self::updateExactOwnedRow(
            self::orderConfig()['table'],
            $id,
            'id',
            $id,
            $attributes,
            $allowed,
            'Order report scalar preparation',
            [
                self::orderConfig()['identity_column'] => self::orderMarker(),
            ]
        );

        return self::reloadOrder($id);
    }

    /**
     * Create one exact owned report OrderItem.
     *
     * @param int                 $orderId
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function reportOrderItem($orderId, array $attributes)
    {
        $defaults = [
            'order_id'          => (int) $orderId,
            'post_id'           => 0,
            'fulfillment_type'  => 'digital',
            'payment_type'      => 'onetime',
            'post_title'        => self::reportMarker('product'),
            'title'             => self::reportMarker('variant'),
            'object_id'         => null,
            'cart_index'        => 0,
            'quantity'          => 1,
            'unit_price'        => 0,
            'cost'              => 0,
            'subtotal'          => 0,
            'tax_amount'        => 0,
            'discount_total'    => 0,
            'refund_total'      => 0,
            'line_total'        => 0,
            'rate'              => 1,
            'other_info'        => ['fixture_identity' => self::identity()],
            'line_meta'         => ['fixture_identity' => self::identity()],
            'fulfilled_quantity'=> 0,
            'referrer'          => self::reportMarker('referrer'),
            'created_at'        => self::reportWindow()['start'],
        ];

        return self::createReportRow(
            'order_item',
            array_merge($defaults, $attributes, ['order_id' => (int) $orderId])
        );
    }

    /**
     * Create one exact owned report OrderAddress.
     *
     * @param int                 $orderId
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function reportOrderAddress($orderId, array $attributes = [])
    {
        $defaults = [
            'order_id' => (int) $orderId,
            'type'     => 'billing',
            'name'     => self::reportMarker('address'),
            'address_1'=> 'Phase 7 exact fixture',
            'city'     => 'Dhaka',
            'state'    => 'C',
            'postcode' => '1205',
            'country'  => 'BD',
            'meta'     => ['fixture_identity' => self::identity()],
        ];

        return self::createReportRow(
            'order_address',
            array_merge($defaults, $attributes, ['order_id' => (int) $orderId])
        );
    }

    /**
     * Create one exact owned report OrderOperation.
     *
     * @param int                 $orderId
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function reportOrderOperation($orderId, array $attributes = [])
    {
        $marker = self::reportMarker('source');
        $defaults = [
            'order_id'      => (int) $orderId,
            'created_via'   => 'phase7-test',
            'emails_sent'   => 0,
            'sales_recorded'=> 0,
            'utm_campaign'  => $marker,
            'utm_term'      => self::reportMarker('term'),
            'utm_source'    => $marker,
            'utm_content'   => self::reportMarker('content'),
            'utm_medium'    => 'integration',
            'utm_id'        => self::reportMarker('utm-id'),
            'cart_hash'     => self::reportMarker('cart'),
            'refer_url'     => '',
        ];

        return self::createReportRow(
            'order_operation',
            array_merge($defaults, $attributes, ['order_id' => (int) $orderId])
        );
    }

    /**
     * Move an exact captured Activity to a deterministic future timestamp.
     *
     * @param int    $activityId
     * @param string $createdAt
     * @return object
     */
    public static function prepareReportActivity($activityId, $createdAt)
    {
        $activityId = (int) $activityId;
        if (!isset(self::$sharedRows['activity'][$activityId])) {
            throw new LogicException('No exact owned Activity is registered for report preparation.');
        }

        self::updateExactOwnedRow(
            self::sharedConfig()['rows']['activity']['table'],
            $activityId,
            'id',
            $activityId,
            ['created_at' => (string) $createdAt, 'updated_at' => (string) $createdAt],
            ['created_at', 'updated_at'],
            'Activity report timestamp preparation'
        );

        return self::reloadSharedRow('activity', $activityId);
    }

    /**
     * Return a deterministic, exact marker value for one shared-table row.
     *
     * @param string $suffix
     * @return string
     */
    public static function sharedValue($suffix)
    {
        $suffix = preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $suffix));
        if ($suffix === '') {
            throw new InvalidArgumentException('Shared fixture marker suffix cannot be empty.');
        }

        return 'phase6-' . substr(hash('sha256', self::identity()), 0, 20) . '-' . $suffix;
    }

    /**
     * Return the exact unique Coupon code for this fixture process.
     *
     * @param string|null $identity
     * @return string
     */
    public static function couponCode($identity = null)
    {
        $identity = $identity === null ? self::identity() : (string) $identity;

        return self::sharedConfig()['coupon']['identity_prefix']
            . strtoupper(substr(hash('sha256', $identity), 0, 32));
    }

    /**
     * Create one inert Coupon through the real FluentCart model stack.
     *
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function coupon(array $attributes = [])
    {
        if (self::$coupon !== null) {
            throw new LogicException('This fixture process already owns a Coupon.');
        }

        $identity = self::identity();
        $code = self::couponCode($identity);
        $config = self::sharedConfig()['coupon'];
        if (self::countExactRows(
            $config['table'],
            [$config['identity_column'] => $code],
            'Coupon exact code collision check'
        ) !== 0) {
            throw new RuntimeException(
                'Refusing to create a Coupon over an existing exact fixture code: ' . $code
            );
        }

        $defaults = [
            'title'            => 'Phase Six owned Coupon ' . $code,
            'code'             => $code,
            'type'             => 'fixed',
            'conditions'       => [],
            'amount'           => 777,
            'use_count'        => 0,
            'status'           => 'draft',
            'notes'            => self::sharedValue('coupon'),
            'stackable'        => 'no',
            'show_on_checkout' => 'no',
        ];
        $data = array_merge($defaults, $attributes, ['code' => $code]);
        $modelClass = $config['model_class'];
        $errorOffset = self::databaseErrorOffset();

        try {
            $coupon = $modelClass::query()->create($data);
            $id = isset($coupon->id) ? (int) $coupon->id : 0;
            if ($id > 0) {
                self::$coupon = [
                    'id'       => $id,
                    'identity' => $identity,
                    'code'     => $code,
                ];
                self::$couponHistory[$id] = self::$coupon;
            }

            self::throwOnDatabaseErrors('Coupon model create', $errorOffset);
            if ($id <= 0) {
                throw new RuntimeException('Coupon model create returned no positive primary ID.');
            }

            return $coupon;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Coupon model/DB fixture create failed for exact code '
                . $code . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Create an exact owned Activity row through the real model stack.
     *
     * @param int    $moduleId
     * @param string $moduleType
     * @param string $suffix
     * @return object
     */
    public static function activity($moduleId, $moduleType, $suffix)
    {
        $marker = self::sharedValue($suffix);

        return self::createSharedRow('activity', [
            'status'       => 'info',
            'log_type'     => 'activity',
            'module_id'    => (int) $moduleId,
            'module_type'  => (string) $moduleType,
            'module_name'  => 'phase6-fixture',
            'title'        => $marker,
            'content'      => $marker,
            'user_id'      => null,
            'read_status'  => 'unread',
            'created_by'   => 'WP-PLUGIN-TEST',
        ]);
    }

    /**
     * Create one exact owned Label through the real model stack.
     *
     * @param string $suffix
     * @return object
     */
    public static function label($suffix)
    {
        return self::createSharedRow('label', [
            'value' => self::sharedValue($suffix),
        ]);
    }

    /**
     * Create one exact polymorphic LabelRelationship.
     *
     * @param int    $labelId
     * @param int    $labelableId
     * @param string $labelableType
     * @return object
     */
    public static function labelRelationship($labelId, $labelableId, $labelableType)
    {
        return self::createSharedRow('label_relationship', [
            'label_id'       => (int) $labelId,
            'labelable_id'   => (int) $labelableId,
            'labelable_type' => (string) $labelableType,
        ]);
    }

    /**
     * Create one exact generic Meta row.
     *
     * @param int    $objectId
     * @param string $objectType
     * @param string $metaKey
     * @param mixed  $metaValue
     * @return object
     */
    public static function meta($objectId, $objectType, $metaKey, $metaValue)
    {
        return self::createSharedRow('meta', [
            'object_id'   => (int) $objectId,
            'object_type' => (string) $objectType,
            'meta_key'    => (string) $metaKey,
            'meta_value'  => $metaValue,
        ]);
    }

    /**
     * Create one exact ProductMeta row without requiring a WordPress product.
     *
     * @param int    $objectId
     * @param string $objectType
     * @param string $metaKey
     * @param mixed  $metaValue
     * @return object
     */
    public static function productMeta($objectId, $objectType, $metaKey, $metaValue)
    {
        return self::createSharedRow('product_meta', [
            'object_id'   => (int) $objectId,
            'object_type' => (string) $objectType,
            'meta_key'    => (string) $metaKey,
            'meta_value'  => $metaValue,
        ]);
    }

    /**
     * Create one exact owned Customer address for checkout reuse.
     *
     * @param int    $customerId
     * @param string $type
     * @return object
     */
    public static function customerAddress($customerId, $type)
    {
        $customerId = (int) $customerId;
        $type = (string) $type;
        if (
            self::$customer === null
            || $customerId !== (int) self::$customer['id']
            || !in_array($type, ['billing', 'shipping'], true)
        ) {
            throw new InvalidArgumentException('Customer address must belong to the exact owned Customer.');
        }

        return self::createSharedRow('customer_address', [
            'customer_id' => $customerId,
            'is_primary'  => 1,
            'type'        => $type,
            'status'      => 'active',
            'label'       => 'phase23-' . $type,
            'name'        => 'Phase Twenty Three',
            'address_1'   => self::sharedValue('customer-address-' . $type),
            'address_2'   => '',
            'city'        => 'Dhaka',
            'state'       => 'C',
            'postcode'    => '1205',
            'country'     => 'BD',
            'phone'       => '',
            'email'       => self::identity(),
            'meta'        => ['fixture_identity' => self::identity()],
        ]);
    }

    /**
     * Create one exact owned TaxRate under an existing TaxClass.
     *
     * @param int                 $classId
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function taxRate($classId, array $attributes = [])
    {
        $classId = (int) $classId;
        if ($classId <= 0) {
            throw new InvalidArgumentException('TaxRate fixture requires a positive TaxClass ID.');
        }

        $defaults = [
            'class_id'     => $classId,
            'country'      => 'XZ',
            'state'        => 'ST',
            'postcode'     => '1000-1999',
            'city'         => '',
            'rate'         => 10,
            'name'         => self::sharedValue('tax-rate'),
            'group'        => 'phase23',
            'priority'     => 1,
            'is_compound'  => 0,
            'for_shipping' => null,
            'for_order'    => 0,
        ];

        return self::createSharedRow('tax_rate', array_merge($defaults, $attributes, [
            'class_id' => $classId,
            'name'     => self::sharedValue('tax-rate'),
        ]));
    }

    /**
     * Return a stable unused synthetic object ID for ProductMeta collisions.
     *
     * @return int
     */
    public static function productMetaObjectId()
    {
        $id = self::syntheticProductMetaObjectId();
        if (
            empty(self::$sharedRows['product_meta'])
            && self::countExactRows(
                self::sharedConfig()['rows']['product_meta']['table'],
                ['object_id' => $id],
                'ProductMeta synthetic object ID collision check'
            ) !== 0
        ) {
            throw new RuntimeException(
                'Synthetic ProductMeta object ID collides with an existing row: ' . $id
            );
        }

        return $id;
    }

    /**
     * Reload an exact captured shared row through its real model.
     *
     * @param string $kind
     * @param int    $id
     * @return object
     */
    public static function reloadSharedRow($kind, $id)
    {
        $kind = (string) $kind;
        $id = (int) $id;
        if (!isset(self::$sharedRowHistory[$kind][$id])) {
            throw new LogicException(
                'No captured shared fixture row for ' . $kind . ' ID ' . $id . '.'
            );
        }

        $modelClass = self::$sharedRowHistory[$kind][$id]['model_class'];
        $errorOffset = self::databaseErrorOffset();
        $row = $modelClass::query()->find($id);
        self::throwOnDatabaseErrors('Shared fixture exact-ID read-back', $errorOffset);
        if (!$row) {
            throw new RuntimeException(
                'Shared fixture exact-ID read-back missed ' . $kind . ' ID ' . $id . '.'
            );
        }

        return $row;
    }

    /**
     * Record an expected exact value after exercising a real production update.
     *
     * @param string              $kind
     * @param int                 $id
     * @param array<string,mixed> $expected
     * @return void
     */
    public static function expectSharedRowValues($kind, $id, array $expected)
    {
        $kind = (string) $kind;
        $id = (int) $id;
        if (!isset(self::$sharedRows[$kind][$id])) {
            throw new LogicException(
                'Cannot update ownership expectations for unknown '
                . $kind . ' ID ' . $id . '.'
            );
        }

        self::$sharedRows[$kind][$id]['expected'] = array_merge(
            self::$sharedRows[$kind][$id]['expected'],
            $expected
        );
        self::$sharedRowHistory[$kind][$id] = self::$sharedRows[$kind][$id];
    }

    /**
     * Use the real OrderResource state-machine entry point.
     *
     * @param object $order
     * @param string $newStatus
     * @return mixed
     */
    public static function updateOrderStatus($order, $newStatus)
    {
        $resourceClass = self::orderConfig()['resource_class'];

        return $resourceClass::updateStatuses([
            'order'        => $order,
            'statuses'     => ['order_status' => (string) $newStatus],
            'manage_stock' => false,
            'action'       => 'change_order_status',
        ]);
    }

    /**
     * Refuse status dispatch when the active site has an unreviewed callback.
     *
     * @param string $newStatus
     * @return void
     */
    public static function assertOrderStatusHooksSafe($newStatus)
    {
        global $wp_filter;

        $allowlist = self::orderConfig()['status_hook_allowlist'];
        $hooks = [
            'fluent_cart/order_status_changed_to_' . (string) $newStatus,
            'fluent_cart/order_status_changed',
        ];

        foreach ($hooks as $hook) {
            $actual = [];
            if (
                isset($wp_filter[$hook])
                && is_object($wp_filter[$hook])
                && !empty($wp_filter[$hook]->callbacks)
            ) {
                foreach ($wp_filter[$hook]->callbacks as $callbacks) {
                    foreach ($callbacks as $callback) {
                        $actual[] = self::callbackName($callback['function']);
                    }
                }
            }

            $expected = isset($allowlist[$hook]) ? array_values($allowlist[$hook]) : [];
            sort($actual);
            sort($expected);
            if ($actual !== $expected) {
                throw new RuntimeException(
                    'Unsafe/unreviewed order-status hook callbacks for ' . $hook
                    . ': expected ' . wp_json_encode($expected)
                    . ', actual ' . wp_json_encode($actual)
                );
            }
        }
    }

    /**
     * Assert that a transition created only its single expected activity row.
     *
     * @param int $orderId
     * @param int $expectedActivityCount
     * @return void
     */
    public static function assertNoForbiddenOrderSideEffects($orderId, $expectedActivityCount)
    {
        $counts = self::orderRelatedCounts($orderId);
        $activityTable = self::orderConfig()['activity_table'];
        $expected = [];

        foreach ($counts as $table => $count) {
            $expectedCount = $table === $activityTable ? (int) $expectedActivityCount : 0;
            if ($count !== $expectedCount) {
                $expected[$table] = [
                    'expected' => $expectedCount,
                    'actual'   => $count,
                ];
            }
        }

        if ($expected) {
            throw new RuntimeException(
                'Forbidden Order side effect/residue for ID ' . (int) $orderId
                . ': ' . wp_json_encode($expected)
            );
        }
    }

    /**
     * Delete related rows and the exact owned Order, then prove exact absence.
     *
     * @param int $id
     * @return void
     */
    public static function cleanupOrder($id)
    {
        $id = (int) $id;
        if (!isset(self::$orders[$id])) {
            return;
        }

        $owned = self::$orders[$id];
        $modelClass = self::orderConfig()['model_class'];

        try {
            $errorOffset = self::databaseErrorOffset();
            $order = $modelClass::query()->find($id);
            self::throwOnDatabaseErrors('Order fixture cleanup lookup', $errorOffset);

            if ($order) {
                $identityColumn = self::orderConfig()['identity_column'];
                $actualIdentity = (string) $order->{$identityColumn};
                if ($actualIdentity !== self::orderMarker($owned['identity'])) {
                    throw new LogicException(
                        'Refusing to delete Order ID ' . $id
                        . ' because its ownership marker changed.'
                    );
                }
                if ((int) $order->customer_id !== $owned['customer_id']) {
                    throw new LogicException(
                        'Refusing to delete Order ID ' . $id
                        . ' because its owned Customer ID changed.'
                    );
                }
            }

            $children = self::countExactRows(
                self::orderConfig()['table'],
                ['parent_id' => $id],
                'Order child-row safety check'
            );
            if ($children !== 0) {
                throw new RuntimeException(
                    'Refusing Order cleanup because exact parent ID ' . $id
                    . ' has unexpected child orders count=' . $children . '.'
                );
            }

            foreach (self::orderConfig()['related_rows'] as $related) {
                $where = isset($related['where']) && is_array($related['where'])
                    ? $related['where']
                    : [];
                $where = array_merge(
                    [$related['foreign_key'] => $id],
                    $where
                );
                self::deleteExactRows(
                    $related['table'],
                    $where,
                    'Order fixture related cleanup'
                );
            }

            self::assertNoForbiddenOrderSideEffects($id, 0);

            if ($order) {
                $errorOffset = self::databaseErrorOffset();
                $deleted = $order->delete();
                self::throwOnDatabaseErrors('Order fixture exact-ID delete', $errorOffset);
                if ($deleted !== true) {
                    throw new RuntimeException(
                        'Order model did not confirm deletion of exact owned ID ' . $id . '.'
                    );
                }
            }

            $errorOffset = self::databaseErrorOffset();
            $remainingById = $modelClass::query()->find($id);
            self::throwOnDatabaseErrors('Order fixture ID absence check', $errorOffset);
            if ($remainingById) {
                throw new RuntimeException(
                    'Order fixture cleanup left exact owned ID ' . $id . '.'
                );
            }

            unset(self::$orders[$id]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Exact Order fixture cleanup failed for ID ' . $id
                . ' / ' . self::orderMarker($owned['identity'])
                . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Delete every captured shared-table row, children before parents.
     *
     * @return void
     */
    public static function cleanupSharedRows()
    {
        foreach (self::sharedConfig()['cleanup_order'] as $kind) {
            $ids = isset(self::$sharedRows[$kind])
                ? array_keys(self::$sharedRows[$kind])
                : [];
            rsort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                self::cleanupSharedRow($kind, $id);
            }
        }
    }

    /**
     * Delete every captured report child by exact primary ID.
     *
     * @return void
     */
    public static function cleanupReportRows()
    {
        foreach (self::reportConfig()['cleanup_order'] as $kind) {
            $ids = isset(self::$reportRows[$kind])
                ? array_keys(self::$reportRows[$kind])
                : [];
            rsort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                self::cleanupReportRow($kind, $id);
            }
        }
    }

    /**
     * Delete the exact owned Coupon after its shared child rows.
     *
     * @return void
     */
    public static function cleanupCoupon()
    {
        if (self::$coupon === null) {
            return;
        }
        if (self::$sharedRows) {
            throw new LogicException('Clean shared Coupon child rows before the owned Coupon.');
        }

        $owned = self::$coupon;
        $config = self::sharedConfig()['coupon'];
        $modelClass = $config['model_class'];

        try {
            $errorOffset = self::databaseErrorOffset();
            $coupon = $modelClass::query()->find($owned['id']);
            self::throwOnDatabaseErrors('Coupon fixture cleanup lookup', $errorOffset);

            if ($coupon) {
                $column = $config['identity_column'];
                if ((string) $coupon->{$column} !== $owned['code']) {
                    throw new LogicException(
                        'Refusing to delete Coupon ID ' . $owned['id']
                        . ' because its exact code changed.'
                    );
                }

                $errorOffset = self::databaseErrorOffset();
                $deleted = $coupon->delete();
                self::throwOnDatabaseErrors('Coupon fixture exact-ID delete', $errorOffset);
                if ($deleted !== true) {
                    throw new RuntimeException(
                        'Coupon model did not confirm deletion of exact owned ID '
                        . $owned['id'] . '.'
                    );
                }
            }

            if (self::countExactRows(
                $config['table'],
                ['id' => (int) $owned['id']],
                'Coupon fixture exact-ID absence check'
            ) !== 0) {
                throw new RuntimeException(
                    'Coupon fixture cleanup left exact owned ID ' . $owned['id'] . '.'
                );
            }
            if (self::countExactRows(
                $config['table'],
                [$config['identity_column'] => $owned['code']],
                'Coupon fixture exact-code absence check'
            ) !== 0) {
                throw new RuntimeException(
                    'Coupon fixture cleanup left exact code ' . $owned['code'] . '.'
                );
            }

            self::$coupon = null;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Exact Coupon fixture cleanup failed for ID ' . $owned['id']
                . ' / ' . $owned['code'] . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Clean all exact owned rows, shared children before their parents.
     *
     * @return void
     */
    public static function cleanupAll()
    {
        self::cleanupSharedRows();
        self::cleanupReportRows();

        $orderIds = array_keys(self::$orders);
        rsort($orderIds, SORT_NUMERIC);
        foreach ($orderIds as $orderId) {
            self::cleanupOrder($orderId);
        }

        self::cleanupCustomer();
        self::cleanupCoupon();

        $residue = self::residueCounts();
        if ($residue['customer'] !== 0 || $residue['order'] !== 0) {
            throw new RuntimeException(
                'Exact fixture marker residue remains after cleanup: '
                . wp_json_encode($residue)
            );
        }

        $historicalOrderIds = array_map('intval', array_keys(self::$orderHistory));
        $orderResidue = [
            self::orderConfig()['table'] => self::countCapturedRows(
                self::orderConfig()['table'],
                'id',
                $historicalOrderIds,
                [],
                'Order history exact-ID residue check'
            ),
        ];
        foreach (self::orderConfig()['related_rows'] as $related) {
            $where = isset($related['where']) && is_array($related['where'])
                ? $related['where']
                : [];
            $orderResidue[$related['table']] = self::countCapturedRows(
                $related['table'],
                $related['foreign_key'],
                $historicalOrderIds,
                $where,
                'Order history related-row residue check'
            );
        }
        if (array_sum($orderResidue) !== 0) {
            throw new RuntimeException(
                'Exact Order fixture history residue remains after cleanup: '
                . wp_json_encode($orderResidue)
            );
        }

        $sharedResidue = self::sharedResidueCounts();
        if (array_sum($sharedResidue) !== 0) {
            throw new RuntimeException(
                'Exact shared fixture ID residue remains after cleanup: '
                . wp_json_encode($sharedResidue)
            );
        }

        $reportResidue = self::reportResidueCounts();
        if (array_sum($reportResidue) !== 0) {
            throw new RuntimeException(
                'Exact report fixture ID residue remains after cleanup: '
                . wp_json_encode($reportResidue)
            );
        }
    }

    /**
     * Delete only the exact owned Customer and prove both ownership keys absent.
     *
     * The ownership registry is retained on failure so the runner's outer
     * finally/shutdown backstops can retry the same exact-ID cleanup.
     *
     * @return void
     */
    public static function cleanupCustomer()
    {
        if (self::$customer === null) {
            return;
        }
        if (self::$orders) {
            throw new LogicException('Clean owned Orders before cleaning their Customer.');
        }

        $owned = self::$customer;
        $modelClass = self::fixtureConfig()['model_class'];
        $errorOffset = self::databaseErrorOffset();

        try {
            $customer = $modelClass::query()->find($owned['id']);
            self::throwOnDatabaseErrors('Customer fixture cleanup lookup', $errorOffset);

            if ($customer) {
                $actualIdentity = (string) $customer->{self::fixtureConfig()['identity_column']};
                if ($actualIdentity !== $owned['identity']) {
                    throw new LogicException(
                        'Refusing to delete Customer ID ' . $owned['id']
                        . ' because its ownership identity changed.'
                    );
                }

                $errorOffset = self::databaseErrorOffset();
                $deleted = $customer->delete();
                self::throwOnDatabaseErrors('Customer fixture exact-ID delete', $errorOffset);
                if ($deleted !== true) {
                    throw new RuntimeException(
                        'Customer model did not confirm deletion of exact owned ID '
                        . $owned['id'] . '.'
                    );
                }
            }

            $errorOffset = self::databaseErrorOffset();
            $remainingById = $modelClass::query()->find($owned['id']);
            self::throwOnDatabaseErrors('Customer fixture ID absence check', $errorOffset);
            if ($remainingById) {
                throw new RuntimeException(
                    'Customer fixture cleanup left exact owned ID ' . $owned['id'] . '.'
                );
            }

            $remainingByIdentity = self::residueCount($owned['identity']);
            if ($remainingByIdentity !== 0) {
                throw new RuntimeException(
                    'Customer fixture cleanup left exact identity '
                    . $owned['identity'] . ' count=' . $remainingByIdentity . '.'
                );
            }

            self::$customer = null;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Exact Customer fixture cleanup failed for ID ' . $owned['id']
                . ' / ' . $owned['identity'] . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Count only the exact configured identity; never use this as a delete selector.
     *
     * @param string|null $identity
     * @return int
     */
    public static function residueCount($identity = null)
    {
        global $wpdb;

        $identity = $identity === null ? self::identity() : (string) $identity;
        $fixtureConfig = self::fixtureConfig();
        $table = self::validatedIdentifier(
            $wpdb->prefix . $fixtureConfig['table'],
            'fixture table'
        );
        $column = self::validatedIdentifier(
            $fixtureConfig['identity_column'],
            'fixture identity column'
        );

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %s",
            $identity
        ));
        if ($wpdb->last_error !== '') {
            throw new RuntimeException(
                'Exact Customer fixture residue query failed: ' . $wpdb->last_error
            );
        }

        return (int) $count;
    }

    /**
     * Return the exact per-run Order note marker.
     *
     * @param string|null $identity
     * @return string
     */
    public static function orderMarker($identity = null)
    {
        $identity = $identity === null ? self::identity() : (string) $identity;

        return self::orderConfig()['identity_prefix'] . $identity;
    }

    /**
     * Count only the exact Order identity marker; never delete by this marker.
     *
     * @param string|null $identity
     * @return int
     */
    public static function orderResidueCount($identity = null)
    {
        $identity = $identity === null ? self::identity() : (string) $identity;

        return self::countExactRows(
            self::orderConfig()['table'],
            [self::orderConfig()['identity_column'] => self::orderMarker($identity)],
            'Exact Order fixture marker residue query'
        );
    }

    /**
     * @param string|null $identity
     * @return array{customer:int,order:int}
     */
    public static function residueCounts($identity = null)
    {
        $identity = $identity === null ? self::identity() : (string) $identity;

        return [
            'customer' => self::residueCount($identity),
            'order'    => self::orderResidueCount($identity),
        ];
    }

    /**
     * Count every exact captured shared/parent fixture ID by physical table.
     *
     * The captured-ID history intentionally survives cleanup so the runner can
     * prove exact absence after every case and during its outer invariant.
     *
     * @return array<string,int>
     */
    public static function sharedResidueCounts()
    {
        $counts = [];
        foreach (self::sharedConfig()['rows'] as $rowConfig) {
            $counts[$rowConfig['table']] = 0;
        }
        $couponConfig = self::sharedConfig()['coupon'];
        $counts[$couponConfig['table']] = 0;

        foreach (self::$sharedRowHistory as $rows) {
            if (!$rows) {
                continue;
            }
            $row = reset($rows);
            $counts[$row['table']] += self::countCapturedRows(
                $row['table'],
                'id',
                array_map('intval', array_keys($rows)),
                [],
                'Shared fixture history exact-ID residue check'
            );
        }

        $counts[$couponConfig['table']] += self::countCapturedRows(
            $couponConfig['table'],
            'id',
            array_map('intval', array_keys(self::$couponHistory)),
            [],
            'Coupon fixture history exact-ID residue check'
        );

        return $counts;
    }

    /**
     * Count deterministic Phase 6 markers from a fresh outer WP-CLI process.
     *
     * Every predicate is an exact identity-derived value. This is detection
     * only; cleanup always requires captured primary IDs in the owning process.
     *
     * @param string|null $identity
     * @return array<string,int>
     */
    public static function sharedMarkerResidueCounts($identity = null)
    {
        $identity = $identity === null ? self::identity() : (string) $identity;
        if ($identity !== self::identity()) {
            throw new LogicException('Shared marker checks require the initialized identity.');
        }

        $shared = self::sharedConfig();
        $coupon = $shared['coupon'];
        $counts = [
            $coupon['table'] => self::countExactRows(
                $coupon['table'],
                [$coupon['identity_column'] => self::couponCode($identity)],
                'Coupon exact marker residue check'
            ),
        ];

        $activityTable = $shared['rows']['activity']['table'];
        $counts[$activityTable] = 0;
        foreach ([
            'activity-order-correct',
            'activity-order-decoy',
            'activity-coupon-correct',
            'activity-coupon-decoy',
            'report-recent-activity',
        ] as $suffix) {
            $counts[$activityTable] += self::countExactRows(
                $activityTable,
                ['content' => self::sharedValue($suffix)],
                'Activity exact marker residue check'
            );
        }

        $labelTable = $shared['rows']['label']['table'];
        $relationshipTable = $shared['rows']['label_relationship']['table'];
        $counts[$labelTable] = 0;
        $counts[$relationshipTable] = 0;
        $labelClass = $shared['rows']['label']['model_class'];
        foreach (['label-order', 'label-customer'] as $suffix) {
            $value = self::sharedValue($suffix);
            $counts[$labelTable] += self::countExactRows(
                $labelTable,
                ['value' => $value],
                'Label exact marker residue check'
            );
            $errorOffset = self::databaseErrorOffset();
            $label = $labelClass::query()->where('value', $value)->first();
            self::throwOnDatabaseErrors('Label marker relationship lookup', $errorOffset);
            if ($label) {
                $counts[$relationshipTable] += self::countExactRows(
                    $relationshipTable,
                    ['label_id' => (int) $label->id],
                    'LabelRelationship exact parent-ID residue check'
                );
            }
        }

        $metaTable = $shared['rows']['meta']['table'];
        $counts[$metaTable] = self::countExactRows(
            $metaTable,
            ['meta_key' => self::sharedValue('coupon-meta-key')],
            'Meta exact marker residue check'
        );

        $productMetaTable = $shared['rows']['product_meta']['table'];
        $counts[$productMetaTable] = self::countExactRows(
            $productMetaTable,
            ['object_id' => self::syntheticProductMetaObjectId()],
            'ProductMeta exact marker residue check'
        );

        $customerAddressTable = $shared['rows']['customer_address']['table'];
        $counts[$customerAddressTable] = 0;
        foreach (['billing', 'shipping'] as $type) {
            $counts[$customerAddressTable] += self::countExactRows(
                $customerAddressTable,
                ['address_1' => self::sharedValue('customer-address-' . $type)],
                'CustomerAddress exact marker residue check'
            );
        }

        $taxRateTable = $shared['rows']['tax_rate']['table'];
        $counts[$taxRateTable] = self::countExactRows(
            $taxRateTable,
            ['name' => self::sharedValue('tax-rate')],
            'TaxRate exact marker residue check'
        );

        return $counts;
    }

    /**
     * Return every exact shared fixture ID captured during this process.
     *
     * @return array<string,array<int,int>>
     */
    public static function ownedSharedIds()
    {
        $ids = [];
        foreach (self::$sharedRowHistory as $kind => $rows) {
            $ids[$kind] = array_map('intval', array_keys($rows));
        }
        if (self::$couponHistory) {
            $ids['coupon'] = array_map('intval', array_keys(self::$couponHistory));
        }

        return $ids;
    }

    /**
     * Count every captured Phase 7 child-row ID by physical table.
     *
     * @return array<string,int>
     */
    public static function reportResidueCounts()
    {
        $counts = [];
        foreach (self::reportConfig()['rows'] as $rowConfig) {
            $counts[$rowConfig['table']] = 0;
        }

        foreach (self::$reportRowHistory as $rows) {
            if (!$rows) {
                continue;
            }
            $row = reset($rows);
            $counts[$row['table']] += self::countCapturedRows(
                $row['table'],
                'id',
                array_map('intval', array_keys($rows)),
                [],
                'Report fixture history exact-ID residue check'
            );
        }

        return $counts;
    }

    /**
     * Count exact identity-derived Phase 7 markers from an outer process.
     *
     * @param string|null $identity
     * @return array<string,int>
     */
    public static function reportMarkerResidueCounts($identity = null)
    {
        $identity = $identity === null ? self::identity() : (string) $identity;
        if ($identity !== self::identity()) {
            throw new LogicException('Report marker checks require the initialized identity.');
        }

        $report = self::reportConfig();
        return [
            $report['rows']['order_item']['table'] => self::countExactRows(
                $report['rows']['order_item']['table'],
                ['post_title' => self::reportMarker('product')],
                'Report OrderItem marker residue check'
            ),
            $report['rows']['order_address']['table'] => self::countExactRows(
                $report['rows']['order_address']['table'],
                ['name' => self::reportMarker('address')],
                'Report OrderAddress marker residue check'
            ),
            $report['rows']['order_operation']['table'] => self::countExactRows(
                $report['rows']['order_operation']['table'],
                ['utm_source' => self::reportMarker('source')],
                'Report OrderOperation marker residue check'
            ),
        ];
    }

    /**
     * Return every exact Phase 7 child-row ID captured during this process.
     *
     * @return array<string,array<int,int>>
     */
    public static function ownedReportIds()
    {
        $ids = [];
        foreach (self::$reportRowHistory as $kind => $rows) {
            $ids[$kind] = array_map('intval', array_keys($rows));
        }

        return $ids;
    }

    /**
     * Count every configured exact relation for one owned Order ID.
     *
     * @param int $orderId
     * @return array<string,int>
     */
    public static function orderRelatedCounts($orderId)
    {
        $counts = [];
        foreach (self::orderConfig()['related_rows'] as $related) {
            $where = isset($related['where']) && is_array($related['where'])
                ? $related['where']
                : [];
            $where = array_merge(
                [$related['foreign_key'] => (int) $orderId],
                $where
            );
            $counts[$related['table']] = self::countExactRows(
                $related['table'],
                $where,
                'Order related-row residue query'
            );
        }

        return $counts;
    }

    /**
     * Return every exact primary ID captured during this process.
     *
     * @return array<int,int>
     */
    public static function ownedOrderIds()
    {
        return array_map('intval', array_keys(self::$orderHistory));
    }

    /**
     * Read every configured protected-table count.
     *
     * @return array<string,int>
     */
    public static function protectedCounts()
    {
        global $wpdb;

        $config = self::suiteConfig();
        $counts = [];
        foreach ($config['protected_tables'] as $table) {
            $fullTable = self::validatedIdentifier($wpdb->prefix . $table, 'protected table');
            $counts[$table] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$fullTable}`"
            );
            if ($wpdb->last_error !== '') {
                throw new RuntimeException(
                    'Protected count query failed for ' . $fullTable . ': '
                    . $wpdb->last_error
                );
            }
        }

        return $counts;
    }

    /**
     * @param string              $kind
     * @param array<string,mixed> $data
     * @return object
     */
    private static function createSharedRow($kind, array $data)
    {
        $kind = (string) $kind;
        $shared = self::sharedConfig();
        if (!isset($shared['rows'][$kind])) {
            throw new InvalidArgumentException('Unknown shared fixture row kind: ' . $kind);
        }

        $config = $shared['rows'][$kind];
        $modelClass = $config['model_class'];
        $errorOffset = self::databaseErrorOffset();

        try {
            $row = $modelClass::query()->create($data);
            $id = isset($row->id) ? (int) $row->id : 0;
            if ($id > 0) {
                $owned = [
                    'id'          => $id,
                    'kind'        => $kind,
                    'table'       => $config['table'],
                    'model_class' => $modelClass,
                    'expected'    => $data,
                ];
                if (!isset(self::$sharedRows[$kind])) {
                    self::$sharedRows[$kind] = [];
                }
                if (!isset(self::$sharedRowHistory[$kind])) {
                    self::$sharedRowHistory[$kind] = [];
                }
                self::$sharedRows[$kind][$id] = $owned;
                self::$sharedRowHistory[$kind][$id] = $owned;
            }

            self::throwOnDatabaseErrors('Shared ' . $kind . ' model create', $errorOffset);
            if ($id <= 0) {
                throw new RuntimeException(
                    'Shared ' . $kind . ' model create returned no positive primary ID.'
                );
            }

            return $row;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Shared ' . $kind . ' model/DB fixture create failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @param string              $kind
     * @param array<string,mixed> $data
     * @return object
     */
    private static function createReportRow($kind, array $data)
    {
        $kind = (string) $kind;
        $report = self::reportConfig();
        if (!isset($report['rows'][$kind])) {
            throw new InvalidArgumentException('Unknown report fixture row kind: ' . $kind);
        }

        if (isset($data['order_id']) && !isset(self::$orders[(int) $data['order_id']])) {
            throw new LogicException(
                'Refusing report child creation for an unowned Order ID '
                . (int) $data['order_id'] . '.'
            );
        }

        $config = $report['rows'][$kind];
        $modelClass = $config['model_class'];
        $errorOffset = self::databaseErrorOffset();

        try {
            $row = $modelClass::query()->create($data);
            $id = isset($row->id) ? (int) $row->id : 0;
            if ($id > 0) {
                $owned = [
                    'id'          => $id,
                    'kind'        => $kind,
                    'table'       => $config['table'],
                    'model_class' => $modelClass,
                    'order_id'    => isset($data['order_id']) ? (int) $data['order_id'] : 0,
                ];
                if (!isset(self::$reportRows[$kind])) {
                    self::$reportRows[$kind] = [];
                }
                if (!isset(self::$reportRowHistory[$kind])) {
                    self::$reportRowHistory[$kind] = [];
                }
                self::$reportRows[$kind][$id] = $owned;
                self::$reportRowHistory[$kind][$id] = $owned;
            }

            self::throwOnDatabaseErrors('Report ' . $kind . ' model create', $errorOffset);
            if ($id <= 0) {
                throw new RuntimeException(
                    'Report ' . $kind . ' model create returned no positive primary ID.'
                );
            }

            return $row;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Report ' . $kind . ' fixture create failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @param string $kind
     * @param int    $id
     * @return void
     */
    private static function cleanupReportRow($kind, $id)
    {
        $kind = (string) $kind;
        $id = (int) $id;
        if (!isset(self::$reportRows[$kind][$id])) {
            return;
        }

        $owned = self::$reportRows[$kind][$id];
        $modelClass = $owned['model_class'];
        $errorOffset = self::databaseErrorOffset();
        $row = $modelClass::query()->find($id);
        self::throwOnDatabaseErrors('Report fixture cleanup lookup', $errorOffset);

        if ($row) {
            if (
                $owned['order_id'] > 0
                && (int) $row->order_id !== (int) $owned['order_id']
            ) {
                throw new LogicException(
                    'Refusing report fixture cleanup because owned Order ID changed for '
                    . $kind . ' ID ' . $id . '.'
                );
            }

            $errorOffset = self::databaseErrorOffset();
            $deleted = $row->delete();
            self::throwOnDatabaseErrors('Report fixture exact-ID delete', $errorOffset);
            if ($deleted !== true) {
                throw new RuntimeException(
                    'Report model did not confirm exact-ID deletion for '
                    . $kind . ' ID ' . $id . '.'
                );
            }
        }

        if (self::countExactRows(
            $owned['table'],
            ['id' => $id],
            'Report fixture exact-ID absence check'
        ) !== 0) {
            throw new RuntimeException(
                'Report fixture cleanup left ' . $kind . ' ID ' . $id . '.'
            );
        }

        unset(self::$reportRows[$kind][$id]);
        if (!self::$reportRows[$kind]) {
            unset(self::$reportRows[$kind]);
        }
    }

    /**
     * @param string $kind
     * @param int    $id
     * @return void
     */
    private static function cleanupSharedRow($kind, $id)
    {
        $kind = (string) $kind;
        $id = (int) $id;
        if (!isset(self::$sharedRows[$kind][$id])) {
            return;
        }

        $owned = self::$sharedRows[$kind][$id];
        $modelClass = $owned['model_class'];

        try {
            $errorOffset = self::databaseErrorOffset();
            $row = $modelClass::query()->find($id);
            self::throwOnDatabaseErrors('Shared ' . $kind . ' cleanup lookup', $errorOffset);
            if ($row) {
                foreach ($owned['expected'] as $column => $expected) {
                    $actual = $row->{$column};
                    if (!self::fixtureValuesEqual($expected, $actual)) {
                        throw new LogicException(
                            'Refusing to delete shared ' . $kind . ' ID ' . $id
                            . ' because exact ownership value changed for ' . $column
                            . ': expected=' . wp_json_encode($expected)
                            . ' actual=' . wp_json_encode($actual)
                        );
                    }
                }

                $errorOffset = self::databaseErrorOffset();
                $deleted = $row->delete();
                self::throwOnDatabaseErrors(
                    'Shared ' . $kind . ' fixture exact-ID delete',
                    $errorOffset
                );
                if ($deleted !== true) {
                    throw new RuntimeException(
                        'Shared ' . $kind . ' model did not confirm deletion of ID ' . $id . '.'
                    );
                }
            }

            if (self::countExactRows(
                $owned['table'],
                ['id' => $id],
                'Shared ' . $kind . ' exact-ID absence check'
            ) !== 0) {
                throw new RuntimeException(
                    'Shared ' . $kind . ' fixture cleanup left exact ID ' . $id . '.'
                );
            }

            unset(self::$sharedRows[$kind][$id]);
            if (!self::$sharedRows[$kind]) {
                unset(self::$sharedRows[$kind]);
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Exact shared ' . $kind . ' cleanup failed for ID '
                . $id . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Compare exact fixture values while tolerating DB integer stringification.
     *
     * @param mixed $expected
     * @param mixed $actual
     * @return bool
     */
    private static function fixtureValuesEqual($expected, $actual)
    {
        if (is_int($expected)) {
            return (int) $actual === $expected;
        }
        if (is_float($expected)) {
            return (float) $actual === $expected;
        }
        if (is_array($expected)) {
            return is_array($actual) && $actual === $expected;
        }
        if ($expected === null) {
            return $actual === null || $actual === '';
        }

        return (string) $actual === (string) $expected;
    }

    /**
     * @return int
     */
    private static function syntheticProductMetaObjectId()
    {
        return 700000000
            + (hexdec(substr(hash('sha256', self::identity()), 0, 7)) % 200000000);
    }

    /**
     * @return int
     */
    private static function databaseErrorOffset()
    {
        global $wpdb, $EZSQL_ERROR;

        $wpdb->last_error = '';

        return is_array($EZSQL_ERROR) ? count($EZSQL_ERROR) : 0;
    }

    /**
     * Fail on every query error since the supplied offset.
     *
     * @param string $operation
     * @param int    $offset
     * @return void
     */
    private static function throwOnDatabaseErrors($operation, $offset)
    {
        global $wpdb, $EZSQL_ERROR;

        $details = [];
        $errors = is_array($EZSQL_ERROR) ? array_slice($EZSQL_ERROR, $offset) : [];
        foreach ($errors as $error) {
            if (!is_array($error) || empty($error['error_str'])) {
                continue;
            }

            $detail = (string) $error['error_str'];
            if (!empty($error['query'])) {
                $detail .= ' | query: ' . trim((string) $error['query']);
            }
            $details[] = $detail;
        }

        if (!$details && $wpdb->last_error !== '') {
            $details[] = (string) $wpdb->last_error;
        }

        if ($details) {
            throw new RuntimeException(
                'DATABASE ERROR during ' . $operation . ': ' . implode(' || ', $details)
            );
        }
    }

    /**
     * @param mixed $callback
     * @return string
     */
    private static function callbackName($callback)
    {
        if (is_array($callback) && count($callback) === 2) {
            $owner = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
            return $owner . '::' . (string) $callback[1];
        }
        if ($callback instanceof \Closure) {
            $reflection = new \ReflectionFunction($callback);
            return 'Closure@' . $reflection->getFileName() . ':' . $reflection->getStartLine();
        }
        if (is_string($callback)) {
            return $callback;
        }

        return gettype($callback);
    }

    /**
     * Count rows selected only by exact equality predicates.
     *
     * @param string              $table
     * @param array<string,mixed> $where
     * @param string              $operation
     * @return int
     */
    private static function countExactRows($table, array $where, $operation)
    {
        global $wpdb;

        if (!$where) {
            throw new LogicException('Exact row count requires at least one predicate.');
        }

        $table = self::validatedIdentifier($wpdb->prefix . $table, 'fixture table');
        $clauses = [];
        $values = [];
        foreach ($where as $column => $value) {
            $column = self::validatedIdentifier($column, 'fixture predicate column');
            $clauses[] = '`' . $column . '` = ' . (is_int($value) ? '%d' : '%s');
            $values[] = $value;
        }

        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE " . implode(' AND ', $clauses);
        $prepared = call_user_func_array(
            [$wpdb, 'prepare'],
            array_merge([$sql], $values)
        );
        $errorOffset = self::databaseErrorOffset();
        $count = $wpdb->get_var($prepared);
        self::throwOnDatabaseErrors($operation, $errorOffset);

        return (int) $count;
    }

    /**
     * Count rows belonging to captured integer keys in one query.
     *
     * @param string              $table
     * @param string              $keyColumn
     * @param array<int,int>      $ids
     * @param array<string,mixed> $where
     * @param string              $operation
     * @return int
     */
    private static function countCapturedRows(
        $table,
        $keyColumn,
        array $ids,
        array $where,
        $operation
    ) {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));
        if (!$ids) {
            return 0;
        }

        $table = self::validatedIdentifier($wpdb->prefix . $table, 'fixture table');
        $keyColumn = self::validatedIdentifier($keyColumn, 'fixture captured-key column');
        $clauses = [sprintf(
            '`%s` IN (%s)',
            $keyColumn,
            implode(',', array_fill(0, count($ids), '%d'))
        )];
        $values = $ids;
        foreach ($where as $column => $value) {
            $column = self::validatedIdentifier($column, 'fixture predicate column');
            $clauses[] = '`' . $column . '` = ' . (is_int($value) ? '%d' : '%s');
            $values[] = $value;
        }

        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE " . implode(' AND ', $clauses);
        $prepared = call_user_func_array(
            [$wpdb, 'prepare'],
            array_merge([$sql], $values)
        );
        $errorOffset = self::databaseErrorOffset();
        $count = $wpdb->get_var($prepared);
        self::throwOnDatabaseErrors($operation, $errorOffset);

        return (int) $count;
    }

    /**
     * Delete rows selected only by an exact captured key plus exact predicates.
     *
     * @param string              $table
     * @param array<string,mixed> $where
     * @param string              $operation
     * @return void
     */
    private static function deleteExactRows($table, array $where, $operation)
    {
        global $wpdb;

        if (!$where) {
            throw new LogicException('Exact row deletion requires at least one predicate.');
        }

        $table = self::validatedIdentifier($wpdb->prefix . $table, 'fixture table');
        $validatedWhere = [];
        $formats = [];
        foreach ($where as $column => $value) {
            $column = self::validatedIdentifier($column, 'fixture predicate column');
            $validatedWhere[$column] = $value;
            $formats[] = is_int($value) ? '%d' : '%s';
        }

        $errorOffset = self::databaseErrorOffset();
        $deleted = $wpdb->delete($table, $validatedWhere, $formats);
        self::throwOnDatabaseErrors($operation . ' (' . $table . ')', $errorOffset);
        if ($deleted === false) {
            throw new RuntimeException($operation . ' failed for ' . $table . '.');
        }

        $remaining = self::countExactRows(
            substr($table, strlen($wpdb->prefix)),
            $validatedWhere,
            $operation . ' absence check'
        );
        if ($remaining !== 0) {
            throw new RuntimeException(
                $operation . ' left ' . $remaining . ' exact row(s) in ' . $table . '.'
            );
        }
    }

    /**
     * Update only an exact owned row using an explicit field allowlist.
     *
     * @param string              $table
     * @param int                 $id
     * @param string              $ownershipColumn
     * @param mixed               $ownershipValue
     * @param array<string,mixed> $attributes
     * @param array<int,string>   $allowed
     * @param string              $operation
     * @param array<string,mixed> $extraWhere
     * @return void
     */
    private static function updateExactOwnedRow(
        $table,
        $id,
        $ownershipColumn,
        $ownershipValue,
        array $attributes,
        array $allowed,
        $operation,
        array $extraWhere = []
    ) {
        global $wpdb;

        if (!$attributes) {
            throw new InvalidArgumentException($operation . ' requires at least one scalar field.');
        }

        $allowedLookup = array_fill_keys($allowed, true);
        $data = [];
        $formats = [];
        foreach ($attributes as $column => $value) {
            if (!isset($allowedLookup[$column])) {
                throw new InvalidArgumentException(
                    $operation . ' does not allow field: ' . $column
                );
            }
            $column = self::validatedIdentifier($column, 'owned update column');
            if (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException(
                    $operation . ' accepts scalar/null fields only: ' . $column
                );
            }
            $data[$column] = $value;
            $formats[] = is_int($value) ? '%d' : (is_float($value) ? '%f' : '%s');
        }

        $tableName = self::validatedIdentifier($wpdb->prefix . $table, 'owned update table');
        $where = array_merge(
            [
                'id' => (int) $id,
                self::validatedIdentifier($ownershipColumn, 'ownership column') => $ownershipValue,
            ],
            $extraWhere
        );
        $whereFormats = [];
        foreach ($where as $column => $value) {
            self::validatedIdentifier($column, 'owned update predicate');
            $whereFormats[] = is_int($value) ? '%d' : '%s';
        }

        if (self::countExactRows($table, $where, $operation . ' ownership check') !== 1) {
            throw new LogicException(
                $operation . ' refused because the exact ownership predicate did not match one row.'
            );
        }

        $errorOffset = self::databaseErrorOffset();
        $updated = $wpdb->update($tableName, $data, $where, $formats, $whereFormats);
        self::throwOnDatabaseErrors($operation, $errorOffset);
        if ($updated === false) {
            throw new RuntimeException($operation . ' failed.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function orderConfig()
    {
        $fixtureConfig = self::fixtureConfig();
        if (empty($fixtureConfig['order']) || !is_array($fixtureConfig['order'])) {
            throw new RuntimeException('integration_fixture.order is missing from suite.config.php.');
        }

        $required = [
            'model_class',
            'resource_class',
            'table',
            'identity_column',
            'identity_prefix',
            'activity_table',
            'related_rows',
            'status_hook_allowlist',
        ];
        foreach ($required as $key) {
            if (!isset($fixtureConfig['order'][$key]) || $fixtureConfig['order'][$key] === '') {
                throw new RuntimeException(
                    'integration_fixture.order.' . $key . ' is missing from suite.config.php.'
                );
            }
        }

        return $fixtureConfig['order'];
    }

    /**
     * @return array<string,mixed>
     */
    private static function reportConfig()
    {
        $fixtureConfig = self::fixtureConfig();
        if (empty($fixtureConfig['reports']) || !is_array($fixtureConfig['reports'])) {
            throw new RuntimeException('integration_fixture.reports is missing from suite.config.php.');
        }

        $report = $fixtureConfig['reports'];
        if (
            empty($report['window'])
            || !is_array($report['window'])
            || empty($report['window']['start'])
            || empty($report['window']['end'])
            || empty($report['window']['future'])
            || empty($report['window']['future_floor'])
            || empty($report['rows'])
            || !is_array($report['rows'])
            || empty($report['cleanup_order'])
            || !is_array($report['cleanup_order'])
        ) {
            throw new RuntimeException(
                'integration_fixture.reports configuration is incomplete in suite.config.php.'
            );
        }

        foreach ($report['rows'] as $kind => $row) {
            if (empty($row['model_class']) || empty($row['table'])) {
                throw new RuntimeException(
                    'integration_fixture.reports.rows.' . $kind . ' is incomplete.'
                );
            }
        }

        return $report;
    }

    /**
     * @return array<string,mixed>
     */
    private static function sharedConfig()
    {
        $fixtureConfig = self::fixtureConfig();
        if (empty($fixtureConfig['shared']) || !is_array($fixtureConfig['shared'])) {
            throw new RuntimeException('integration_fixture.shared is missing from suite.config.php.');
        }

        $shared = $fixtureConfig['shared'];
        if (
            empty($shared['coupon']['model_class'])
            || empty($shared['coupon']['table'])
            || empty($shared['coupon']['identity_column'])
            || empty($shared['coupon']['identity_prefix'])
            || empty($shared['rows'])
            || !is_array($shared['rows'])
            || empty($shared['cleanup_order'])
            || !is_array($shared['cleanup_order'])
        ) {
            throw new RuntimeException(
                'integration_fixture.shared configuration is incomplete in suite.config.php.'
            );
        }

        foreach ($shared['rows'] as $kind => $row) {
            if (empty($row['model_class']) || empty($row['table'])) {
                throw new RuntimeException(
                    'integration_fixture.shared.rows.' . $kind . ' is incomplete.'
                );
            }
        }

        return $shared;
    }

    /**
     * @return array<string,mixed>
     */
    private static function fixtureConfig()
    {
        $config = self::suiteConfig();
        if (empty($config['integration_fixture']) || !is_array($config['integration_fixture'])) {
            throw new RuntimeException('integration_fixture is missing from suite.config.php.');
        }

        $required = ['model_class', 'table', 'identity_column', 'identity_domain'];
        foreach ($required as $key) {
            if (empty($config['integration_fixture'][$key])) {
                throw new RuntimeException(
                    'integration_fixture.' . $key . ' is missing from suite.config.php.'
                );
            }
        }

        return $config['integration_fixture'];
    }

    /**
     * @return array<string,mixed>
     */
    private static function suiteConfig()
    {
        if (self::$config === null) {
            self::$config = require dirname(__DIR__) . '/suite.config.php';
        }

        return self::$config;
    }

    /**
     * @param string $identifier
     * @param string $label
     * @return string
     */
    private static function validatedIdentifier($identifier, $label)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new RuntimeException('Unsafe ' . $label . ' identifier in suite configuration.');
        }

        return $identifier;
    }
}
