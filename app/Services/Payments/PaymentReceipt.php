<?php

namespace FluentCart\App\Services\Payments;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\ProductAdminHelper;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\OrderService;
use FluentCart\App\Vite;
use FluentCart\Framework\Database\Orm\Collection;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\DateTime;

class PaymentReceipt
{
    protected $order;

    public function __construct($order)
    {
        $this->order = is_array($order) ? $order : $order->toArray();
    }

    public function getItems()
    {

        $this->getItemImage();
        $orderItems = Arr::get($this->order, 'order_items', []);

        $hasAdjustmentItem = false; // Flag to track if an 'adjustment' item exists

        $subscriptionItem = current(array_filter($orderItems, function ($item) {
            return Arr::get($item, 'payment_type') === 'subscription';
        }));

        foreach ($orderItems as $key => $item) {

            if (Arr::get($item, 'payment_type') == 'signup_fee') {
                unset($orderItems[$key]);
            }

            // Check for 'adjustment' item and set the flag
            if (Arr::get($item, 'payment_type') == 'adjustment') {
                $hasAdjustmentItem = true;
                if ($subscriptionItem) {
                    Arr::set($orderItems, $key . '.media_url', Arr::get($subscriptionItem, 'media_url'));
                }
            }

            // Check for 'subscription' item and skip it if 'adjustment' item exists
            if ($hasAdjustmentItem && Arr::get($item, 'payment_type') == 'subscription') {
                unset($orderItems[$key]);
            }

            // Check for 'subscription' item and add it if 'adjustment' item not exists
            if (!$hasAdjustmentItem && Arr::get($item, 'payment_type') == 'subscription') {
                $signupFee = 0;
                if (Arr::get($item, 'other_info.manage_setup_fee') == 'yes') {
                    $signupFee = Arr::get($item, 'other_info.signup_fee');
                    $orderItems[$key]['signup_fee_name'] = Arr::get($item, 'other_info.signup_fee_name') . ':';
//                    if (Arr::get($item, 'other_info')->setup_fee_per_item == 'yes') {
//                        $signupFee *= Arr::get($item, 'quantity');
//                        $orderItems[$key]['signup_fee_name'] .= ' x ' . Arr::get($item, 'quantity');
//                    }
                  //  $orderItems[$key]['other_info']->signup_fee = $signupFee;
                }
            }
        }

        // Check for 'adjustment' item and update the order items if 'adjustment' item exists
        if ($hasAdjustmentItem) {
            Arr::set($this->order, 'order_items', $orderItems);
        }

        return $orderItems;
    }

    public function date($format = 'd M Y')
    {
        return DateTime::parse(Arr::get($this->order, 'created_at'))->format($format);
    }

    public function getEmail()
    {
        $customerEmail = Arr::get($this->order, 'customer.email');
        if ($customerEmail) {
            return $customerEmail;
        }
        return Arr::get($this->order, 'customer.billing_address.0.email', '');
    }

    public function total()
    {
        $totalAmount = Arr::get($this->order, 'total_amount', 0);

        // $totalAmount -= Arr::get($this->order,'discount_total', 0);
        return CurrencySettings::getPriceHtml(
            $totalAmount,
            Arr::get($this->order, 'currency')
        );
    }

    public function shippingCharge()
    {
        $shippingTotal = Arr::get($this->order, 'shipping_total', 0);
        if (empty($shippingTotal)) {
            return 0;
        }

        return CurrencySettings::getPriceHtml(
            $shippingTotal,
            Arr::get($this->order, 'currency')
        );
    }

    public function subtotal()
    {
        return CurrencySettings::getPriceHtml(
            Arr::get($this->order, 'subtotal'),
            Arr::get($this->order, 'currency'),
        );
    }

    public function formatPriceHtml($amount, $code = null)
    {
        return CurrencySettings::getPriceHtml($amount, $code ?? Arr::get($this->order, 'currency'));
    }

    public function getItemImage()
    {
        $orderItems = Arr::get($this->order, 'order_items', []);
        $variationIds = Collection::make($orderItems)->pluck('object_id')->toArray();
        $productVariants = ProductVariation::query()
            ->with(['product_detail', 'media'])
            ->whereIn('id', $variationIds)
            ->get();

        $variantMap = Collection::make($productVariants);

        foreach ($orderItems as $key => &$item) {

            $variant = $variantMap->firstWhere('id', $item['object_id']);
            $mediaUrl = Vite::getAssetUrl('images/placeholder.svg');
            if ($variant) {
                $mediaUrl = $variant->thumbnail ?: (new ProductAdminHelper())->getFeaturedMedia($variant->product_detail->featured_media);
                //Image Check Test
                //$mediaUrl = Arr::get($variant->media, 'meta_value.0.url', (new ProductAdminHelper())->getFeaturedMedia($variant->product_detail->featured_media));
            }

            Arr::set(
                $this->order, 'order_items.' . $key . '.media_url',
                $mediaUrl
            );

            Arr::set(
                $this->order,
                'order_items.' . $key . '.currency',
                Arr::get($this->order, 'currency', '')
            );
        }

        return $orderItems;

        // $productThumbnails = ProductMetaResource::findByIds($variationIds)->toArray();
        // $mediaMap = Collection::make($productThumbnails)->pluck('meta_value', 'object_id');

        // foreach ($order_items as $key => &$item) {
        //     if (isset($mediaMap[$item['object_id']])) {
        //         $mediaUrl = Arr::get($mediaMap[$item['object_id']], '0.url', '');
        //         Arr::set(
        //             $this->order,'order_items.'.$key.'.media_url',
        //             $mediaUrl
        //         );
        //     } else {
        //         Arr::set(
        //             $this->order,
        //             'order_items.' . $key . '.media_url',
        //             Vite::getEnqueuePath('images/placeholder.svg')
        //         );
        //     }
        //     Arr::set(
        //         $this->order,
        //         'order_items.' . $key . '.currency',
        //         Arr::get($this->order, 'currency', '')
        //     );
        // }
        // return $order_items;
    }

    public function __call($name, $arguments)
    {
        return Arr::get($this->order, $name);
    }

    public function totalDiscount($formatted = true)
    {
        $totalDiscount = Arr::get($this->order, 'manual_discount_total') + Arr::get($this->order, 'coupon_discount_total');

        if (!$formatted) {
            return $totalDiscount;
        }
        return CurrencySettings::getPriceHtml(
            $totalDiscount,
            Arr::get($this->order, 'currency'),
        );
    }

    public function discount($formatted = true)
    {


        if (intval(Arr::get($this->order, 'discount_total') == 0)) {
            return 0.0;
        }

        return (CurrencySettings::getPriceHtml(
            Arr::get($this->order, 'manual_discount_total'),
            Arr::get($this->order, 'currency'),
        ));
    }

    public function proratedCredit()
    {
        $proratedCredit = 0.0;
        $config = Arr::get($this->order, 'config', 0);
        if (empty($config)) {
            return 0.0;
        }

        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        if (is_array($config)) {
            $proratedCredit = Arr::get($config, 'prorated_credit', 0.0);
            if (intval($proratedCredit) == 0) {
                return 0.0;
            }
        }

        return intval($proratedCredit) > 0 ? CurrencySettings::getPriceHtml(
            $proratedCredit,
            Arr::get($this->order, 'currency'),
        ) : 0.0;

    }


}
