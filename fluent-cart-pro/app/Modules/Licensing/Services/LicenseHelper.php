<?php

namespace FluentCartPro\App\Modules\Licensing\Services;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Database\Orm\Collection;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseActivation;

class LicenseHelper
{
    public static function sanitizeSiteUrl($url)
    {
        if (!$url) {
            return $url;
        }

        $originalUrl = $url;

        // remove trailing slash
        $url = trim(urldecode($url));
        $url = untrailingslashit($url);
        // remove http:// or https://
        $url = preg_replace('/^https?:\/\//', '', $url);

        return apply_filters('fluent_cart/license/santized_url', $url, $originalUrl);
    }


    public static function getExpirationDateByVariation(ProductVariation $variation)
    {
        if ($variation->payment_type != 'subscription') {
            return null; // No expiration date for one-time purchases
        }

        $repeatInterval = (string)Arr::get($variation->other_info, 'repeat_interval', '');
        $trialDays = (int)Arr::get($variation->other_info, 'trial_days', 0);
        $intervals = [
            'daily'   => 'day',
            'weekly'  => 'week',
            'monthly' => 'month',
            'yearly'  => 'year',
            'half_yearly' => 'month',
            'quarterly' => 'month'
        ];

        $repeatInterval = $intervals[$repeatInterval] ?? $repeatInterval;

        // if ($license && ($license->status != Status::LICENSE_EXPIRED && $license->status != Status::LICENSE_DISABLED)){
            
        //     $baseTimestamp = strtotime($license->expiration_date);
        //     if ($repeatInterval == 'half_yearly') {
        //         $timestamp = strtotime('+ 6 months', $baseTimestamp);
        //     } else if ($repeatInterval == 'quarterly') {
        //         $timestamp = strtotime('+ 3 months', $baseTimestamp);
        //     } else {
        //         $timestamp = strtotime('+ 1 ' . $repeatInterval, $baseTimestamp);
        //     }

        //     return DateTime::anyTimeToGmt($timestamp)->format('Y-m-d H:i:s');
        // }

        if ($repeatInterval == 'half_yearly') {
            $timestamp = strtotime('+ 6 months', strtotime(DateTime::gmtNow())) + ($trialDays * DAY_IN_SECONDS);
        } else if ($repeatInterval == 'quarterly') {
            $timestamp = strtotime('+ 3 months', strtotime(DateTime::gmtNow())) + ($trialDays * DAY_IN_SECONDS);
        } else {
            $timestamp = strtotime('+ 1 ' . $repeatInterval, strtotime(DateTime::gmtNow())) + ($trialDays * DAY_IN_SECONDS);
        }

        $timestamp = apply_filters('fluent_cart/license/expiration_date_by_variation', $timestamp, [
            'variation' => $variation,
            'trial_days' => $trialDays
        ]);

        if ($timestamp === false) {
            return null; // Invalid timestamp
        }

        return DateTime::anyTimeToGmt($timestamp)->format('Y-m-d H:i:s');
    }

    public function getCustomerLicenses($request, $customer_id = null, $limit = null)
    {
        // modify status if expiration date is in past
        $licensesQuery = License::query()
            ->with(['customer', 'productVariant', 'order', 'product'])
            ->withCount('activations')
            ->where('customer_id', $customer_id)
            ->orderBy('id', 'desc');


        if ($limit) {
            $licenses = $licensesQuery->limit(2)->get();
        } else {
            $licenses = $licensesQuery->paginate($request->get('per_page', 10), ['*'], 'page', $request->get('page', 1));
        }


        foreach ($licenses as $license) {
            if ($license->expiration_date && DateTime::gmtNow() > $license->expiration_date) {
                $license->status = 'expired';
            }
        }

        return $licenses;
    }

    public function getLicenseByKey($license_key, $customer)
    {
        $license = License::query()
            ->where('license_key', $license_key)
            ->where('customer_id', $customer->id)
            ->with(['productVariant'])
            ->first();

        if (!$license) {
            return new \WP_Error('invalid_license', __('Invalid License key provided.', 'fluent-cart-pro'));
        }

        if ($license->expiration_date && DateTime::gmtNow() > $license->expiration_date) {
            $license->status = 'expired';
        }


        return $this->getLicenseDetails($license, ['activations', 'transactions', 'subscription']);
    }

    public function getLicenseById($id)
    {
        $license = License::with(['customer', 'productVariant', 'labels'])
            ->find($id);

        if (empty($license)) {
            return wp_send_json([
                'data' => [
                    'message'    => __('License not found', 'fluent-cart-pro'),
                    'buttonText' => __('Back to License List', 'fluent-cart-pro'),
                    'route'      => '/licenses'
                ],
                'code' => 'fluent_cart_entity_not_found',
            ], 404);
        }

        if ($license->expiration_date && DateTime::gmtNow() > $license->expiration_date) {
            $license->status = 'expired';
        }

        return $this->getLicenseDetails($license, ['orders', 'activations', 'labels']);
    }

    public function getRelatedOrderIds($orderId)
    {
        $orderIds = [$orderId];
        // get children orders
        $childOrders = Order::query()->where('parent_id', $orderId)->get();
        foreach ($childOrders as $childOrder) {
            $orderIds[] = $childOrder->id;
        }

        // also add those orders whose upgraded_from the current orderId
        $upgradedFrom = Order::query()->whereJsonContains('config', ['upgraded_from' => $orderId])->get();
        foreach ($upgradedFrom as $order) {
            $orderIds[] = $order->id;
        }

        return $orderIds;
    }

    /*
     * @return array
     * @param License $license
     * @param array $with additional data ['transactions', 'activations']
     * */
    public function getLicenseDetails($license, $with = []): array
    {
        $order = Order::with(['order_items', 'billing_address', 'shipping_address'])
            ->findOrFail($license->order_id);

        $transactions = [];
        if (in_array('transactions', $with)) {
            $relatedOrderIds = $this->getRelatedOrderIds($order->id);
            $transactions = OrderTransaction::query()
                ->whereIn('order_id', $relatedOrderIds)
                ->orderBy('id', 'DESC')
                ->get();
        }

        $orders = [];
        if (in_array('orders', $with)) {
            $relatedOrderIds = $this->getRelatedOrderIds($order->id);
            $orders = Order::query()
                ->whereIn('id', $relatedOrderIds)
                ->orderBy('id', 'DESC')
                ->get();
        }


        $activations = [];
        if (in_array('activations', $with)) {
            $activations = LicenseActivation::with('site')->where('license_id', $license->id)->get();
        }

        $relatedSubscription = null;
        if (in_array('subscription', $with)) {
            $relatedSubscription = $license->subscription;
        }

        $selectedLabels = [];
        if (in_array('labels', $with)) {
            $selectedLabels = Collection::make($license['labels'])->pluck('label_id');
        }

        $licenseProduct = Product::with('variants')
            ->where('ID', $license->product_id)
            ->first();

        $downloads = $license->getDownloads();

        return [
            'license'           => $license,
            'downloads'         => $downloads,
            'order'             => $order,
            'transactions'      => $transactions,
            'activations'       => $activations,
            'product'           => $licenseProduct,
            'selected_labels'   => $selectedLabels,
            'subscription'      => $relatedSubscription,
            'upgrade_path_base' => URL::getFrontEndUrl('custom-payment'),
            'orders'            => $orders
        ];
    }

    public static function getLicenseByKeyHashData($data, $checkActivation = true)
    {
        if (!empty($data['license_key'])) {
            $license = License::where('license_key', $data['license_key'])
                ->first();

            if (!$license) {
                return [new \WP_Error('invalid_license', __('Invalid License key provided.', 'fluent-cart-pro')), null];
            }
            // verify license validity
            if ($license->isExpired()) {
                $license->status = 'expired';
            }

            $data['site_url'] = static::sanitizeSiteUrl($data['site_url'] ?? '');

            $activation = LicenseActivation::where('license_id', $license->id)
                ->whereHas('site', function ($q) use ($data) {
                    $q->where('site_url', $data['site_url']);
                })
                ->first();

            if (!$activation && $checkActivation) {
                return [new \WP_Error('invalid_activation', __('License activation could not be found', 'fluent-cart-pro')), null];
            }
        } else if (!empty($data['activation_hash'])) {
            $activation = LicenseActivation::where('activation_hash', $data['activation_hash'])
                ->first();
            if (!$activation) {
                return [new \WP_Error('invalid_activation', __('License activation could not be found', 'fluent-cart-pro')), null];
            }
            $license = $activation->license;
            if (!$license) {
                return [new \WP_Error('invalid_license', __('Invalid License key provided.', 'fluent-cart-pro')), null];
            }
            // verify license validity
            if ($license->isExpired()) {
                $license->status = 'expired';
            }
        } else {
            return [new \WP_Error('invalid_license', __('Invalid License key provided.', 'fluent-cart-pro')), null];
        }

        return [$license, $activation];
    }

    public static function getProductLicenseConfig($productId, $context = 'edit')
    {
        $settings = ProductMeta::query()
            ->where('object_id', $productId)
            ->where('meta_key', 'license_settings')
            ->first();

        $settings = empty($settings) ? [] : $settings->meta_value;

        if ($context != 'edit') {
            return $settings;
        }

        $defaults = [
            'enabled'            => 'no',
            'version'            => '',
            'global_update_file' => [
                'id'     => '',
                'driver' => 'local',
                'path'   => '',
                'url'    => '',
            ],
            'variations'         => [],
            'wp'                 => [
                'is_wp'        => 'no',
                'readme_url'   => '',
                'banner_url'   => '',
                'icon_url'     => '',
                'required_php' => '',
                'required_wp'  => ''
            ]
        ];

        $settings = wp_parse_args($settings, $defaults);

        $variations = $settings['variations'];

        $variationIds = [];

        $productVariations = ProductVariation::with('media')->where('post_id', $productId)->get();

        foreach ($productVariations as $variation) {
            $variationId = $variation->id;
            $variationIds[] = $variationId;

            $isOneTimeVariation = $variation->payment_type === 'onetime';

            $subscriptionInfo = '';
            $setupFeeInfo = '';

            if (!$isOneTimeVariation) {
                $subscriptionInfo = Helper::generateSubscriptionInfo($variation->other_info, $variation->item_price);
                $setupFeeInfo = Helper::generateSetupFeeInfo($variation->other_info);
            }


            if (!isset($variations[$variationId])) {
                $variations[$variationId] = [
                    'variation_id'      => $variationId,
                    'title'             => $variation->variation_title,
                    'activation_limit'  => '',
                    'validity'          => static::getDefaultValidity($variation),
                    'media'             => $variation->media,
                    'subscription_info' => $subscriptionInfo,
                    'setup_fee_info'    => $setupFeeInfo
                ];
            } else {
                $variations[$variationId]['title'] = $variation->variation_title;
                $variations[$variationId]['media'] = $variation->media;
                $variations[$variationId]['subscription_info'] = $subscriptionInfo;
                $variations[$variationId]['setup_fee_info'] = $setupFeeInfo;
            }
        }

        // Remove deleted variations
        foreach ($variations as $variationId => $variation) {
            if (!in_array($variationId, $variationIds)) {
                unset($variations[$variationId]);
            }
        }

        $settings['variations'] = array_values($variations);

        return $settings;
    }

    public static function getDefaultValidity($variation): array
    {
        $paymentType = Arr::get($variation, 'other_info.payment_type');

        $unit = '';
        $value = 1;

        if ($paymentType === 'onetime') {
            $unit = 'lifetime';
            $value = 1;
        }

        $period = Arr::get($variation, 'other_info.repeat_interval');

        $unitMap = [
            'daily'   => 'day',
            'weekly'  => 'week',
            'monthly' => 'month',
            'yearly'  => 'year',
            'half_yearly' => 'month',
            'quarterly' => 'month'
        ];

        if (empty($unit)) {
            $unit = $unitMap[$period] ?? '';
        }

        $valueMap = [
            'daily'   => 1,
            'weekly'  => 1,
            'monthly' => 1,
            'yearly'  => 1,
            'half_yearly' => 6,
            'quarterly' => 3,
        ];
        $value = $valueMap[$period] ?? 1;

        $validity = [
            'unit' => $unit,
            'value' => $value
        ];

        return apply_filters('fluent_cart/license/default_validity_by_variation', $validity, [
            'variation' => $variation
        ]);
    }

    /**
     * Get the activation limit and expiration date for a product variation.
     *
     * @param ProductVariation $item The product variation item.
     * @return array|null An array containing 'limit' and 'expiration_date', or null if not found.
     */
    public static function getVariationLimitAndExpiration($item, $variationId = null): ?array
    {
        $variationId = $item->object_id;
        $settings = static::getVariationLicenseConfig($variationId, 'view');

        if ($variationSettings = Arr::get($settings, 'variations.' . $variationId)) {
            return [
                'limit'           => Arr::get($variationSettings, 'activation_limit', 0),
                'expiration_date' => static::getExpirationDate(Arr::get($variationSettings, 'validity', []))
            ];
        }

        return null;
    }

    public static function getValidityFromVariation($variationId): ?array
    {
        $settings = static::getVariationLicenseConfig($variationId, 'view');

        if ($variationSettings = Arr::get($settings, 'variations.' . $variationId)) {
            return [
                'limit'           => Arr::get($variationSettings, 'activation_limit', 0),
                'expiration_date' => static::getExpirationDate(Arr::get($variationSettings, 'validity', []))
            ];
        }

        return null;
    }


    public static function getVariationLicenseConfig($variationId, $context = 'edit')
    {
        $variation = ProductVariation::query()->find($variationId);
        if (!$variation) {
            return [];
        }

        $settings = ProductMeta::query()
            ->where('object_id', $variation->post_id)
            ->where('meta_key', 'license_settings')
            ->first();

        $settings = empty($settings) ? [] : $settings->meta_value;

        if ($context != 'edit') {
            return $settings;
        }

        $defaults = [
            'enabled'            => 'no',
            'version'            => '',
            'global_update_file' => [
                'driver' => 'local',
                'path'   => '',
                'url'    => '',
            ],
            'variations'         => [],
            'wp'                 => [
                'is_wp'        => 'no',
                'readme_url'   => '',
                'banner_url'   => '',
                'icon_url'     => '',
                'required_php' => '',
                'required_wp'  => ''
            ]
        ];

        $settings = wp_parse_args($settings, $defaults);

        $variations = $settings['variations'];

        $variationIds = [];

        $productVariations = ProductVariation::with('media')->where('post_id', $variation->post_id)->get();

        foreach ($productVariations as $productVariation) {
            $variationId = $productVariation->id;
            $variationIds[] = $variationId;
            if (!isset($variations[$variationId])) {
                $variations[$variationId] = [
                    'variation_id'     => $variationId,
                    'title'            => $productVariation->variation_title,
                    'activation_limit' => '',
                    'validity'         => [
                        'unit'  => 'year',
                        'value' => 1
                    ],
                    'media'            => $productVariation->media
                ];
            } else {
                $variations[$variationId]['title'] = $productVariation->variation_title;
                $variations[$variationId]['media'] = $productVariation->media;
            }
        }

        // Remove deleted variations
        foreach ($variations as $variationId => $variation) {
            if (!in_array($variationId, $variationIds)) {
                unset($variations[$variationId]);
            }
        }

        $settings['variations'] = array_values($variations);

        return $settings;
    }

    /**
     * Get the expiration date based on the validity settings.
     *
     * @param array $validity Validity settings containing 'unit' and 'value'.
     * @param string|null $from Optional starting date for calculation.
     * @return string|null Expiration date in GMT format or null if lifetime.
     */
    public static function getExpirationDate($validity, $from = null)
    {
        $unit = Arr::get($validity, 'unit');
        $value = Arr::get($validity, 'value');

        if ($unit === 'lifetime') {
            return null;
        }

        $timestamp = $from ? strtotime($from) : strtotime(DateTime::gmtNow());

        if ($timestamp === false) {
            return null;
        }

        $interval = "+{$value} {$unit}";

        $expireTime = strtotime($interval, $timestamp);

        return DateTime::anyTimeToGmt($expireTime);
    }


    public static function isLocalSite($url = '')
    {
        // Default to current site URL if none provided
        if (empty($url)) {
            return false;
        }

        // Define default staging patterns
        $subdomain_patterns = array(
            'staging',
            'stage',
            'dev',
            'test',
            'qa',
            'sandbox',
            'beta',
            'preview',
            'uat', // User Acceptance Testing
            'development'
        );

        $subfolder_patterns = array(
            'staging',
            'stage',
            'dev',
            'test',
            'qa',
            'sandbox',
            'beta',
            'preview',
            'uat',
            'development'
        );

        // Define popular WordPress staging domains (from hosting providers)
        $staging_domains = array(
            'localhost', // Local development
            '.wpengine.com',
            '.wpenginepowered.com',
            '.kinsta.cloud',
            '.sg-host.com', // SiteGround
            '.cloudwaysapps.com',
            '.flywheelsites.com', // Flywheel
            '.pantheonsite.io', // Pantheon
            '.dreamhostps.com', // DreamHost
            '.pressable.site', // Pressable
            '.staging.wpmudev.host', // WPMU DEV
            '.liquidweb.cloud', // Liquid Web
            '.runcloud.link', // RunCloud
            '.wp1.sh' // xCloud
        );

        // Allow filtering of patterns
        $subdomain_patterns = apply_filters('fluent_cart/license/staging_subdomain_patterns', $subdomain_patterns);
        $subfolder_patterns = apply_filters('fluent_cart/license/staging_subfolder_patterns', $subfolder_patterns);
        $staging_domains = apply_filters('fluent_cart/license/staging_domains', $staging_domains);

        // If $url doesn't contain scheme, add http:// to parse correctly
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'http://' . $url;
        }
        
        // Parse URL components
        $parsed_url = wp_parse_url($url);
        $host = isset($parsed_url['host']) ? strtolower($parsed_url['host']) : '';
        $path = isset($parsed_url['path']) ? strtolower($parsed_url['path']) : '';

        // Check subdomain patterns
        foreach ($subdomain_patterns as $pattern) {
            // Check if host starts with pattern or contains it as a subdomain
            if (strpos($host, $pattern . '.') === 0 || strpos($host, '.' . $pattern . '.') !== false) {
                return true;
            }
        }

        // Check subfolder patterns
        foreach ($subfolder_patterns as $pattern) {
            $regex = '#/(?:' . preg_quote($pattern, '#') . ')(?:/|$)#i';
            if (preg_match($regex, $path)) {
                return true;
            }
        }

        // Check popular staging domains
        foreach ($staging_domains as $domain) {
            if (strpos($host, $domain) !== false) {
                return true;
            }
        }

        // Allow final result to be filtered
        return apply_filters('fluent_cart/license/is_staging_site_result', false, $url);
    }

    public static function formatLicense(License $license)
    {
        if ($license->isExpired()) {
            $license->status = 'expired';
        }

        return [
            'license_key'      => $license->license_key,
            'status'           => $license->getHumanReadableStatus(),
            'expiration_date'  => $license->expiration_date,
            'variation_id'     => $license->variation_id,
            'activation_count' => $license->activation_count,
            'limit'            => $license->limit,
            'product_id'       => $license->product_id,
            'created_at'       => $license->created_at->format('Y-m-d H:i:s'),
            'title'            => $license->product ? $license->product->post_title : 'Unknown Product',
            'subtitle'         => $license->productVariant ? $license->productVariant->variation_title : '',
            'renewal_url'      => $license->getRenewalUrl(),
            'has_upgrades'     => $license->hasUpgrades(),
            'order'            => [
                'uuid' => $license->order ? $license->order->uuid : ''
            ]
        ];
    }

    public static function getLicenseGracePeriodDays()
    {
        return apply_filters('fluent_cart/license/grace_period_in_days', 15);
    }

}
