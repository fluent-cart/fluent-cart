<?php
/**
 * Exact-ID fixtures for Phase 18 customer-portal UUID ownership checks.
 *
 * Customer, Order, OrderTransaction, and Subscription rows are created and
 * deleted through their real models. Raw wpdb reads are limited to immutable
 * snapshots, exact-key related-row cleanup, and residue assertions.
 */

class FcPublicSurfaceFixture
{
    /** @var array<int,array<string,mixed>> */
    private static $active = [];

    /** @var array<int,array<string,mixed>> */
    private static $history = [];

    /** @var array<string,mixed>|null */
    private static $config = null;

    /**
     * Create two linked WordPress users/customers with inert records.
     *
     * @param string $suffix
     * @return array<string,array<string,mixed>>
     */
    public static function pair($suffix)
    {
        $suffix = preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $suffix));
        $suffix = trim($suffix, '-');
        if ($suffix === '') {
            throw new InvalidArgumentException('Phase 18 fixture suffix cannot be empty.');
        }

        $pair = [];
        foreach (['a', 'b'] as $actor) {
            $pair[$actor] = self::actor($suffix . '-' . $actor);
        }

        return $pair;
    }

    /**
     * Log in as one owned WordPress user and reset plugin customer caches.
     *
     * @param array<string,mixed> $actor
     * @return void
     */
    public static function login(array $actor)
    {
        wp_set_current_user((int) $actor['user_id']);
        \FluentCart\App\Helpers\Helper::getCurrentUser(true);
        \FluentCart\Api\Resource\CustomerResource::resetCurrentCustomerRuntimeCache();
    }

    /**
     * Snapshot every owned row plus exact related rows that UUID routes could
     * mutate. Equality before/after is the Phase 18 "change nothing" oracle.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function snapshot(array $actor)
    {
        global $wpdb;

        $tables = self::tables();
        $moduleTypes = self::config()['activity_module_types'];
        $customerId = (int) $actor['customer_id'];
        $orderId = (int) $actor['order_id'];
        $transactionId = (int) $actor['transaction_id'];
        $subscriptionId = (int) $actor['subscription_id'];

        return [
            'customer' => $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$tables['customer']}` WHERE `id` = %d",
                $customerId
            ), ARRAY_A),
            'order' => $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$tables['order']}` WHERE `id` = %d",
                $orderId
            ), ARRAY_A),
            'transaction' => $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$tables['transaction']}` WHERE `id` = %d",
                $transactionId
            ), ARRAY_A),
            'subscription' => $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$tables['subscription']}` WHERE `id` = %d",
                $subscriptionId
            ), ARRAY_A),
            'order_addresses' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM `{$tables['order_address']}`
                 WHERE `order_id` = %d ORDER BY `id` ASC",
                $orderId
            ), ARRAY_A),
            'order_meta' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM `{$tables['order_meta']}`
                 WHERE `order_id` = %d ORDER BY `id` ASC",
                $orderId
            ), ARRAY_A),
            'subscription_meta' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM `{$tables['subscription_meta']}`
                 WHERE `subscription_id` = %d ORDER BY `id` ASC",
                $subscriptionId
            ), ARRAY_A),
            'order_activity' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM `{$tables['activity']}`
                 WHERE `module_type` = %s AND `module_id` = %d ORDER BY `id` ASC",
                $moduleTypes['order'],
                $orderId
            ), ARRAY_A),
            'subscription_activity' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM `{$tables['activity']}`
                 WHERE `module_type` = %s AND `module_id` = %d ORDER BY `id` ASC",
                $moduleTypes['subscription'],
                $subscriptionId
            ), ARRAY_A),
        ];
    }

    /**
     * @return array<string,int>
     */
    public static function residueCounts()
    {
        global $wpdb;

        $tables = self::tables();
        $counts = [
            'wp_user'      => 0,
            'customer'     => 0,
            'order'        => 0,
            'transaction'  => 0,
            'subscription' => 0,
        ];

        $columns = [
            'wp_user'      => [$wpdb->users, 'ID', 'user_id'],
            'customer'     => [$tables['customer'], 'id', 'customer_id'],
            'order'        => [$tables['order'], 'id', 'order_id'],
            'transaction'  => [$tables['transaction'], 'id', 'transaction_id'],
            'subscription' => [$tables['subscription'], 'id', 'subscription_id'],
        ];
        foreach ($columns as $kind => $definition) {
            [$table, $column, $historyKey] = $definition;
            $ids = array_values(array_unique(array_filter(array_map(
                function ($owned) use ($historyKey) {
                    return (int) $owned[$historyKey];
                },
                self::$history
            ))));
            if ($ids) {
                $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` IN ("
                    . implode(',', array_fill(0, count($ids), '%d')) . ')';
                $counts[$kind] = (int) $wpdb->get_var(call_user_func_array(
                    [$wpdb, 'prepare'],
                    array_merge([$sql], $ids)
                ));
            }
        }

        return $counts;
    }

    /**
     * @return array<string,int>
     */
    public static function markerResidueCounts()
    {
        global $wpdb;

        $tables = self::tables();
        $prefix = self::markerPrefix();

        return [
            'wp_user' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->users}` WHERE `user_email` LIKE %s",
                $prefix . '%@' . self::config()['identity_domain']
            )),
            'customer' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$tables['customer']}` WHERE `email` LIKE %s",
                $prefix . '%@' . self::config()['identity_domain']
            )),
            'order' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$tables['order']}` WHERE `note` LIKE %s",
                'Owned Phase 18 order ' . $prefix . '%'
            )),
            'transaction' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$tables['transaction']}`
                 WHERE `vendor_charge_id` LIKE %s",
                $prefix . '%'
            )),
            'subscription' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$tables['subscription']}`
                 WHERE `vendor_subscription_id` LIKE %s",
                self::subscriptionMarkerPrefix() . '%'
            )),
        ];
    }

    /**
     * Delete only immediately captured primary keys after rechecking markers.
     *
     * @return void
     */
    public static function cleanupAll()
    {
        global $wpdb;

        $tables = self::tables();
        $moduleTypes = self::config()['activity_module_types'];
        foreach (array_reverse(array_keys(self::$active)) as $ownerKey) {
            $owned = self::$active[$ownerKey];

            $subscriptionClass = self::rowConfig('subscription')['model_class'];
            $subscription = $subscriptionClass::query()->find((int) $owned['subscription_id']);
            if ($subscription) {
                if (
                    (int) $subscription->customer_id !== (int) $owned['customer_id']
                    || (string) $subscription->vendor_subscription_id
                        !== (string) $owned['subscription_marker']
                ) {
                    throw new LogicException('Refusing to delete Phase 18 Subscription: ownership changed.');
                }
                $wpdb->delete(
                    $tables['subscription_meta'],
                    ['subscription_id' => (int) $subscription->id],
                    ['%d']
                );
                $wpdb->delete(
                    $tables['activity'],
                    [
                        'module_type' => $moduleTypes['subscription'],
                        'module_id'   => (int) $subscription->id,
                    ],
                    ['%s', '%d']
                );
                if ($subscription->delete() !== true) {
                    throw new RuntimeException('Phase 18 Subscription model did not confirm delete.');
                }
            }

            $transactionClass = self::rowConfig('transaction')['model_class'];
            $transaction = $transactionClass::query()->find((int) $owned['transaction_id']);
            if ($transaction) {
                if (
                    (int) $transaction->order_id !== (int) $owned['order_id']
                    || (string) $transaction->vendor_charge_id
                        !== (string) $owned['transaction_marker']
                ) {
                    throw new LogicException('Refusing to delete Phase 18 Transaction: ownership changed.');
                }
                if ($transaction->delete() !== true) {
                    throw new RuntimeException('Phase 18 Transaction model did not confirm delete.');
                }
            }

            $orderClass = self::rowConfig('order')['model_class'];
            $order = $orderClass::query()->find((int) $owned['order_id']);
            if ($order) {
                if (
                    (int) $order->customer_id !== (int) $owned['customer_id']
                    || (string) $order->note !== (string) $owned['order_marker']
                ) {
                    throw new LogicException('Refusing to delete Phase 18 Order: ownership changed.');
                }
                foreach (self::config()['cleanup_order_rows'] as $relatedKey) {
                    $wpdb->delete(
                        $tables[$relatedKey],
                        ['order_id' => (int) $order->id],
                        ['%d']
                    );
                }
                $wpdb->delete(
                    $tables['activity'],
                    [
                        'module_type' => $moduleTypes['order'],
                        'module_id'   => (int) $order->id,
                    ],
                    ['%s', '%d']
                );
                if ($order->delete() !== true) {
                    throw new RuntimeException('Phase 18 Order model did not confirm delete.');
                }
            }

            $customerClass = self::rowConfig('customer')['model_class'];
            $customer = $customerClass::query()->find((int) $owned['customer_id']);
            if ($customer) {
                if ((string) $customer->email !== (string) $owned['email']) {
                    throw new LogicException('Refusing to delete Phase 18 Customer: ownership changed.');
                }
                if ($customer->delete() !== true) {
                    throw new RuntimeException('Phase 18 Customer model did not confirm delete.');
                }
            }

            if (get_user_by('id', (int) $owned['user_id'])) {
                if (!function_exists('wp_delete_user')) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                }
                if (wp_delete_user((int) $owned['user_id']) !== true) {
                    throw new RuntimeException('Phase 18 WordPress user delete failed.');
                }
            }

            unset(self::$active[$ownerKey]);
        }

        if (array_sum(self::residueCounts()) !== 0) {
            throw new RuntimeException(
                'Phase 18 exact fixture residue remains: '
                . wp_json_encode(self::residueCounts())
            );
        }
    }

    /**
     * @param string $suffix
     * @return array<string,mixed>
     */
    private static function actor($suffix)
    {
        $config = self::config();
        $marker = self::marker($suffix);
        $email = $marker . '@' . $config['identity_domain'];
        if (get_user_by('email', $email)) {
            throw new RuntimeException('Phase 18 WordPress user marker already exists.');
        }

        $customerClass = self::rowConfig('customer')['model_class'];
        if ($customerClass::query()->where('email', $email)->count() !== 0) {
            throw new RuntimeException('Phase 18 Customer marker already exists.');
        }

        $userId = wp_insert_user([
            'user_login' => $marker,
            'user_email' => $email,
            'user_pass'  => wp_generate_password(32, true, true),
            'role'       => 'subscriber',
            'first_name' => 'Phase Eighteen',
            'last_name'  => strtoupper(substr($suffix, -1)),
        ]);
        if (is_wp_error($userId) || (int) $userId <= 0) {
            throw new RuntimeException(
                'Phase 18 WordPress user create failed: '
                . (is_wp_error($userId) ? $userId->get_error_message() : 'no ID')
            );
        }

        $owned = [
            'user_id'             => (int) $userId,
            'email'               => $email,
            'customer_id'         => 0,
            'customer_uuid'       => '',
            'order_id'            => 0,
            'order_uuid'          => '',
            'order_marker'        => 'Owned Phase 18 order ' . $marker,
            'transaction_id'      => 0,
            'transaction_uuid'    => '',
            'transaction_marker'  => $marker . '-transaction',
            'subscription_id'     => 0,
            'subscription_uuid'   => '',
            'subscription_marker' => self::subscriptionMarker($marker),
        ];
        // Capture the WordPress primary key before the first later operation
        // that can throw, so even a failed Customer create is recoverable.
        self::$active[$owned['user_id']] = $owned;
        self::$history[$owned['user_id']] = $owned;

        try {
            $customer = $customerClass::query()->create([
                'user_id'        => (int) $userId,
                'email'          => $email,
                'first_name'     => 'Phase Eighteen',
                'last_name'      => strtoupper(substr($suffix, -1)),
                'status'         => 'active',
                'purchase_value' => ['currency' => 'USD', 'gross' => 0],
                'purchase_count' => 0,
                'ltv'            => 0,
                'notes'          => 'Owned Phase 18 customer ' . $marker,
                'country'        => 'BD',
                'city'           => 'Dhaka',
                'state'          => 'C',
                'postcode'       => '1205',
            ]);
            $owned['customer_id'] = isset($customer->id) ? (int) $customer->id : 0;
            $owned['customer_uuid'] = isset($customer->uuid) ? (string) $customer->uuid : '';
            if ($owned['customer_id'] <= 0 || $owned['customer_uuid'] === '') {
                throw new RuntimeException('Phase 18 Customer create returned incomplete identity.');
            }
            self::$active[$owned['user_id']] = $owned;
            self::$history[$owned['user_id']] = $owned;

            $orderClass = self::rowConfig('order')['model_class'];
            $order = $orderClass::query()->create([
                'status'                => 'processing',
                'parent_id'             => null,
                'invoice_no'            => '',
                'receipt_number'        => null,
                'fulfillment_type'      => 'digital',
                'type'                  => 'subscription',
                'customer_id'           => $owned['customer_id'],
                'payment_method'        => '',
                'payment_method_title'  => '',
                'payment_status'        => 'pending',
                'currency'              => 'USD',
                'subtotal'              => 1000,
                'discount_tax'          => 0,
                'manual_discount_total' => 0,
                'coupon_discount_total' => 0,
                'shipping_tax'          => 0,
                'shipping_total'        => 0,
                'fee_total'             => 0,
                'tax_total'             => 0,
                'tax_behavior'          => 0,
                'total_amount'          => 1000,
                'rate'                  => 1,
                'note'                  => $owned['order_marker'],
                'ip_address'            => '192.0.2.18',
                'total_refund'          => 0,
                'total_paid'            => 0,
                'mode'                  => 'test',
                'shipping_status'       => '',
                'config'                => ['fixture_identity' => $marker],
            ]);
            $owned['order_id'] = isset($order->id) ? (int) $order->id : 0;
            $owned['order_uuid'] = isset($order->uuid) ? (string) $order->uuid : '';
            if ($owned['order_id'] <= 0 || $owned['order_uuid'] === '') {
                throw new RuntimeException('Phase 18 Order create returned incomplete identity.');
            }
            self::$active[$owned['user_id']] = $owned;
            self::$history[$owned['user_id']] = $owned;

            $transactionClass = self::rowConfig('transaction')['model_class'];
            $transaction = $transactionClass::query()->create([
                'order_id'            => $owned['order_id'],
                'order_type'          => 'subscription',
                'vendor_charge_id'    => $owned['transaction_marker'],
                'payment_method'      => '',
                'payment_mode'        => 'test',
                'payment_method_type' => '',
                'currency'            => 'USD',
                'transaction_type'    => 'charge',
                'subscription_id'     => null,
                'card_last_4'         => 0,
                'card_brand'          => '',
                'status'              => 'pending',
                'total'               => 1000,
                'rate'                => 1,
                'meta'                => ['fixture_identity' => $marker],
            ]);
            $owned['transaction_id'] = isset($transaction->id) ? (int) $transaction->id : 0;
            $owned['transaction_uuid'] = isset($transaction->uuid)
                ? (string) $transaction->uuid
                : '';
            if ($owned['transaction_id'] <= 0 || $owned['transaction_uuid'] === '') {
                throw new RuntimeException('Phase 18 Transaction create returned incomplete identity.');
            }
            self::$active[$owned['user_id']] = $owned;
            self::$history[$owned['user_id']] = $owned;

            $subscription = self::createSubscription(
                $owned['customer_id'],
                $owned['order_id'],
                $owned['subscription_marker'],
                'active'
            );
            $subscriptionId = (int) $subscription->id;
            $subscriptionClass = self::rowConfig('subscription')['model_class'];
            $subscription = $subscriptionClass::query()->find($subscriptionId);
            if (!$subscription) {
                throw new RuntimeException('Phase 18 Subscription disappeared after create.');
            }
            $owned['subscription_id'] = $subscriptionId;
            $owned['subscription_uuid'] = (string) $subscription->uuid;
            // The installed schema may truncate this legacy vendor column.
            // Capture the physical value returned by the real model immediately
            // and use that exact immutable value for ownership and cleanup.
            $owned['subscription_marker'] = (string) $subscription->vendor_subscription_id;
            self::$active[$owned['user_id']] = $owned;
            self::$history[$owned['user_id']] = $owned;

            return $owned;
        } catch (\Throwable $e) {
            self::cleanupAll();
            throw $e;
        }
    }

    /**
     * @param int    $customerId
     * @param int    $orderId
     * @param string $marker
     * @param string $status
     * @return object
     */
    private static function createSubscription($customerId, $orderId, $marker, $status)
    {
        $class = self::rowConfig('subscription')['model_class'];
        if ($class::query()->where('vendor_subscription_id', $marker)->count() !== 0) {
            throw new RuntimeException('Phase 18 Subscription marker already exists.');
        }

        $subscription = $class::query()->create([
            'customer_id'            => (int) $customerId,
            'parent_order_id'        => (int) $orderId,
            'product_id'             => 0,
            'item_name'              => 'Owned Phase 18 subscription',
            'variation_id'           => 0,
            'billing_interval'       => 'monthly',
            'signup_fee'             => 0,
            'quantity'               => 1,
            'recurring_amount'       => 1000,
            'recurring_tax_total'    => 0,
            'recurring_total'        => 1000,
            'bill_times'             => 0,
            'bill_count'             => 1,
            'expire_at'              => null,
            'trial_ends_at'          => null,
            'canceled_at'            => $status === 'canceled'
                ? '2001-02-03 04:05:06'
                : null,
            'restored_at'            => null,
            'collection_method'      => 'automatic',
            'trial_days'             => 0,
            'vendor_customer_id'     => '',
            'vendor_plan_id'         => '',
            'vendor_subscription_id' => $marker,
            'next_billing_date'      => '2099-02-03 04:05:06',
            'status'                 => (string) $status,
            'original_plan'          => '[]',
            'vendor_response'        => '[]',
            'current_payment_method' => '',
            'config'                 => ['fixture_identity' => $marker],
        ]);
        if (empty($subscription->id) || empty($subscription->uuid)) {
            throw new RuntimeException('Phase 18 Subscription create returned incomplete identity.');
        }

        return $subscription;
    }

    /**
     * Keep the immutable test discriminator inside the installed varchar(45).
     *
     * Non-strict MySQL silently truncated the old full marker. The strict-SQL
     * axis correctly rejects that fixture bug, so use a collision-resistant
     * fixed-length value that is valid under both configurations.
     *
     * @param string $marker
     * @return string
     */
    private static function subscriptionMarker($marker)
    {
        return self::subscriptionMarkerPrefix()
            . substr(hash('sha256', (string) $marker), 0, 32);
    }

    /**
     * @return string
     */
    private static function subscriptionMarkerPrefix()
    {
        return 'wp-p18-';
    }

    /**
     * @param string $suffix
     * @return string
     */
    private static function marker($suffix)
    {
        $suffix = preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $suffix));
        return self::markerPrefix() . trim($suffix, '-');
    }

    /**
     * @return string
     */
    private static function markerPrefix()
    {
        return self::config()['identity_prefix']
            . substr(hash('sha256', FcFixture::identity()), 0, 12)
            . '-';
    }

    /**
     * @return array<string,string>
     */
    private static function tables()
    {
        global $wpdb;

        $tables = [];
        foreach (array_keys(self::config()['rows']) as $key) {
            $tables[$key] = $wpdb->prefix . self::rowConfig($key)['table'];
        }
        return $tables;
    }

    /**
     * @param string $key
     * @return array<string,string>
     */
    private static function rowConfig($key)
    {
        $config = self::config();
        if (empty($config['rows'][$key])) {
            throw new RuntimeException('Phase 18 row configuration is missing: ' . $key);
        }
        return $config['rows'][$key];
    }

    /**
     * @return array<string,mixed>
     */
    private static function config()
    {
        if (self::$config === null) {
            $suite = require dirname(__DIR__) . '/suite.config.php';
            if (empty($suite['integration_fixture']['public_surface'])) {
                throw new RuntimeException('integration_fixture.public_surface is missing.');
            }
            self::$config = $suite['integration_fixture']['public_surface'];
        }

        return self::$config;
    }
}
