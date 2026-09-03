<?php
/**
 * Exact-primary-key Phase 14 fixtures for background automation.
 */

class FcAutomationFixture
{
    /** @var array<string,array<string,string>> */
    private static $carts = [];

    /** @var array<string,array<string,string>> */
    private static $cartHistory = [];

    /** @var array<string,mixed>|null */
    private static $config = null;

    /**
     * @param string $suffix
     * @param string              $updatedAt
     * @param array<string,mixed> $attributes
     * @return object
     */
    public static function cart($suffix, $updatedAt, array $attributes = [])
    {
        $suffix = preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $suffix));
        if ($suffix === '') {
            throw new InvalidArgumentException('Automation cart suffix cannot be empty.');
        }

        $config = self::config();
        $class = $config['model_class'];
        $hash = substr(hash('sha256', FcFixture::identity() . '|cart|' . $suffix), 0, 32);
        $email = self::markerPrefix() . $suffix . '@example.invalid';
        if ($class::query()->where($config['primary_key'], $hash)->count() !== 0) {
            throw new RuntimeException('Automation Cart primary marker already exists.');
        }

        $defaults = [
            'cart_hash'     => $hash,
            'customer_id'   => null,
            'user_id'       => null,
            'order_id'      => null,
            'checkout_data' => [],
            'cart_data'     => [],
            'utm_data'      => [],
            'coupons'       => [],
            'first_name'    => 'Phase',
            'last_name'     => 'Fourteen',
            'email'         => $email,
            'stage'         => 'cart',
            'cart_group'    => 'phase14-' . $suffix,
            'user_agent'    => 'FluentCart Phase 14 integration fixture',
            'ip_address'    => '192.0.2.14',
            'completed_at'  => null,
            'deleted_at'    => null,
        ];
        $row = $class::query()->create(array_merge($defaults, $attributes, [
            'cart_hash' => $hash,
            'email'     => $email,
        ]));
        $storedHash = isset($row->cart_hash) ? (string) $row->cart_hash : '';
        if ($storedHash !== $hash) {
            throw new RuntimeException('Automation Cart create returned an unexpected primary key.');
        }

        self::$carts[$hash] = ['cart_hash' => $hash, 'email' => $email];
        self::$cartHistory[$hash] = self::$carts[$hash];

        global $wpdb;
        $table = $wpdb->prefix . $config['table'];
        $updated = $wpdb->update(
            $table,
            ['updated_at' => (string) $updatedAt],
            ['cart_hash' => $hash, 'email' => $email],
            ['%s'],
            ['%s', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Automation Cart timestamp preparation did not affect one row.');
        }

        return $class::query()->where('cart_hash', $hash)->first();
    }

    /**
     * @param string $hash
     * @return object|null
     */
    public static function findCart($hash)
    {
        if (!isset(self::$cartHistory[(string) $hash])) {
            throw new LogicException('Cart primary key is not owned by this process.');
        }
        $class = self::config()['model_class'];

        return $class::query()->where('cart_hash', (string) $hash)->first();
    }

    /**
     * @return array<string,int>
     */
    public static function residueCounts()
    {
        global $wpdb;
        $table = $wpdb->prefix . self::config()['table'];
        $count = 0;
        foreach (self::$cartHistory as $row) {
            $count += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `cart_hash` = %s AND `email` = %s",
                $row['cart_hash'],
                $row['email']
            ));
        }

        return ['cart' => $count];
    }

    /**
     * @return array<string,int>
     */
    public static function markerResidueCounts()
    {
        global $wpdb;
        $table = $wpdb->prefix . self::config()['table'];
        $prefix = self::markerPrefix() . '%@example.invalid';

        return [
            'cart' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `email` LIKE %s",
                $prefix
            )),
        ];
    }

    /**
     * @return void
     */
    public static function cleanupAll()
    {
        $class = self::config()['model_class'];
        foreach (array_reverse(array_keys(self::$carts)) as $hash) {
            $owned = self::$carts[$hash];
            $row = $class::query()->where('cart_hash', $hash)->first();
            if ($row) {
                if ((string) $row->email !== $owned['email']) {
                    throw new LogicException('Refusing to delete Cart because ownership changed.');
                }
                if ($row->delete() !== true) {
                    throw new RuntimeException('Cart model did not confirm exact delete: ' . $hash);
                }
            }
            unset(self::$carts[$hash]);
        }

        if (array_sum(self::residueCounts()) !== 0) {
            throw new RuntimeException(
                'Phase 14 exact Cart residue remains: ' . wp_json_encode(self::residueCounts())
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function config()
    {
        if (self::$config === null) {
            $suite = require dirname(__DIR__) . '/suite.config.php';
            if (empty($suite['integration_fixture']['automation']['cart'])) {
                throw new RuntimeException('integration_fixture.automation.cart is missing.');
            }
            self::$config = $suite['integration_fixture']['automation']['cart'];
        }

        return self::$config;
    }

    /**
     * @return string
     */
    private static function markerPrefix()
    {
        return 'phase14-cart-' . substr(hash('sha256', FcFixture::identity()), 0, 16) . '-';
    }
}
