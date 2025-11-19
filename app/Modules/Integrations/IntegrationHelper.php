<?php

namespace FluentCart\App\Modules\Integrations;

use FluentCart\Framework\Support\Arr;

class IntegrationHelper
{
    public static function validateAndFormatIntegrationFeedSettings(array $integration, $args = [])
    {
        $defaultSettings = [
            'provider'       => '',
            'scope'          => 'product',
            'product_id'     => '',
            'integration_id' => ''
        ];

        $args = wp_parse_args($args, $defaultSettings);

        if (empty($args['provider']) || empty($args['scope'])) {
            return new \WP_Error(
                'integration_validation_error',
                __('Provider and scope are required for integration settings.', 'fluent-cart')
            );
        }

        $settingsFields = apply_filters('fluent_cart/integration/get_integration_settings_fields_' . $args['provider'], [], $args);

        $fields = Arr::get($settingsFields, 'fields');

        $validKeys = ['enabled', 'conditional_variation_ids'];

        $errors = [];
        foreach ($fields as $field) {
            $key = (string)Arr::get($field, 'key');
            if (!$key) {
                continue;
            }

            $validKeys[] = $key;
            if (empty($field['required'])) {
                continue;
            }
            if (!array_key_exists($key, $integration) || (is_array($integration[$key]) ? empty($integration[$key]) : trim((string)$integration[$key]) === '')) {
                $label = $field['label'] ?? $key;
                $errors[$key] = sprintf(
                    /* translators: 1: attribute name */
                    __('%s is required.', 'fluent-cart'), $label);
            }
        }

        if ($errors && $integration['enabled']) {
            return new \WP_Error(
                'integration_validation_error',
                __('Please fill up the required fields:', 'fluent-cart'),
                $errors
            );
        }

        $validatedData = Arr::only($integration, $validKeys);

        return apply_filters('fluent_cart/integration/integration_saving_data_' . $args['provider'], $validatedData, [
            'provider'       => $args['provider'],
            'scope'          => $args['scope'],
            'product_id'     => $args['product_id'],
            'integration_id' => $args['integration_id'],
            'raw_data'       => $integration
        ]);
    }

    public static function formatFeedDataForEditing($meta, $args = [])
    {
        $data = $meta->meta_value;

        $enabled = $data['enabled'];
        $enabled = $enabled === true || $enabled === 'true';

        $args['feed'] = $meta;

        $data = apply_filters('fluent_cart/integration/editing_integration_' . $meta->meta_key, $data, $args);

        $feedData = [
            'id'       => $meta->id,
            'name'     => Arr::get($data, 'name'),
            'enabled'  => $enabled,
            'provider' => $meta->meta_key,
            'feed'     => $data,
            'scope'    => $args['scope'] ?? 'product',
        ];

        return $feedData;
    }
}
