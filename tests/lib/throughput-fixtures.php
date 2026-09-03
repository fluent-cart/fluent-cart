<?php
/**
 * Exact-ID Phase 16 fixtures for list-query throughput guards.
 *
 * Creation uses real FluentCart models. Every primary ID is captured
 * immediately; cleanup reloads and verifies immutable ownership before
 * deleting only that row. Marker queries are assertions, never selectors for
 * mutation.
 */

class FcThroughputFixture
{
    /** @var array<string,mixed>|null */
    private static $config = null;

    /** @var array<string,array<int,array<string,mixed>>> */
    private static $rows = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    private static $history = [];

    /** @var array<int,array<string,mixed>> */
    private static $products = [];

    /** @var array<int,array<string,mixed>> */
    private static $productHistory = [];

    /**
     * Return one run-scoped scalar marker.
     *
     * @param string $suffix
     * @return string
     */
    public static function marker($suffix)
    {
        $suffix = preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $suffix));
        if ($suffix === '') {
            throw new InvalidArgumentException('Throughput fixture marker suffix cannot be empty.');
        }

        return self::throughputConfig()['identity_prefix']
            . substr(hash('sha256', FcFixture::identity()), 0, 20)
            . '-' . $suffix;
    }

    /**
     * Create additional Customers with primary billing and shipping addresses.
     *
     * @param int $count
     * @return array<int,object>
     */
    public static function customers($count)
    {
        $count = (int) $count;
        if ($count < 1) {
            throw new InvalidArgumentException('Throughput Customer count must be positive.');
        }

        $created = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $index = count(self::$history['customer'] ?? []) + 1;
            $marker = self::marker('customer');
            $email = $marker . '-' . sprintf('%02d', $index)
                . '@' . self::throughputConfig()['identity_domain'];

            $class = self::rowConfig('customer')['model_class'];
            $customer = $class::query()->create([
                'email'          => $email,
                'first_name'     => $marker,
                'last_name'      => sprintf('%02d', $index),
                'status'         => 'active',
                'purchase_value' => ['currency' => 'USD', 'gross' => 0],
                'purchase_count' => 0,
                'ltv'            => 0,
                'notes'          => self::marker('customer-note'),
                'country'        => 'BD',
                'city'           => 'Dhaka',
                'state'          => 'C',
                'postcode'       => '1205',
            ]);
            self::capture('customer', $customer, ['email' => $email]);

            foreach (['billing', 'shipping'] as $type) {
                $addressClass = self::rowConfig('customer_address')['model_class'];
                $label = self::marker('address') . '-' . $type . '-' . sprintf('%02d', $index);
                $address = $addressClass::query()->create([
                    'customer_id' => (int) $customer->id,
                    'is_primary'  => 1,
                    'type'        => $type,
                    'status'      => 'active',
                    'label'       => $label,
                    'name'        => self::marker('address-name'),
                    'address_1'   => 'Phase 16 exact fixture',
                    'address_2'   => '',
                    'city'        => 'Dhaka',
                    'state'       => 'C',
                    'postcode'    => '1205',
                    'country'     => 'BD',
                    'phone'       => '',
                    'email'       => $email,
                    'meta'        => ['fixture_identity' => FcFixture::identity()],
                ]);
                self::capture('customer_address', $address, [
                    'customer_id' => (int) $customer->id,
                    'type'        => $type,
                    'label'       => $label,
                ]);
            }

            $created[] = $customer;
        }

        return $created;
    }

    /**
     * Create additional Activities linked read-only to an existing WP user.
     *
     * @param int $count
     * @param int $userId
     * @return array<int,object>
     */
    public static function activities($count, $userId)
    {
        $count = (int) $count;
        $userId = (int) $userId;
        if ($count < 1 || $userId < 1 || !get_user_by('ID', $userId)) {
            throw new InvalidArgumentException(
                'Throughput Activities need a positive count and existing WordPress user.'
            );
        }

        $created = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $index = count(self::$history['activity'] ?? []) + 1;
            $title = self::marker('activity') . '-' . sprintf('%02d', $index);
            $class = self::rowConfig('activity')['model_class'];
            $activity = $class::query()->create([
                'status'       => 'info',
                'log_type'     => 'activity',
                'module_id'    => 0,
                'module_type'  => 'phase16-test',
                'module_name'  => self::marker('activity-module'),
                'title'        => $title,
                'content'      => $title,
                'user_id'      => $userId,
                'read_status'  => 'unread',
                'created_by'   => 'WP-PLUGIN-TEST',
            ]);
            self::capture('activity', $activity, ['title' => $title]);
            $created[] = $activity;
        }

        return $created;
    }

    /**
     * Create additional Product/ProductDetail/ProductVariation trees.
     *
     * @param int $count
     * @return array<int,array<string,object>>
     */
    public static function products($count)
    {
        $count = (int) $count;
        if ($count < 1) {
            throw new InvalidArgumentException('Throughput Product count must be positive.');
        }

        $created = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $index = count(self::$productHistory) + 1;
            $title = self::marker('product') . '-' . sprintf('%02d', $index);
            $productClass = self::throughputConfig()['product_model_class'];
            $product = $productClass::query()->create([
                'post_title'         => $title,
                'post_name'          => sanitize_title($title),
                'post_status'        => 'publish',
                'post_type'          => self::throughputConfig()['product_post_type'],
                'post_content'       => '',
                'post_excerpt'       => '',
                'post_author'        => 0,
                'post_date'          => '2001-02-03 04:05:06',
                'post_date_gmt'      => '2001-02-03 04:05:06',
                'post_modified'      => '2001-02-03 04:05:06',
                'post_modified_gmt'  => '2001-02-03 04:05:06',
                'comment_status'     => 'closed',
                'ping_status'        => 'closed',
                'post_password'      => '',
                'to_ping'            => '',
                'pinged'             => '',
                'post_content_filtered' => '',
                'post_parent'        => 0,
                'menu_order'         => 0,
                'post_mime_type'     => '',
                'guid'               => '',
                'comment_count'      => 0,
            ]);
            $postId = isset($product->ID) ? (int) $product->ID : 0;
            if ($postId > 0) {
                self::$products[$postId] = [
                    'id'    => $postId,
                    'title' => $title,
                    'type'  => self::throughputConfig()['product_post_type'],
                ];
                self::$productHistory[$postId] = self::$products[$postId];
            }
            if ($postId <= 0) {
                throw new RuntimeException('Throughput Product create returned no positive ID.');
            }

            $detailClass = self::rowConfig('product_detail')['model_class'];
            $detail = $detailClass::query()->create([
                'post_id'              => $postId,
                'fulfillment_type'     => 'digital',
                'min_price'            => 1000,
                'max_price'            => 1000,
                'default_variation_id' => 0,
                'variation_type'       => 'simple',
                'stock_availability'   => 'in-stock',
                'other_info'           => ['fixture_identity' => FcFixture::identity()],
                'default_media'        => [],
                'manage_stock'         => 1,
                'manage_downloadable'  => 0,
            ]);
            self::capture('product_detail', $detail, ['post_id' => $postId]);

            $variationTitle = self::marker('variation') . '-' . sprintf('%02d', $index);
            $variationClass = self::rowConfig('product_variation')['model_class'];
            $variation = $variationClass::query()->create([
                'post_id'              => $postId,
                'media_id'             => 0,
                'serial_index'         => 1,
                'sold_individually'    => 0,
                'variation_title'      => $variationTitle,
                'variation_identifier' => self::marker('variation-id') . '-' . $index,
                'sku'                  => strtoupper(substr(hash('sha256', $title), 0, 24)),
                'manage_stock'         => 1,
                'payment_type'         => 'onetime',
                'stock_status'         => 'in-stock',
                'backorders'           => 0,
                'total_stock'          => 10,
                'available'            => 10,
                'committed'            => 0,
                'on_hold'              => 0,
                'fulfillment_type'     => 'digital',
                'item_status'          => 'active',
                'manage_cost'          => 0,
                'item_price'           => 1000,
                'item_cost'            => 0,
                'compare_price'        => 0,
                'other_info'           => ['fixture_identity' => FcFixture::identity()],
                'downloadable'         => 0,
                'shipping_class'       => null,
            ]);
            self::capture('product_variation', $variation, [
                'post_id'         => $postId,
                'variation_title' => $variationTitle,
            ]);

            $detail->default_variation_id = (int) $variation->id;
            $detail->save();

            $created[] = [
                'product'   => $product,
                'detail'    => $detail,
                'variation' => $variation,
            ];
        }

        return $created;
    }

    /**
     * Delete all exact owned rows, then exact Product posts.
     *
     * @return void
     */
    public static function cleanupAll()
    {
        foreach (self::throughputConfig()['cleanup_order'] as $kind) {
            self::cleanupRows($kind);
        }

        $postIds = array_keys(self::$products);
        rsort($postIds, SORT_NUMERIC);
        foreach ($postIds as $postId) {
            global $wpdb;

            $owned = self::$products[$postId];
            clean_post_cache((int) $postId);
            $post = get_post((int) $postId);
            if ($post) {
                if (
                    (string) $post->post_title !== (string) $owned['title']
                    || (string) $post->post_type !== (string) $owned['type']
                ) {
                    throw new LogicException(
                        'Refusing to delete throughput Product because ownership changed: '
                        . (int) $postId
                    );
                }

                $metaDeleted = $wpdb->delete(
                    $wpdb->postmeta,
                    ['post_id' => (int) $postId],
                    ['%d']
                );
                $termsDeleted = $wpdb->delete(
                    $wpdb->term_relationships,
                    ['object_id' => (int) $postId],
                    ['%d']
                );
                $postDeleted = $wpdb->delete(
                    $wpdb->posts,
                    ['ID' => (int) $postId],
                    ['%d']
                );
                if ($metaDeleted === false || $termsDeleted === false || $postDeleted !== 1) {
                    throw new RuntimeException(
                        'Exact throughput Product cleanup failed: ' . (int) $postId
                    );
                }
                clean_post_cache((int) $postId);
            }
            unset(self::$products[$postId]);
        }

        $residue = self::residueCounts();
        $markers = self::markerResidueCounts();
        if (array_sum($residue) !== 0 || array_sum($markers) !== 0) {
            throw new RuntimeException(
                'Phase 16 throughput residue remains: exact='
                . wp_json_encode($residue)
                . ' marker=' . wp_json_encode($markers)
            );
        }
    }

    /**
     * Count historical exact primary IDs that remain.
     *
     * @return array<string,int>
     */
    public static function residueCounts()
    {
        global $wpdb;

        $counts = [];
        foreach (self::throughputConfig()['rows'] as $kind => $config) {
            $ids = array_map('intval', array_keys(self::$history[$kind] ?? []));
            $table = $wpdb->prefix . $config['table'];
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `id` IN ("
                . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $counts[$kind] = $ids
                ? (int) $wpdb->get_var(call_user_func_array(
                    [$wpdb, 'prepare'],
                    array_merge([$sql], $ids)
                ))
                : 0;
        }

        $postIds = array_map('intval', array_keys(self::$productHistory));
        $postSql = "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE `ID` IN ("
            . implode(',', array_fill(0, count($postIds), '%d')) . ')';
        $counts['product_post'] = $postIds
            ? (int) $wpdb->get_var(call_user_func_array(
                [$wpdb, 'prepare'],
                array_merge([$postSql], $postIds)
            ))
            : 0;

        return $counts;
    }

    /**
     * Count run markers without using them as cleanup selectors.
     *
     * @return array<string,int>
     */
    public static function markerResidueCounts()
    {
        $customerClass = self::rowConfig('customer')['model_class'];
        $addressClass = self::rowConfig('customer_address')['model_class'];
        $activityClass = self::rowConfig('activity')['model_class'];
        $variationClass = self::rowConfig('product_variation')['model_class'];
        $productClass = self::throughputConfig()['product_model_class'];

        return [
            'customer' => (int) $customerClass::query()
                ->where('email', 'like', self::marker('customer') . '-%')
                ->count(),
            'customer_address' => (int) $addressClass::query()
                ->where('label', 'like', self::marker('address') . '-%')
                ->count(),
            'activity' => (int) $activityClass::query()
                ->where('title', 'like', self::marker('activity') . '-%')
                ->count(),
            'product_variation' => (int) $variationClass::query()
                ->where('variation_title', 'like', self::marker('variation') . '-%')
                ->count(),
            'product_post' => (int) $productClass::query()
                ->where('post_title', 'like', self::marker('product') . '-%')
                ->count(),
        ];
    }

    /**
     * Return captured primary IDs for runner diagnostics.
     *
     * @return array<string,array<int,int>>
     */
    public static function ownedIds()
    {
        $ids = [];
        foreach (self::$history as $kind => $rows) {
            $ids[$kind] = array_map('intval', array_keys($rows));
        }
        $ids['product_post'] = array_map('intval', array_keys(self::$productHistory));

        return $ids;
    }

    /**
     * Capture one model row immediately after creation.
     *
     * @param string              $kind
     * @param object              $model
     * @param array<string,mixed> $ownership
     * @return void
     */
    private static function capture($kind, $model, array $ownership)
    {
        $id = isset($model->id) ? (int) $model->id : 0;
        if ($id <= 0) {
            throw new RuntimeException(
                'Throughput ' . $kind . ' create returned no positive primary ID.'
            );
        }

        $owned = array_merge(['id' => $id], $ownership);
        self::$rows[$kind][$id] = $owned;
        self::$history[$kind][$id] = $owned;
    }

    /**
     * Delete exact captured rows of one model kind.
     *
     * @param string $kind
     * @return void
     */
    private static function cleanupRows($kind)
    {
        $ids = array_keys(self::$rows[$kind] ?? []);
        rsort($ids, SORT_NUMERIC);
        $class = self::rowConfig($kind)['model_class'];

        foreach ($ids as $id) {
            $owned = self::$rows[$kind][$id];
            $row = $class::query()->find((int) $id);
            if ($row) {
                foreach ($owned as $column => $expected) {
                    if ($column === 'id') {
                        continue;
                    }
                    if ((string) $row->{$column} !== (string) $expected) {
                        throw new LogicException(
                            'Refusing to delete throughput ' . $kind . ' ID '
                            . (int) $id . ' because ownership changed at ' . $column . '.'
                        );
                    }
                }
                if ($row->delete() !== true) {
                    throw new RuntimeException(
                        'Throughput ' . $kind . ' model did not confirm deletion: '
                        . (int) $id
                    );
                }
            }
            if ($class::query()->find((int) $id)) {
                throw new RuntimeException(
                    'Throughput ' . $kind . ' exact ID remains: ' . (int) $id
                );
            }
            unset(self::$rows[$kind][$id]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function throughputConfig()
    {
        if (self::$config === null) {
            $suite = require dirname(__DIR__) . '/suite.config.php';
            self::$config = $suite['integration_fixture']['throughput'];
        }

        return self::$config;
    }

    /**
     * @param string $kind
     * @return array<string,mixed>
     */
    private static function rowConfig($kind)
    {
        $rows = self::throughputConfig()['rows'];
        if (!isset($rows[$kind])) {
            throw new InvalidArgumentException('Unknown throughput row kind: ' . $kind);
        }

        return $rows[$kind];
    }
}
