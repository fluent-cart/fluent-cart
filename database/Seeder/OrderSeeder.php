<?php

namespace FluentCart\Database\Seeder;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CustomerHelper;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Faker\Factory;
use FluentCart\Framework\Support\Arr;

class OrderSeeder
{
    public static function seed($count, $assoc_args = [])
    {
        $db = \FluentCart\App\App::getInstance('db');

        $faker = Factory::create();
        $faker->addProvider(new \FluentCart\Database\Seeder\ProductNameProvide($faker));

        $customerIds = Customer::query()->select('id')->orderBy('id', 'desc')->take($count / 2)->pluck('id');
        $products = Product::with('variants')->take(100)->get()->toArray();
        $orderCount = Order::query()->count();
        $orderItems = [];
        $orderTransactions = [];
        $coupons = Coupon::query()->where('status', 'active')->get()->toArray();

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Orders', $count);
        }

        $storeSettings = new StoreSettings();
        for ($i = 0; $i < $count; $i++) {
            $totalPrice = 0;
            $discountTotal = 0;
            $tempOrderItems = [];
            $createdDate = $faker->dateTimeBetween('-450 days', 'now')->format('Y-m-d H:i:s');
            $createdDateGmt = DateTime::anyTimeToGmt($createdDate);

            $fulfilmentType = $faker->randomElement(['physical', 'digital']);

            // Filter only products that have at least one variant with the selected fulfilment type
            $filteredProducts = array_filter($products, function ($product) use ($fulfilmentType) {
                return !empty(array_filter($product['variants'], function ($variant) use ($fulfilmentType) {
                    return isset($variant['fulfillment_type']) && $variant['fulfillment_type'] === $fulfilmentType;
                }));
            });

            // If no product matches the type, fallback to all products
            $availableProducts = count($filteredProducts) > 0 ? array_values($filteredProducts) : $products;

            // Randomize
            $randomizeProduct = count($availableProducts) < 2
                ? $availableProducts
                : array_map(function ($index) use ($availableProducts) {
                    return $availableProducts[$index];
                }, (array)array_rand($availableProducts, min(wp_rand(2, 5), count($availableProducts))));

            // Randomly pick a coupon
            $appliedCoupon = $faker->randomElement($coupons ?? []);
            $couponType = Arr::get($appliedCoupon, 'type');
            $couponAmount = (int)Arr::get($appliedCoupon, 'amount', 0);

            foreach ($randomizeProduct as $key => $productIndex) {
                $product = $products[$key];
                $variant = $faker->randomElement($product['variants']);
                $quantity = wp_rand(1, 3);
                $itemPrice = $variant['item_price'] ?? 0;
                $subTotalPrice = $quantity * $itemPrice;

                $lineDiscount = 0;
                if ($couponType === 'percentage' && $couponAmount > 0) {
                    $lineDiscount = intval(($couponAmount / 100) * $subTotalPrice);
                }

                $discountTotal += $lineDiscount;
                $totalPrice += $subTotalPrice;

                $tempOrderItems[] = [
                    'post_title'       => $product['post_title'],
                    'title'            => $variant['variation_title'] ?? '',
                    'fulfillment_type' => $variant['fulfillment_type'],
                    'payment_type'     => $variant['payment_type'],
                    'quantity'         => $quantity,
                    'unit_price'       => $itemPrice,
                    'line_total'       => $subTotalPrice - $lineDiscount,
                    'subtotal'         => $subTotalPrice,
                    'post_id'          => $product['ID'],
                    'object_id'        => $variant['id'],
                    'discount_total'   => $lineDiscount,
                    'cart_index'       => $i + 1,
                    'created_at'       => $createdDateGmt,
                ];
            }

            $paymentMethod = $faker->randomElement(['offline_payment', 'online_payment']);
            $paymentMethodTitle = $faker->randomElement(['Cash on Delivery', 'PayPal', 'Stripe']);
            $customerId = $faker->randomElement($customerIds);

            $paymentStatus = $faker->randomElement([
                'pending',
                'paid',
                'partially_paid',
                'refunded',
                'partially_refunded',
                'failed',
            ]);

            switch ($paymentStatus) {
                case 'paid':
                    if ($fulfilmentType === 'digital') {
                        $status = 'completed';
                    } else {
                        $status = $faker->randomElement(['completed', 'processing']);
                    }
                    break;

                case 'partially_paid':
                    $status = 'processing';
                    break;

                case 'partially_refunded':
                    $status = 'completed';
                    break;

                case 'refunded':
                    $status = 'canceled';
                    break;

                default:
                    // For pending, failed, authorized
                    $status = $faker->randomElement(['on-hold', 'canceled', 'failed']);
                    break;
            }

            $refundedAt = $faker->dateTimeBetween($createdDate, 'now')->format('Y-m-d H:i:s');
            $currency = 'USD';
            $shippingTax = 0;
            $taxTotal = 0;
            $orderDiscountTotal = 0;
            $totalRefund = 0;
            $totalPaid = 0;
            $completedAt = null;
            $totalAmount = $totalPrice - $shippingTax - $taxTotal - $orderDiscountTotal - $discountTotal;
            if ($status === 'completed') {
                $endDate = (new DateTime($createdDateGmt))->modify('+3 days')->format('Y-m-d H:i:s');
                $completedAt = $faker->dateTimeBetween($createdDateGmt, $endDate)->format('Y-m-d H:i:s');
            }

            if ($paymentStatus === 'paid') {
                $totalPaid = $totalAmount;
            } elseif ($paymentStatus === 'refunded') {
                $totalPaid = $totalAmount;
                $totalRefund = $totalPaid;
            } elseif ($paymentStatus === 'partially_refunded') {
                $totalPaid = $totalAmount;
                $refundPercentage = wp_rand(20, 30) / 100;
                $totalRefund = round($totalPaid * $refundPercentage, 2);
            } elseif ($paymentStatus === 'partially_paid') {
                $paymentPercentage = wp_rand(50, 60) / 100;
                $totalPaid = round($totalAmount * $paymentPercentage, 2);
                $totalRefund = 0;
            }

            $orderData = [
                'parent_id'             => 0,
                'invoice_no'            => $storeSettings->getInvoicePrefix() . ($orderCount + $i + 1) . $storeSettings->getInvoiceSuffix(),
                'receipt_number'        => ($orderCount + $i + 1),
                'customer_id'           => $customerId,
                'payment_method'        => $paymentMethod,
                'payment_method_title'  => $paymentMethodTitle,
                'payment_status'        => $paymentStatus,
                'currency'              => $currency,
                'subtotal'              => $totalPrice,
                'shipping_tax'          => $shippingTax,
                'fulfillment_type'      => $fulfilmentType,
                'tax_total'             => $taxTotal,
                'manual_discount_total' => $orderDiscountTotal,
                'coupon_discount_total' => $discountTotal,
                'total_amount'          => $totalPrice - $shippingTax - $taxTotal - $orderDiscountTotal - $discountTotal,
                'total_paid'            => $totalPaid,
                'status'                => $status,
                'created_at'            => $createdDateGmt,
                'total_refund'          => $totalRefund,
                'refunded_at'           => $refundedAt,
                'completed_at'          => $completedAt,
            ];

            $order = Order::query()->create($orderData);

            switch ($paymentStatus) {
                case 'paid':
                case 'partially_paid':
                case 'authorized':
                    $transactionStatus = 'completed';
                    break;

                case 'refunded':
                    $transactionStatus = 'refunded';
                    break;

                case 'partially_refunded':
                    $transactionStatus = 'partially_refunded';
                    break;

                case 'pending':
                    $transactionStatus = 'pending';
                    break;

                case 'failed':
                    $transactionStatus = 'failed';
                    break;

                default:
                    $transactionStatus = 'pending';
                    break;
            }


            $orderTransactions[] = [
                'order_id'       => $order->id,
                'order_type'     => $order->type,
                'payment_method' => $paymentMethod,
                'payment_mode'   => 'test',
                'status'         => $transactionStatus,
                'currency'       => $currency,
                'total'          => $totalPrice,
                'created_at'     => $createdDateGmt,
            ];

            $appliedCouponData = [];
            if (!empty($appliedCoupon) && $discountTotal > 0) {
                $appliedCouponData[] = [
                    'order_id'    => $order->id,
                    'coupon_id'   => $appliedCoupon['id'],
                    'customer_id' => $customerId,
                    'code'        => $appliedCoupon['code'],
                    'amount'      => $discountTotal,
                    'created_at'  => $createdDateGmt,
                ];
            }


            foreach ($tempOrderItems as $index => $item) {
                $tempOrderItems[$index]['order_id'] = $order->id;
            }

            $orderItems = array_merge($orderItems, $tempOrderItems);

            if (defined('WP_CLI') && WP_CLI) {
                if ($i !== $count - 1) {
                    $progress->tick();
                }
            } else {
                echo wp_kses_post( sprintf(
                    /* translators: %d: order ID */
                    __('Inserting Order %1$s<br>', 'fluent-cart'),
                    esc_html($i + 1)
                ) );
            }
        }

        OrderTransaction::query()->insert($orderTransactions);
        OrderItem::query()->insert($orderItems);
        AppliedCoupon::query()->insert($appliedCouponData);

        (new CustomerHelper)->calculateCustomerStats();

        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }
}
