<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Models\Meta;
use FluentCart\App\Modules\Integrations\AddOnModule;
use FluentCart\App\Modules\Integrations\GlobalIntegrationSettings;
use FluentCart\App\Modules\Integrations\IntegrationHelper;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class IntegrationController extends Controller
{
    public function index()
    {
        return (new AddOnModule())->updateAddOnsStatus($this->request->all());
    }


    public function updateStatus()
    {
        return (new GlobalIntegrationSettings())->updateNotificationStatus($this->request->all());
    }

    public function saveSettings(Request $request)
    {
        $requestData = $request->all();
        $integrationId = Arr::get($requestData, 'integration_id');
        $integrationMeta = null;
        $provider = Arr::get($requestData, 'integration_name');

        if ($integrationId) {
            $integrationMeta = Meta::where('id', $integrationId)
                ->where('object_type', 'order_integration')
                ->where('meta_key', $provider)
                ->first();

            if (!$integrationMeta) {
                return $this->sendError([
                    'message' => __('Integration not found', 'fluent-cart')
                ]);
            }
        }

        $integrationFeed = json_decode(Arr::get($requestData, 'integration'), true);

        $integrationFeedData = IntegrationHelper::validateAndFormatIntegrationFeedSettings($integrationFeed, [
            'provider'       => $provider,
            'scope'          => 'global',
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
            $integrationMeta = Meta::create([
                'object_type' => 'order_integration',
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

    public function lists()
    {
        return (new GlobalIntegrationSettings())->getIntegrationList($this->request->all());
    }

    /**
     * @return array
     * @throws \Exception
     * Get all Global Integrations
     */
    public function getFeeds(Request $request)
    {
        $availableIntegrations = apply_filters('fluent_cart/integration/order_integrations', [], []);
        $availableIntegrations = array_filter($availableIntegrations, function ($integration) {
            return in_array('global', Arr::get($integration, 'scopes', [])) && $integration['enabled'];
        });

        $formattedFeeds = [];
        if ($availableIntegrations) {
            $feeds = Meta::whereIn('meta_key', array_keys($availableIntegrations))
                ->where('object_type', 'order_integration')
                ->orderBy('id', 'ASC')
                ->get();

            foreach ($feeds as $feed) {
                $data = $feed->meta_value;
                $formattedFeeds[] = [
                    'id'       => $feed->id,
                    'name'     => Arr::get($data, 'name'),
                    'enabled'  => $data['enabled'],
                    'provider' => $feed->meta_key,
                    'feed'     => $data,
                    'scope'    => 'global',
                ];
            }
        }

        return [
            'feeds'                  => array_values($formattedFeeds),
            'available_integrations' => $availableIntegrations,
            'all_module_config_url'  => admin_url('admin.php?page=fluent-cart#/integrations')
        ];
    }

    public function changeStatus(Request $request, $integrationId)
    {
        $integration = Meta::query()
            ->where('object_type', 'order_integration')
            ->findOrFail($integrationId);

        $meta = $integration->meta_value;
        $status = $request->get('status', '');

        $meta['enabled'] = $status === 'yes' ? 'yes' : 'no';
        $integration->meta_value = $meta;
        $integration->save();

        return [
            'message' => __('Integration status updated successfully.', 'fluent-cart'),
            'meta'    => $meta
        ];
    }

    public function getSettings(Request $request)
    {
        $integration_name = $request->get('integration_name', false);
        $allIntegrations = apply_filters('fluent_cart/integration/order_integrations', []);

        if (!isset($allIntegrations[$integration_name])) {
            return $this->sendError([
                'message' => __('Integration not found', 'fluent-cart')
            ]);
        }

        $baseAddon = $allIntegrations[$integration_name];
        if (!in_array('global', Arr::get($baseAddon, 'scopes', [])) || !$baseAddon['enabled']) {
            return $this->sendError([
                'message' => __('This integration is not available for global scope or not enabled', 'fluent-cart')
            ]);
        }

        // This is just for validation, we will use the integration_id if provided
        $integrationId = $request->get('integration_id', 0);
        if ($integrationId) {
            $integrationMeta = Meta::where('id', $integrationId)
                ->where('object_type', 'order_integration')
                ->where('meta_key', $integration_name)
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
                'integration_id'   => $this->request->get('integration_id', 0)
            ],
            'global'
        );

        return $settings;
    }

    public function deleteSettings(Request $request, $integrationId)
    {
        $integration = Meta::query()
            ->where('object_type', 'order_integration')
            ->findOrFail($integrationId);

        $integration->delete();

        return [
            'message' => __('Integration has been deleted successfully.', 'fluent-cart'),
            'id'      => $integrationId
        ];
    }

    /**
     * @return array
     * @throws \Exception
     * Get global settings of an integration
     */
    public function getGlobalSettings()
    {
        return (new GlobalIntegrationSettings())->getGlobalSettingsData($this->request->all());
    }

    public function setGlobalSettings()
    {
        return (new GlobalIntegrationSettings())->saveGlobalSettingsData($this->request->all());
    }

    public function authenticateCredentials()
    {
        return (new GlobalIntegrationSettings())->authenticateCredentials($this->request->all());
    }

    public function chained()
    {
        return (new GlobalIntegrationSettings())->chainedData($this->request->all());
    }

    public function installPlugin()
    {
        return (new GlobalIntegrationSettings())->installPlugin($this->request->get('addon_key'));
    }

    public function getDynamicOptions(Request $request)
    {
        $optionKey = (string)$request->get('option_key', false);
        $search = (string)$request->get('search', '');

        if ($optionKey == 'post_type') {

            $postType = $request->get('sub_option_key', '');
            if (!$postType) {
                return [
                    'options' => []
                ];
            }

            $args = [
                'post_type'      => $postType,
                'posts_per_page' => 20
            ];

            if ($search) {
                $args['s'] = $search;
            }

            $posts = get_posts($args);

            $formattedPosts = [];
            if (!is_wp_error($posts)) {
                foreach ($posts as $post) {
                    $formattedPosts[$post->ID] = [
                        'id'    => strval($post->ID),
                        'title' => $post->post_title
                    ];
                }
            }

            $includedIds = (array)$request->get('values', []);

            if (!$includedIds) {
                return [
                    'options' => array_values($formattedPosts)
                ];
            }

            $includedIds = (array)$includedIds;

            $includedIds = array_diff($includedIds, array_keys($formattedPosts));
            if ($includedIds) {
                $posts = get_posts([
                    'post_type' => $postType,
                    'post__in'  => $includedIds
                ]);
                foreach ($posts as $post) {
                    $formattedPosts[$post->ID] = [
                        'id'    => strval($post->ID),
                        'title' => $post->post_title
                    ];
                }
            }

            return [
                'options' => array_values($formattedPosts)
            ];

        }

        if ($optionKey) {
            $options = apply_filters('fluent_cart/integration/integration_options_' . $optionKey, [], [
                'search'     => (string)$request->get('search', ''),
                'values'     => (array)$request->get('values', []),
                'option_key' => $optionKey
            ]);
        }

        return [
            'options' => isset($options) ? $options : []
        ];
    }

}
