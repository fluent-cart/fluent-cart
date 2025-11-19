<?php

namespace FluentCart\App\Hooks\CLI;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Subscriptions\Models\Subscription;

class OrderCloneCommand
{
    /**
     * Clone orders with specified date range
     *
     * ## OPTIONS
     *
     * [--count=<number>]
     * : Number of orders to clone
     * ---
     * default: 10
     * ---
     *
     * [--start-date=<date>]
     * : Start date for cloned orders (YYYY-MM-DD)
     * ---
     * default: 30 days ago
     * ---
     *
     * [--end-date=<date>]
     * : End date for cloned orders (YYYY-MM-DD)
     * ---
     * default: today
     * ---
     *
     * [--source-order-id=<id>]
     * : Specific order ID to clone (if not provided, random orders will be selected)
     *
     * ## EXAMPLES
     *
     *     wp fluent_cart clone_orders --count=50
     *     wp fluent_cart clone_orders --count=25 --start-date=2024-01-01 --end-date=2024-12-31
     *     wp fluent_cart clone_orders --source-order-id=123 --count=5
     */
    public function clone_orders($args, $assoc_args)
    {
        $count = isset($assoc_args['count']) ? (int)$assoc_args['count'] : 10;
        $startDate = isset($assoc_args['start-date']) ? $assoc_args['start-date'] : gmdate('Y-m-d', strtotime('-30 days'));
        $endDate = isset($assoc_args['end-date']) ? $assoc_args['end-date'] : gmdate('Y-m-d');
        $sourceOrderId = isset($assoc_args['source-order-id']) ? (int)$assoc_args['source-order-id'] : null;

        \WP_CLI::line("Starting order cloning process...");
        \WP_CLI::line("Count: {$count}");
        \WP_CLI::line("Date range: {$startDate} to {$endDate}");

        try {
            $results = $this->cloneOrders($count, $startDate, $endDate, $sourceOrderId);

            \WP_CLI::success("Successfully cloned {$results['success']} orders");
            if ($results['failed'] > 0) {
                \WP_CLI::warning("Failed to clone {$results['failed']} orders");
            }

        } catch (\Exception $e) {
            \WP_CLI::error("Order cloning failed: " . $e->getMessage());
        }
    }

    private function cloneOrders($count, $startDate, $endDate, $sourceOrderId = null)
    {
        $results = ['success' => 0, 'failed' => 0];

        // Get source orders
        $sourceOrders = $this->getSourceOrders($sourceOrderId, $count);

        if (empty($sourceOrders)) {
            throw new \Exception('No source orders found to clone');
        }

        $progress = \WP_CLI\Utils\make_progress_bar('Cloning Orders', $count);

        for ($i = 0; $i < $count; $i++) {
            $sourceOrder = $sourceOrders[$i % count($sourceOrders)];
            $randomDate = $this->getRandomDateInRange($startDate, $endDate);

            if ($sourceOrder instanceof Order) {
                $sourceOrder = $sourceOrder->toArray();
            }
            try {
                $this->cloneSingleOrder($sourceOrder, $randomDate);
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                \WP_CLI::debug("Failed to clone order {$sourceOrder->id}: " . $e->getMessage());
            }

            $progress->tick();
        }

        $progress->finish();
        return $results;
    }

    private function getSourceOrders($sourceOrderId, $count)
    {
        $query = Order::with([
            'billing_address',
            'shipping_address',
            'order_items',
            'appliedCoupons',
            'customer'
        ]);

        if ($sourceOrderId) {
            return [$query->findOrFail($sourceOrderId)];
        }

        return $query->inRandomOrder()->limit(min($count, 50))->get();
    }

    private function cloneSingleOrder($sourceOrder, $targetDate)
    {
        global $wpdb;
        //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query('START TRANSACTION');

        try {
            // Clone main order
            $newOrder = $this->cloneOrderRecord($sourceOrder, $targetDate);

            // Clone order items
            $this->cloneOrderItems($sourceOrder, $newOrder);

            // Clone addresses
            $this->cloneOrderAddresses($sourceOrder, $newOrder);

            // Clone applied coupons
            $this->cloneAppliedCoupons($sourceOrder, $newOrder);

            // Clone order meta/actions
            $this->cloneOrderMeta($sourceOrder, $newOrder);

            // Clone licenses if exists
            $this->cloneLicenses($sourceOrder, $newOrder);

            // Clone subscriptions if exists
            $this->cloneSubscriptions($sourceOrder, $newOrder);
            //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('COMMIT');

        } catch (\Exception $e) {
            //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private function cloneOrderRecord($sourceOrder, $targetDate)
    {

        $orderData = $sourceOrder;
        unset($orderData['id']);

        // Generate new identifiers
        $orderData['uuid'] = md5(time() . wp_generate_uuid4());
        $orderData['receipt_number'] = $this->getNextReceiptNumber();
        $orderData['invoice_no'] = 'FC-' . $orderData['receipt_number'];

        // Set target date
        $orderData['created_at'] = DateTime::anyTimeToGmt($targetDate)->format('Y-m-d H:i:s');
        $orderData['updated_at'] = $orderData['created_at'];

        $order = Order::create($orderData);

        $order->load('customer');

        if ($order->customer) {
            $order->customer->recountStat();
        }

        return $order;
    }

    private function cloneOrderAddresses($sourceOrder, $newOrder)
    {
        foreach (['billing_address', 'shipping_address'] as $addressType) {
            if (!empty($sourceOrder[$addressType])) {
                $addressData = $sourceOrder[$addressType];
                unset($addressData['id']);
                $addressData['order_id'] = $newOrder->id;
                $addressData['created_at'] = $newOrder->created_at;
                $addressData['updated_at'] = $newOrder->created_at;

                OrderAddress::create($addressData);
            }
        }
    }

    private function cloneOrderItems($sourceOrder, $newOrder)
    {
        if (!empty($sourceOrder['order_items'])) {
            foreach ($sourceOrder['order_items'] as $item) {
                $itemData = $item;
                unset($itemData['id']);
                $itemData['order_id'] = $newOrder->id;
                $itemData['created_at'] = $newOrder->created_at;
                $itemData['updated_at'] = $newOrder->created_at;

                OrderItem::create($itemData);
            }
        }
    }

    private function cloneAppliedCoupons($sourceOrder, $newOrder)
    {
        if (!empty($sourceOrder['appliedCoupons'])) {
            $couponIds = [];

            foreach ($sourceOrder['appliedCoupons'] as $coupon) {
                $couponData = is_array($coupon) ? $coupon : $coupon->toArray();
                unset($couponData['id']);
                $couponData['order_id'] = $newOrder->id;
                $couponData['created_at'] = $newOrder->created_at;
                $couponData['updated_at'] = $newOrder->created_at;

                AppliedCoupon::create($couponData);

                // Collect coupon IDs for incrementing use_count
                if (!empty($couponData['coupon_id'])) {
                    $couponIds[] = $couponData['coupon_id'];
                }
            }

            // Increment use_count for all used coupons
            if (!empty($couponIds)) {
                \FluentCart\App\Models\Coupon::whereIn('id', array_unique($couponIds))
                    ->increment('use_count', 1);
            }
        }
    }

    private function cloneOrderMeta($sourceOrder, $newOrder)
    {
        $metas = OrderMeta::where('order_id', $sourceOrder['id'])->get();

        foreach ($metas as $meta) {
            OrderMeta::create([
                'order_id'   => $newOrder->id,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'   => $meta->meta_key,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $meta->meta_value,
                'created_at' => $newOrder->created_at,
                'updated_at' => $newOrder->created_at
            ]);
        }
    }

    private function cloneLicenses($sourceOrder, $newOrder)
    {
        if (!class_exists(License::class)) {
            return;
        }

        $licenses = License::where('order_id', $sourceOrder['id'])->get();

        foreach ($licenses as $license) {
            $licenseData = $license->toArray();
            unset($licenseData['id']);
            $licenseData['order_id'] = $newOrder->id;
            $licenseData['license_key'] = wp_generate_uuid4();
            $licenseData['created_at'] = $newOrder->created_at;
            $licenseData['updated_at'] = $newOrder->created_at;

            License::create($licenseData);
        }
    }

    private function cloneSubscriptions($sourceOrder, $newOrder)
    {
        if (!class_exists(Subscription::class)) {
            return;
        }

        $subscriptions = Subscription::where('parent_order_id', $sourceOrder['id'])->get();

        foreach ($subscriptions as $subscription) {
            $subData = $subscription->toArray();
            unset($subData['id']);
            $subData['parent_order_id'] = $newOrder->id;
            $subData['created_at'] = $newOrder->created_at;
            $subData['updated_at'] = $newOrder->created_at;

            // Adjust billing dates relative to new order date
            if ($subData['next_billing_date']) {
                $interval = $subData['billing_interval'] ?? 'month';
                $subData['next_billing_date'] = gmdate('Y-m-d H:i:s',
                    strtotime($newOrder->created_at . " +1 {$interval}"));
            }

            Subscription::create($subData);
        }
    }

    private function getRandomDateInRange($startDate, $endDate)
    {
        $startTimestamp = strtotime($startDate);
        $endTimestamp = strtotime($endDate);
        $randomTimestamp = wp_rand($startTimestamp, $endTimestamp);

        // Add random time within the day
        $randomTime = wp_rand(0, 86399); // 0 to 23:59:59

        return gmdate('Y-m-d H:i:s', $randomTimestamp + $randomTime);
    }

    private function getNextReceiptNumber()
    {
        global $wpdb;
        //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $lastNumber = $wpdb->get_var("SELECT MAX(receipt_number) FROM {$wpdb->prefix}fct_orders");
        return ($lastNumber ?? 1000) + 1;
    }
}
