<?php

namespace FluentCart\Database\Seeder;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Faker\Factory;

class SubscriptionSeeder
{
    public static function seed($count, $assoc_args = [])
    {
        $faker = Factory::create();

        // Get active orders with subscription items
        $orderItems = OrderItem::query()
            ->where('payment_type', 'subscription')
            ->get();

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Subscriptions', $count);
        }

        $subscriptions = [];

        if (empty($orderItems)) {
            return;
        }

        foreach ($orderItems as $orderItem) {

            $order = Order::find($orderItem->order_id);
            if (!$order) {
                continue;
            }

            $variation = ProductVariation::find($orderItem->object_id);
            if (!$variation || $variation->payment_type !== 'subscription') {

                continue;
            }

            $otherInfo = $variation->other_info;
            $createdDate = $order->created_at;
            $createdDateGmt = DateTime::anyTimeToGmt($createdDate);

            $intervalMap = [
                'daily' => 'day',
                'weekly' => 'week',
                'monthly' => 'month',
                'yearly' => 'year',
            ];

            $interval = $intervalMap[$otherInfo['repeat_interval']] ?? 'month';

            $nextBillingDate = (new DateTime($createdDateGmt))
                ->modify('+1 ' . $interval)
                ->format('Y-m-d H:i:s');
            // Set subscription status based on order payment status
            $status = Status::SUBSCRIPTION_ACTIVE;
            if ($order->payment_status === Status::PAYMENT_PAID) {
                $status = Status::SUBSCRIPTION_ACTIVE;
            } else if ($order->payment_status === Status::PAYMENT_FAILED) {
                $status = Status::SUBSCRIPTION_FAILING;
            } else if ($order->payment_status === Status::PAYMENT_REFUNDED) {
                $status = Status::SUBSCRIPTION_CANCELED;
            } else if ($order->payment_status === Status::PAYMENT_PENDING) {
                $status = Status::SUBSCRIPTION_PENDING;
            }

            $subscriptions[] = [
                'parent_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'product_id' => $orderItem->post_id,
                'item_name' => $orderItem->title,
                'variation_id' => $variation->id,
                'status' => $status,
                'billing_interval' => $otherInfo['repeat_interval'],
                'recurring_amount' => $orderItem['unit_price'],
                'recurring_total' => $orderItem['unit_price'],
                'bill_times' => $otherInfo['times'],
                'next_billing_date' => $nextBillingDate,
                'created_at' => $createdDateGmt,
                'updated_at' => $createdDateGmt
            ];


            if (defined('WP_CLI') && WP_CLI) {
                $progress->tick();
            }
        }

        // Insert subscriptions in chunks
        foreach (array_chunk($subscriptions, 100) as $chunk) {
            Subscription::query()->insert($chunk);
        }

        self::updateTransactions();

        if (defined('WP_CLI') && WP_CLI) {
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }

    public static function updateTransactions()
    {
        $subscriptions = Subscription::query()->get();

        foreach ($subscriptions as $subscription) {
            OrderTransaction::query()
                ->where('order_id', $subscription->parent_order_id)
                ->where('order_type', 'subscription')
                ->update([
                    'subscription_id' => $subscription->id,
                    'transaction_type' => Status::TRANSACTION_TYPE_CHARGE
                ]);
        }
    }
}
