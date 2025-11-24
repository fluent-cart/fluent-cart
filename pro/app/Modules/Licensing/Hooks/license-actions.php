<?php

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;

/*
 * Product Level Settings
 */

(new \FluentCartPro\App\Modules\Licensing\Hooks\Handlers\LicenseGenerationHandler())->register();
(new \FluentCartPro\App\Modules\Licensing\Services\LicenseLog())->register();
(new \FluentCartPro\App\Modules\Licensing\Hooks\Handlers\LicenseApiHandler())->register();
(new \FluentCartPro\App\Modules\Licensing\Hooks\Handlers\LicenseSchedulerHandler())->register();
(new \FluentCartPro\App\Modules\Licensing\Hooks\Handlers\ManualLicenseRenewalHandler())->register();


add_action('fluent_cart/order_customer_changed', function ($data) {
    $connectedOrderIds = Arr::get($data, 'connected_order_ids', []);
    $newCustomer = Arr::get($data, 'new_customer', null);
    if (empty($newCustomer)) {
        return;
    }

    License::query()
        ->whereIn('order_id', $connectedOrderIds)
        ->update([
            'customer_id' => $newCustomer->id
        ]);
});

add_filter('fluent_cart/editor_shortcodes', function ($shortCodes) {
    if (ModuleSettings::isActive('license')) {
        $shortCodes['license'] = [
            'title'      => __('License', 'fluent-cart-pro'),
            'shortcodes' => [
                '{{order.licenses}}' => __('Order Licenses', 'fluent-cart-pro'),
            ],
        ];
    }
    return $shortCodes;
});

add_action('fluent_cart/customer_resources_moved', function ($data) {
    $fromCustomerId = Arr::get($data, 'from_customer_id', null);
    $toCustomerId = Arr::get($data, 'to_customer_id', null);

    if (empty($fromCustomerId) || empty($toCustomerId)) {
        return;
    }

    if ($toCustomerId === $fromCustomerId) {
        return;
    }

    License::query()->where('customer_id', $fromCustomerId)->update(['customer_id' => $toCustomerId]);
});

add_filter('fluent_cart/single_order_downloads', function ($downloadData, $data) {

    $order = Arr::get($data, 'order', null);
    $scope = Arr::get($data, 'scope', null);


    if ($scope !== 'email') {
        return $downloadData;
    }


    $licenses = License::query()->where('order_id', $order->id)->get();

    $formattedDownload = [];

    foreach ($downloadData as $key => $download) {
        $productId = Arr::get($download, 'product_id', null);
        $variationId = Arr::get($download, 'variation_id', null);

        $relatedLicense = $licenses->first(function ($license) use ($productId, $variationId) {
            return $license->product_id == $productId && $license->variation_id == $variationId;
        });

        if ($relatedLicense && $relatedLicense->isExpired()) {
            continue;
        } else {
            $formattedDownload[$key] = $download;
        }
    }


    return $formattedDownload;
}, 10, 2);

add_filter('fluent_cart/product_download/can_be_downloaded', function ($canBeDownloaded, $data) {
    $orders = Arr::get($data, 'orders', []);
    $orderIds = $orders->pluck('id')->toArray();
    $licenses = License::query()->whereIn('order_id', $orderIds)->get();

    if (!$licenses->count()) {
        return $canBeDownloaded;
    }
    
    $canBeDownloaded = false;
    foreach ($licenses as $license) {
        if ($license->isValid()) {
            $canBeDownloaded = true;
            break;
        }
    }

    return $canBeDownloaded;
}, 10, 2);

add_action('fluent_cart/order_deleted', function ($data) {
   $connectedOrderIds = $data['connected_order_ids'];
   License::query()->whereIn('order_id', $connectedOrderIds)->delete();
});
