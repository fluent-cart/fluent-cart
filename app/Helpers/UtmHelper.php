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
            // Ad network click identifiers. gbraid and wbraid carry iOS clicks where gclid
            // is absent, and gad_campaignid names the campaign on the url itself, which
            // survives cookie loss.
            'fbclid',
            'gclid',
            'gbraid',
            'wbraid',
            'gad_campaignid',
            'gad_source',
            'msclkid'
        ];

        return apply_filters('fluent_cart/utm/allowed_keys', $keys, []);
    }

    /**
     * Hostnames that belong to the same store network (e.g. child/product sites
     * that redirect visitors here for checkout). Referrers from these hosts are
     * treated as internal navigation and never recorded as refer_url — the real
     * source arrives as query params (refer_url, utm_*) appended by the child site.
     */
    public static function getInternalDomains(): array
    {
        $domains = apply_filters('fluent_cart/utm/internal_domains', []);

        if (!is_array($domains)) {
            return [];
        }

        $hosts = [];
        foreach ($domains as $domain) {
            if (!is_string($domain) || !$domain) {
                continue;
            }
            $domain = strtolower(trim($domain));
            if (strpos($domain, '//') !== false) {
                $domain = (string)wp_parse_url($domain, PHP_URL_HOST);
            }
            $domain = trim($domain, '/');
            if ($domain) {
                $hosts[] = $domain;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Record an order's traffic source on its operation row.
     *
     * The known UTM fields become columns; anything else on the allow list — click
     * identifiers such as gclid — goes into meta, which is where reporting and any
     * conversion upload will look for them.
     *
     * Safe to call more than once for the same order. Empty incoming values never
     * overwrite something already recorded.
     *
     * An order arriving with no source at all still gets its row when a cart hash is
     * given. Other code treats the operation row as the order's companion — ReceiptHandler
     * reads sales_recorded off it to decide whether a receipt is being seen for the first
     * time — so an untracked order must not be left without one.
     *
     * @param int $orderId
     * @param array $data Raw source data, normally the cart's utm_data.
     * @param string $cartHash Recorded alongside so the order can be traced back to its cart.
     * @return OrderOperation|null The row, or null when there was nothing to record it against.
     */
    public static function addUtmToOrder($orderId, $data = [], $cartHash = '')
    {
        if (!$orderId) {
            return null;
        }

        if (!is_array($data)) {
            $data = [];
        }

        $directValueKeys = [
            'utm_campaign',
            'utm_content',
            'utm_term',
            'utm_source',
            'utm_medium',
            'utm_id',
            'refer_url',
        ];

        $directValues = [];
        foreach (Arr::only($data, $directValueKeys) as $key => $value) {
            $value = $key == 'refer_url'
                ? self::normalizeReferUrl($value)
                : sanitize_text_field($value);

            if ($value !== '' && $value !== null) {
                $directValues[$key] = $value;
            }
        }

        $allowedKeys = self::allowedUtmParameterKey();
        $metaValues = [];
        foreach (Arr::except($data, $directValueKeys) as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                continue;
            }

            $value = sanitize_text_field($value);
            if ($value !== '' && $value !== null) {
                $metaValues[$key] = $value;
            }
        }

        // A cart hash on its own is reason enough: the row is the order's companion and
        // downstream code reads it whether or not the visit carried a source.
        if (!$directValues && !$metaValues && !$cartHash) {
            return null;
        }

        $operation = OrderOperation::query()->where('order_id', $orderId)->first();

        if (!$operation) {
            $attributes = array_merge($directValues, ['order_id' => $orderId]);

            if ($metaValues) {
                $attributes['meta'] = $metaValues;
            }

            if ($cartHash) {
                $attributes['cart_hash'] = $cartHash;
            }

            return OrderOperation::query()->create($attributes);
        }

        $attributes = $directValues;

        if ($metaValues) {
            $existingMeta = $operation->meta;
            $attributes['meta'] = Arr::mergeMissingValues(
                $metaValues,
                is_array($existingMeta) ? $existingMeta : []
            );
        }

        if ($cartHash && !$operation->cart_hash) {
            $attributes['cart_hash'] = $cartHash;
        }

        if ($attributes) {
            $operation->update($attributes);
        }

        return $operation;
    }

    /**
     * Reduce a referrer (full URL or bare host) to its bare domain:
     * no scheme, no www. prefix, no path — e.g. "google.com"
     */
    public static function normalizeReferUrl($value): string
    {
        $value = trim((string)$value);
        if (!$value) {
            return '';
        }

        if (strpos($value, '//') !== false) {
            $host = wp_parse_url($value, PHP_URL_HOST);
            if ($host) {
                $value = $host;
            }
        } else {
            $value = explode('/', $value)[0];
        }

        $value = strtolower($value);
        if (strpos($value, 'www.') === 0) {
            $value = substr($value, 4);
        }

        return sanitize_text_field($value);
    }

    /**
     * Choose the attribution block to record against an order.
     *
     * The browser resolves attribution before posting — UTMManager replaces the
     * attribution block on a fresh marketing touch and carries ad click identifiers
     * forward on their own longer window — so the posted block is already the
     * finished answer and is taken whole. Merging it with the cart column key by
     * key would re-introduce fields from a touch the browser deliberately dropped,
     * because a cart row is reused across visits and only refreshes when the
     * customer edits a checkout field.
     *
     * The cart remains the fallback for order creation that never went through a
     * browser, where it is the only source available.
     *
     * @param array $requestUtmData Attribution posted with the current request.
     * @param mixed $cartUtmData    The cart's stored block, or null.
     *
     * @return array
     */
    public static function resolveUtmData(array $requestUtmData, $cartUtmData = []): array
    {
        if ($requestUtmData) {
            return $requestUtmData;
        }

        return is_array($cartUtmData) ? $cartUtmData : [];
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