<?php

namespace FluentCartPro\App\Modules\Licensing\Hooks\Handlers;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;

class LicenseGenerationHandler
{

    public function register()
    {
        // fluent_cart/order_paid -> generate
        add_action('fluent_cart/order_paid', [$this, 'maybeGenerateLicensesOnPurchaseSuccess'], 10, 1);

        // fluent_cart/order_fully_refunded -> revoke
        add_action('fluent_cart/order_fully_refunded', [$this, 'maybeRevokeLicensesOnFullRefund'], 10, 1);

        // fluent_cart/subscription_renewed -> extend validity
        add_action('fluent_cart/subscription_renewed', [$this, 'maybeExtendOnRenewal'], 10, 1);

        // fluent_cart/payments/subscription_expired -> expire the license
        add_action('fluent_cart/payments/subscription_expired', [$this, 'maybeExpireLicenseOnSubscriptionExpired'], 10, 1);

        // fluent_cart/payments/subscription_completed
        add_action('fluent_cart/payments/subscription_completed', [$this, 'maybeExtendLicenseToLifeTimeOnEot'], 10, 1);

        // Handle Order Upgraded here
        add_action('fluent_cart/order/upgraded', [$this, 'handleUpgradedOrderCompleted'], 10, 1);

        add_action('fluent_cart/subscription/data_updated', [$this, 'maybeExtendOnRenewal'], 10, 1);
        add_action('fluent_cart/subscription/subscription_canceled', [$this, 'maybeExtendOnRenewal'], 10, 1);

        add_filter('fluent_cart/order/expected_license_count', [$this, 'expectedLicenseCount'], 10, 2);

        add_action('fluent_cart/order/generateMissingLicenses', [$this, 'generateMissingLicenses'], 10, 1);

    }


    public function generateMissingLicenses($data)
    {
        $orderModel = Arr::get($data, 'order', null);

        $accpetedTypes = [
            Status::ORDER_TYPE_PAYMENT,
            Status::ORDER_TYPE_SUBSCRIPTION
        ];

        if (!$orderModel || !in_array($orderModel->type, $accpetedTypes) || License::query()->where('order_id', $orderModel->id)->exists()) {
            return;
        }

        $formattedLicenses = $this->getFormattedLicensesByOrder($orderModel);

        if (!$formattedLicenses) {
            return false;
        }

        if ($orderModel->lienses) {
            //pluck all product_id
            $existingProductIds = $orderModel->licenses->pluck('product_id')->toArray();

            //now filter the $formattedLicenses to remove the existing product_ids
            $formattedLicenses = array_filter($formattedLicenses, function ($formatedLicense) use ($existingProductIds) {
                return !in_array($formatedLicense['post_id'], $existingProductIds);
            });
        }

        $subscription = Subscription::query()->where('parent_order_id', $orderModel->id)->first();

        foreach ($formattedLicenses as $licenseData) {
            $this->addNewLicense($licenseData, $orderModel, $subscription);
        }

        wp_send_json([
            'message' => __('Licenses generated successfully', 'fluent-cart-pro')
        ], 200);
    }


    public function maybeGenerateLicensesOnPurchaseSuccess($data)
    {
        $orderModel = Arr::get($data, 'order', null);

        $accpetedTypes = [
            Status::ORDER_TYPE_PAYMENT,
            Status::ORDER_TYPE_SUBSCRIPTION
        ];

        if (!$orderModel || !in_array($orderModel->type, $accpetedTypes) || License::query()->where('order_id', $orderModel->id)->exists()) {
            return;
        }

        $formattedLicenses = $this->getFormattedLicensesByOrder($orderModel);

        if (!$formattedLicenses) {
            return false;
        }

        $subscription = Subscription::query()->where('parent_order_id', $orderModel->id)->first();

        foreach ($formattedLicenses as $licenseData) {
            $this->addNewLicense($licenseData, $orderModel, $subscription);
        }

        return true;
    }

    public function maybeRevokeLicensesOnFullRefund($data)
    {
        $orderModel = Arr::get($data, 'order');

        $licenses = License::query()
            ->whereIn('order_id', array_filter([$orderModel->id, $orderModel->parent_id]))
            ->get();

        if ($licenses->isEmpty()) {
            return false;
        }

        foreach ($licenses as $license) {
            $prevConfig = $license->config;
            if (!is_array($prevConfig)) {
                $prevConfig = [];
            }

            $prevConfig['reason'] = 'Refunded';

            $license->fill([
                'status' => 'disabled',
                'config' => $prevConfig,
            ]);

            $license->save();

            do_action('fluent_cart/licensing/license_disabled', [
                'license' => $license,
                'order'   => $orderModel,
            ]);

        }

    }

    public function maybeExtendOnRenewal($data)
    {
        $subscription = Arr::get($data, 'subscription');
        if (!$subscription) {
            return;
        }

        $licenses = License::query()
            ->where('subscription_id', $subscription->id)
            ->get();

        if ($licenses->isEmpty()) {
            return;
        }

        foreach ($licenses as $license) {
            $oldExpirationDate = $license->expiration_date;
            $license->expiration_date = $subscription->next_billing_date;
            $prevStatus = $license->status;

            if (!$license->isValid()) {
                $license->status = 'active';
            }

            $license->save();
            if ($oldExpirationDate !== $license->expiration_date) {
                do_action('fluent_cart/licensing/license_renewed', [
                    'license'      => $license,
                    'subscription' => $subscription,
                    'prev_status'  => $prevStatus,
                ]);
            }
        }

        return true;
    }

    public function maybeExpireLicenseOnSubscriptionExpired($data)
    {
        $subscription = Arr::get($data, 'subscription');
        if (!$subscription) {
            return;
        }

        $licenses = License::query()
            ->where('subscription_id', $subscription->id)
            ->get();

        if ($licenses->isEmpty()) {
            return;
        }

        foreach ($licenses as $license) {
            if ($license->status === 'expired' || $license->status === 'disabled') {
                continue;
            }

            $prevStatus = $license->status;

            $license->status = 'expired';
            $license->save();

            do_action('fluent_cart/licensing/license_expired', [
                'license'      => $license,
                'subscription' => $subscription,
                'prev_status'  => $prevStatus,
            ]);
        }

        return true;
    }

    public function maybeExtendLicenseToLifeTimeOnEot($data)
    {
        $subscription = Arr::get($data, 'subscription');
        if (!$subscription) {
            return;
        }

        $licenses = License::query()
            ->where('subscription_id', $subscription->id)
            ->get();

        if ($licenses->isEmpty()) {
            return;
        }

        foreach ($licenses as $license) {
            $prevStatus = $license->status;

            if (!$license->isValid()) {
                $license->status = 'active';
            }

            $license->expiration_date = null; // Set expiration date to null for lifetime license
            $license->save();

            do_action('fluent_cart/licensing/extended_to_lifetime', [
                'license'      => $license,
                'subscription' => $subscription,
                'prev_status'  => $prevStatus,
            ]);
        }

        return true;
    }

    public function handleUpgradedOrderCompleted($data)
    {
        $newOrderModel = Arr::get($data, 'order', null);
        $oldOrderModel = Arr::get($data, 'from_order', null);
        $upgradedFromVariantId = Arr::get($data, 'from_variant_id');

        if (!$newOrderModel || !$oldOrderModel) {
            return;
        }

        $oldOrderLicences = License::query()
            ->where('order_id', $oldOrderModel->id)
            ->get();

        if ($oldOrderLicences->isEmpty()) {
            return;
        }

        /**
         * Steps:
         *  - Find all licenses associated with the old order
         *  - Update the licenses to point to the new order
         *  - Maybe we have new limits and validity, so we need to check the new order items
         *  - Set the status of the licenses to 'active'
         *  - maybe it's a lifetime license, so we need to check if the new order has a lifetime licenses
         *  - check the subscription if it exists, and update the subscription_id in the licenses
         */
        $newFormattedLicenses = $this->getFormattedLicensesByOrder($newOrderModel);
        if (!$newFormattedLicenses) {
            return;
        }

        $subscription = Subscription::query()->where('parent_order_id', $newOrderModel->id)->first();

        foreach ($newFormattedLicenses as $newLicenseData) {

            $existingLicense = License::query()->where('product_id', $newLicenseData['product_id'])
                ->where('variation_id', $upgradedFromVariantId)
                ->where('order_id', $oldOrderModel->id)
                ->first();

            if ($existingLicense) {
                // This is the exisitng license, we need to update it
                $prevConfig = $existingLicense->config;
                $prevOrderIds = Arr::get($prevConfig, 'prev_order_ids', []);
                $prevOrderIds[] = $oldOrderModel->id;
                $prevConfig['prev_order_ids'] = array_values(array_unique($prevOrderIds));

                $existingLicense->fill([
                    'limit'              => $newLicenseData['limit'],
                    'expiration_date'    => $subscription ? $subscription->next_billing_date : $newLicenseData['expiration_date'],
                    'variation_id'       => $newLicenseData['variation_id'],
                    'order_id'           => $newOrderModel->id,
                    'subscription_id'    => $subscription ? $subscription->id : null,
                    'last_reminder_sent' => null,
                    'last_reminder_type' => null,
                    'config'             => $prevConfig,
                    'customer_id'        => $newOrderModel->customer_id,
                ]);

                $updates = $existingLicense->getDirty();
                $existingLicense->save();

                do_action('fluent_cart/licensing/license_upgraded', [
                    'license'      => $existingLicense,
                    'order'        => $newOrderModel,
                    'subscription' => $subscription,
                    'updates'      => $updates
                ]);
            } else {
                // This is a new license, we need to create it
                $this->addNewLicense($newLicenseData, $newOrderModel, $subscription);
            }
        }

        return true;
    }

    private function getFormattedLicensesByOrder(Order $orderModel)
    {
        $productIds = [];
        $variationIds = [];
        $quantityMaps = [];

        foreach ($orderModel->order_items as $item) {
            if ($item->payment_type == 'signup_fee') {
                continue;
            }

            $productIds[] = $item->post_id;
            $variationIds[] = $item->object_id;
            $quantityMaps[$item->object_id] = $item->quantity;
        }

        $productIds = array_values(array_unique($productIds));
        $variationIds = array_values(array_unique($variationIds));

        if (!$productIds || !$variationIds) {
            return false;
        }

        $licenseConfigs = ProductMeta::query()->whereIn('object_id', $productIds)
            ->where('meta_key', 'license_settings')
            ->get();

        if ($licenseConfigs->isEmpty()) {
            return;
        }

        $formattedLicenses = [];
        foreach ($licenseConfigs as $licenseConfig) {
            $value = $licenseConfig->meta_value;
            if (Arr::get($value, 'enabled') !== 'yes') {
                continue;
            }

            $variations = Arr::get($value, 'variations', []);
            foreach ($variations as $variation) {
                if (!in_array($variation['variation_id'], $variationIds)) {
                    continue;
                }

                $limit = (int)Arr::get($variation, 'activation_limit', 0);
                if ($limit < 0) {
                    continue;
                }

                $variantItem = ProductVariation::query()->where('id', $variation['variation_id'])
                    ->first();

                if (!$variantItem) {
                    continue;
                }

                if (!empty($quantityMaps[$variantItem->id])) {
                    $limit = $limit * $quantityMaps[$variantItem->id];
                }

                $formattedLicenses[$variation['variation_id']] = [
                    'prefix'          => Arr::get($value, 'prefix', ''),
                    'variation'       => $variantItem,
                    'limit'           => (int)$limit,
                    'product_id'      => $licenseConfig->object_id,
                    'variation_id'    => $variantItem->id,
                    'expiration_date' => LicenseHelper::getExpirationDateByVariation($variantItem)
                ];
            }
        }

        return $formattedLicenses;
    }

    public function expectedLicenseCount($expectedLicenseCount, array $args)
    {
        $productIds = [];
        foreach (Arr::get($args, 'order_items', []) as $item) {
            if (Arr::get($item, 'payment_type') == 'signup_fee') {
                continue;
            }
            $productIds[] = Arr::get($item, 'post_id');
        }

        $productIds = array_values(array_unique($productIds)) ?? [];

        $licenseConfigs = ProductMeta::query()->whereIn('object_id', $productIds)
            ->where('meta_key', 'license_settings')
            ->whereRaw('JSON_VALID(meta_value) = 1')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_value, '$.enabled')) = 'yes'")
            ->get();

        if (!$licenseConfigs->isEmpty()) {
            $expectedLicenseCount = $licenseConfigs->count();
        }

        return $expectedLicenseCount;
    }

    private function addNewLicense($licenseData, $orderModel, $subscription = null)
    {
        $data = [
            'status'          => 'inactive',
            'limit'           => $licenseData['limit'],
            'order_id'        => $orderModel->id,
            'product_id'      => $licenseData['product_id'],
            'variation_id'    => $licenseData['variation_id'],
            'customer_id'     => $orderModel->customer_id,
            'expiration_date' => $licenseData['expiration_date'],
            'config'          => [],
            'license_key'     => $licenseData['prefix'] . md5(
                    $licenseData['product_id'] . '-' . $licenseData['variation_id'] . '-' . $orderModel->id . '-' . wp_generate_uuid4()
                ),
        ];

        if ($subscription && $licenseData['expiration_date']) {
            $data['subscription_id'] = $subscription->id;
            $data['expiration_date'] = $subscription->next_billing_date;
        }

        $data = apply_filters('fluent_cart/licensing/license_create_data', $data, [
            'order'        => $orderModel,
            'variation'    => $licenseData['variation'],
            'subscription' => $subscription,
        ]);

        if (is_wp_error($data)) {
            return $data;
        }

        $license = License::query()->create($data);
        do_action('fluent_cart/licensing/license_issued', [
            'license'      => $license,
            'data'         => $data,
            'order'        => $orderModel,
            'subscription' => $subscription,
        ]);

        return $license;
    }

}
