<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\Product;
use FluentCart\App\Modules\Integrations\GlobalIntegrationSettings;
use FluentCart\App\Modules\Integrations\IntegrationHelper;
use FluentCart\Framework\Http\Controller;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ProductIntegrationsController extends Controller
{
    public function getFeeds(Request $request)
    {
        $formattedFeeds = $this->getNotificationFeeds($request->productId);
        $availableIntegrations = apply_filters('fluent_cart/integration/order_integrations', []);

        $availableIntegrations = array_filter($availableIntegrations, function ($integration) {
            return in_array('product', Arr::get($integration, 'scopes', [])) && $integration['enabled'];
        });

        $integrationKeys = array_keys($availableIntegrations);

        $formattedFeeds = array_filter($formattedFeeds, function ($feed) use ($integrationKeys) {
            return in_array($feed['provider'], $integrationKeys);
        });

        return [
            'feeds'                  => array_values($formattedFeeds),
            'available_integrations' => $availableIntegrations,
            'all_module_config_url'  => admin_url('admin.php?page=fluent-cart#/integrations')
        ];
    }

    /**
     * Get product-specific integration settings
     *
     * @param \FluentCart\Framework\Http\Request\Request $request
     * @param int $product_id
     * @param string $integration_name
     */
    public function getProductIntegrationSettings(Request $request, $product_id, $integration_name)
    {
        $product = Product::findOrFail($product_id);

        $allIntegrations = apply_filters('fluent_cart/integration/order_integrations', []);

        if (!isset($allIntegrations[$integration_name])) {
            return $this->sendError([
                'message' => __('Integration not found', 'fluent-cart')
            ]);
        }

        $baseAddon = $allIntegrations[$integration_name];
        if (!in_array('product', Arr::get($baseAddon, 'scopes', [])) || !$baseAddon['enabled']) {
            return $this->sendError([
                'message' => __('This integration is not available for products or not enabled', 'fluent-cart')
            ]);
        }

        // This is just for validation, we will use the integration_id if provided
        $integrationId = $request->get('integration_id', 0);
        if ($integrationId) {
            $integrationMeta = ProductMeta::where('id', $integrationId)
                ->where('object_id', $product->ID)
                ->where('object_type', 'product_integration')
                ->first();

            if (!$integrationMeta) {
                return $this->sendError([
                    'message' => __('Integration not found', 'fluent-cart')
                ]);
            }
        }

        $integrationManager = new GlobalIntegrationSettings();
        $settings = $integrationManager->getIntegrationSettings(
            [
                'integration_name' => $integration_name,
                'integration_id'   => $this->request->get('integration_id', 0),
                'product_id'       => $product_id
            ],
            'product'
        );

        $settings['product_variations'] = $product->variants()
            ->select('id', 'variation_title')
            ->get()
            ->map(function ($variation) {
                return [
                    'id'    => $variation->id,
                    'title' => $variation->variation_title
                ];
            })->toArray();

        if (empty($settings['settings']['conditional_variation_ids'])) {
            $settings['settings']['conditional_variation_ids'] = [];
        }

        $settings['scope'] = 'product';

        return $settings;
    }


    /**
     * @param \FluentCart\Framework\Http\Request\Request $request
     * @param $product_id
     * @return array|\WP_REST_Response
     */
    public function saveProductIntegration(Request $request, $product_id)
    {
        $product = Product::find($product_id);
        if (!$product) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart')
            ]);
        }

        $requestData = $request->all();
        $integrationId = Arr::get($requestData, 'integration_id');
        $integrationMeta = null;
        if ($integrationId) {
            $integrationMeta = ProductMeta::where('id', $integrationId)
                ->where('object_id', $product_id)
                ->where('object_type', 'product_integration')
                ->first();

            if (!$integrationMeta) {
                return $this->sendError([
                    'message' => __('Integration not found', 'fluent-cart')
                ]);
            }
        }

        $integrationFeed = json_decode(Arr::get($requestData, 'integration'), true);
        $provider = Arr::get($requestData, 'integration_name');

        $integrationFeedData = IntegrationHelper::validateAndFormatIntegrationFeedSettings($integrationFeed, [
            'provider'       => $provider,
            'scope'          => 'product',
            'product_id'     => $product_id,
            'integration_id' => $integrationId
        ]);

        if (is_wp_error($integrationFeedData)) {
            return $this->sendError([
                'message' => $integrationFeedData->get_error_message(),
                'errors'  => $integrationFeedData->get_error_data()
            ]);
        }

        if ($integrationMeta) {
            $integrationMeta->meta_value = $integrationFeedData;
            $integrationMeta->save();
        } else {
            $integrationMeta = ProductMeta::create([
                'object_id'   => $product_id,
                'object_type' => 'product_integration',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'    => $provider,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'  => $integrationFeedData
            ]);
        }

        do_action('fluent_cart/reindex_integration_feeds', []);

        return [
            'message'          => __('Integration has been successfully saved', 'fluent-cart'),
            'integration_id'   => $integrationId,
            'integration_name' => $provider,
            'created'          => $integrationMeta->wasRecentlyCreated,
            'feedData'         => $integrationFeedData
        ];
    }

    public function changeStatus(Request $request)
    {
        $productId = $request->get('product_id');
        $notificationId = $request->get('notification_id');

        if (!$productId || !$notificationId) {
            return $this->sendError([
                'message' => __('Product ID and Notification ID are required', 'fluent-cart')
            ]);
        }

        $product = Product::findOrFail($productId);
        $notification = ProductMeta::where('id', $notificationId)
            ->where('object_id', $product->ID)
            ->where('object_type', 'product_integration')
            ->first();

        if (!$notification) {
            return $this->sendError([
                'message' => __('Notification not found', 'fluent-cart')
            ]);
        }

        $metaValue = $notification->meta_value;

        $metaValue['enabled'] = $request->get('status') === 'yes' ? 'yes' : 'no';
        $notification->meta_value = $metaValue;
        $notification->save();

        return [
            'message' => __('Integration status has been updated', 'fluent-cart')
        ];
    }

    public function deleteProductIntegration(Request $request, $product_id, $integration_id)
    {
        // Verify product exists
        $product = Product::findOrFail($product_id);

        // Delete integration
        ProductMeta::where('id', $integration_id)
            ->where('object_id', $product->ID)
            ->where('object_type', 'product_integration')
            ->delete();

        return [
            'message' => __('Integration deleted successfully', 'fluent-cart')
        ];
    }

    /**
     * Get integration settings for a specific integration type
     *
     * @param string $integration_type
     */
    private function getIntegrationSettings($integration_type)
    {
        $integrationManager = new GlobalIntegrationSettings();
        return $integrationManager->getIntegrationSettings(
            [
                'integration_name' => $integration_type
            ],
            'product'
        );
    }


    private function getNotificationFeeds($productId)
    {
        $feeds = ProductMeta::query()
            ->where('object_id', $productId)
            ->where('object_type', 'product_integration')
            ->orderBy('id', 'ASC')
            ->get();

        $formattedFeeds = [];

        foreach ($feeds as $feed) {
            $data = $feed->meta_value;
            $formattedFeeds[] = [
                'id'       => $feed->id,
                'name'     => Arr::get($data, 'name'),
                'enabled'  => $data['enabled'],
                'provider' => $feed->meta_key,
                'feed'     => $data,
                'scope'    => 'product',
            ];
        }

        return $formattedFeeds;
    }

}
