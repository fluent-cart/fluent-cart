<?php

namespace FluentCart\App\Modules\ProductIntegration;

use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Modules\Integrations\GlobalIntegrationSettings;
use FluentCart\App\Modules\Integrations\GlobalNotificationHandler;
use FluentCart\Framework\Support\Arr;

class ProductIntegrationHandler
{
    public function handle($order, $customer, $targetHook, $group): void
    {
        if (empty($order->order_items)) {
            return;
        }

        $productIds = $order->order_items->pluck('post_id');
        $productFeeds = $this->getProductsFeeds($productIds);

        $formattedFeeds = (new GlobalIntegrationSettings())->formatFeedsData($productFeeds);

        (new GlobalNotificationHandler())->triggerNotification(
            $formattedFeeds,
            $order,
            $customer,
            $targetHook,
            $group,
            'product_integration'
        );
    }

    public function getProductsFeeds($productIds)
    {
        return ProductMeta::query()->where('object_type', 'product_integration')
            ->whereIn('object_id', $productIds)
            ->get();
    }

}
