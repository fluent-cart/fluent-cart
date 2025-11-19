<?php

namespace FluentCart\App\Helpers;

use FluentCart\App\App;
use FluentCart\App\Models\OrderOperation;
use FluentCart\Framework\Support\Arr;

class UtmHelper
{

    public static function allowedUtmParameterKey(): array
    {
        $keys = [
            'utm_campaign',
            'utm_content',
            'utm_term',
            'utm_source',
            'utm_medium',
            'utm_id',
            'refer_url',
            'fbclid',
            'gclid'
        ];

        return apply_filters('fluent_cart/utm/allowed_keys', $keys, []);
    }

    public static function addUtmToOrder($orderId, $data = [])
    {
        $directValueKeys = [
            'utm_campaign',
            'utm_content',
            'utm_term',
            'utm_source',
            'utm_medium',
            'utm_id',
            'refer_url',
        ];

        $directValues = Arr::only($data, $directValueKeys);
        $metaValues = Arr::except($data, $directValueKeys);

        foreach ($directValues as $key => $value) {
            if ($key == 'refer_url') {
                $directValues[$key] = sanitize_url($value);
            } else {
                $directValues[$key] = sanitize_text_field($value);
            }
        }

        $allowedKeys = self::allowedUtmParameterKey();
        $allowedMetaValues = [];
        foreach ($metaValues as $key => $value) {
            if (!in_array($key, $allowedKeys)) {
                continue;
            }
            $allowedMetaValues[$key] = sanitize_text_field($value);
        }

        $directValues['meta'] = $allowedMetaValues;

        $hasValues = array_filter($directValues) || array_filter($allowedMetaValues);


        $oldOperation = OrderOperation::query()->where('order_id', $orderId)->first();

        if (empty($oldOperation)) {
            OrderOperation::query()->create(
                array_merge($directValues, ['order_id' => $orderId])
            );
        } else {
            $directValues['meta'] = Arr::mergeMissingValues($directValues['meta'], $oldOperation->meta);
            Arr::mergeMissingValues($directValues, Arr::except($oldOperation->toArray(), 'meta'));
            $directValues = array_merge($directValues);
            $oldOperation->update($directValues);
        }
    }

    public static function getUtmDataOfRequest(): array
    {
        $requestData = App::request()->all();
        $requestUtmData = Arr::get($requestData, 'utm_data', []);
        $sanitizedUtmData = [];
        // Sanitize UTM data
        foreach ($requestUtmData as $utmKey => $utmValue) {
            $sanitizedKey = sanitize_text_field($utmKey);
            $sanitizedUtmData[$sanitizedKey] = sanitize_text_field($utmValue);
        }

        return $sanitizedUtmData;
    }

}