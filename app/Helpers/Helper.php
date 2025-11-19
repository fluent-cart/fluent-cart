<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\Confirmation;
use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\Api\Taxonomy;
use FluentCart\App\App;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\User;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Http\URL as BaseUrl;
use FluentCart\Framework\Support\Arr;

class Helper
{
    const PRODUCT_TYPE_SIMPLE = 'simple';
    const PRODUCT_TYPE_SIMPLE_VARIATION = 'simple_variations';
    const PRODUCT_TYPE_ADVANCE_VARIATION = 'advanced_variations';

    const INSTANT_CHECKOUT_URL_PARAM = 'fct_cart_hash';

    const IN_STOCK = 'in-stock';
    const OUT_OF_STOCK = 'out-of-stock';

    const ROLE_CAPABILITY_PREFIX = 'fluent_cart/permissions/';

    const USER_ROLE = 'fluent_cart_customer';

    public static function getUidSerial()
    {
        static $id = 0;

        $id = $id + 1;

        return $id;

    }

    public static function getRestInfo()
    {
        $app = App::getInstance();

        $ns = $app->config->get('app.rest_namespace');
        $ver = $app->config->get('app.rest_version');

        return [
            'base_url'  => self::getBaseRestUrl(),
            'url'       => self::getFullRestUrl($ns, $ver),
            'nonce'     => wp_create_nonce('wp_rest'),
            'namespace' => $ns,
            'version'   => $ver,
        ];
    }


    /**
     * Get base rest url by examining the permalink.
     *
     * @see https://wordpress.stackexchange.com/questions/273144/can-i-use-rest-api-on-plain-permalink-format
     *
     * @return string
     */
    protected static function getBaseRestUrl()
    {
        if (get_option('permalink_structure')) {
            return esc_url_raw(rest_url());
        }

        return esc_url_raw(
            rtrim(get_site_url(), '/') . "/?rest_route=/"
        );
    }

    /**
     * Get the full rest url by examining the permalink
     * (full means, including the namespace/version).
     *
     * @see https://wordpress.stackexchange.com/questions/273144/can-i-use-rest-api-on-plain-permalink-format
     *
     * @return string
     */
    protected static function getFullRestUrl($ns, $ver)
    {
        if (get_option('permalink_structure')) {
            return esc_url_raw(rest_url($ns . '/' . $ver));
        }

        return esc_url_raw(
            rtrim(get_site_url(), '/') . "/?rest_route=/{$ns}/{$ver}"
        );
    }


    public static function shopConfig($key = false)
    {
        /**
         * todo - need to review for more improvement : AR
         */
        $currencySettings = (fluentCart(CurrencySettings::class))->get();
        $storeSettings = (new StoreSettings())->get([
            'store_name', 'store_logo'
        ]);

        $settings = array_merge($currencySettings, $storeSettings);


        if (!$key) {
            return $settings;
        }

        if (is_array($key)) {
            return Arr::only($settings, $key);
        } else {
            return Arr::get($settings, $key);
        }

    }

    public static function invoiceSettings($key = false)
    {
        $settings = [
            'invoice_prefix' => 'AS-',
        ];
        if (!$key) {
            return $settings;
        }

        return Arr::get($settings, $key);
    }

    public static function getOrderStatuses()
    {
        return apply_filters('fluent-cart/order_statuses', [
            'on-hold'    => __('On Hold', 'fluent-cart'),
            'processing' => __('Processing', 'fluent-cart'),
            'completed'  => __('Completed', 'fluent-cart'),
            //'archived' => __('Archived', 'fluent-cart'),
            'cancelled'  => __('Cancelled', 'fluent-cart'),
        ], []);
    }

    public static function getEditableOrderStatuses()
    {
        return apply_filters('fluent-cart/editable_order_statuses', [
            'on-hold'    => __('On Hold', 'fluent-cart'),
            'processing' => __('Processing', 'fluent-cart'),
            'completed'  => __('Completed', 'fluent-cart'),
            //  'archived' => __('Archived', 'fluent-cart'),
            'cancelled'  => __('Cancelled', 'fluent-cart')
        ], []);
    }

    public static function getEditableCustomerStatuses()
    {
        return apply_filters('fluent-cart/editable_customer_statuses', [
            'active'   => __('Active', 'fluent-cart'),
            'inactive' => __('Inactive', 'fluent-cart'),
        ], []);
    }

    public static function getShippingStatuses()
    {
        return apply_filters('fluent-cart/shipping_statuses', [
            'unshipped'   => __('Unhipped', 'fluent-cart'),
            'shipped'     => __('Shipped', 'fluent-cart'),
            'delivered'   => __('Delivered', 'fluent-cart'),
            'unshippable' => __('Unshippable', 'fluent-cart'),
        ], []);
    }

    public static function getEditableShippingStatuses()
    {
        return apply_filters('fluent-cart/editable_order_statuses', [
            'unshipped'   => __('Unhipped', 'fluent-cart'),
            'shipped'     => __('Shipped', 'fluent-cart'),
            'delivered'   => __('Delivered', 'fluent-cart'),
            'unshippable' => __('Unshippable', 'fluent-cart'),
        ], []);
    }

    public static function getOrderSuccessStatuses()
    {
        return [
            'completed',
            // 'archived',
            'processing',
        ];
    }

    public static function getOrderFailedStatuses()
    {
        return [
            'failed',
            //'refunded',
            'cancelled',
        ];
    }

    public static function getTransactionSuccessStatuses()
    {
        return [
            'paid',
        ];
    }

    public static function getTransactionStatuses($withLabel = true)
    {
        $statuses = apply_filters('fluent-cart/transaction_statuses', [
            'pending'         => __('Pending', 'fluent-cart'),
            'paid'            => __('Paid', 'fluent-cart'),
            'require_capture' => __('Authorized (Require Capture)', 'fluent-cart'),
            'failed'          => __('Failed', 'fluent-cart'),
            'refunded'        => __('Refunded', 'fluent-cart'),
            'active'          => __('Active', 'fluent-cart'),
        ], []);

        if ($withLabel) {
            return $statuses;
        }

        return array_keys($statuses);
    }

    public static function getEditableTransactionStatuses($withLabel = true)
    {
        $statuses = apply_filters('fluent-cart/editable_transaction_statuses', [
            'pending'  => __('Pending', 'fluent-cart'),
            'paid'     => __('Paid', 'fluent-cart'),
            'failed'   => __('Failed', 'fluent-cart'),
            'refunded' => __('Refunded', 'fluent-cart'),
        ], []);

        if ($withLabel) {
            return $statuses;
        }

        return array_keys($statuses);
    }

    public static function loadSpoutLib()
    {
        static $loaded;

        if ($loaded) {
            return $loaded;
        }

        require_once FLUENTCART_PLUGIN_PATH . 'app/Services/Libs/Spout/Autoloader/autoload.php';

        return true;
    }

    public static function productStatuses($withLabel = true): array
    {
        $statues = [
            'publish' => __('Publish', 'fluent-cart'),
            'draft'   => __('Draft', 'fluent-cart'),
            'future'  => __('Scheduled', 'fluent-cart'),
            'private' => __('Private', 'fluent-cart'),
            'trash'   => __('Trashed', 'fluent-cart'),
        ];

        if ($withLabel) {
            return $statues;
        }

        return array_keys($statues);
    }

    public static function productAdminAllStatuses()
    {
        $statuses = self::productStatuses();
        unset($statuses['trash']);
        return array_keys($statuses);
    }

    public static function getCartDriver()
    {
        return 'db';
    }

    /**
     * Convert an amount to a formatted decimal string.
     *
     * @param float $amount The amount to convert. (required)
     * @param bool $withCurrency Whether to include the currency symbol. (optional, default: true)
     * @param string|null $currencyCode The currency code to use. (optional, default: null)
     * @param bool $formatted Whether to format the amount. (optional, default: true)
     * @param bool $showDecimals Whether to show decimal places. (optional, default: true)
     * @param bool $thousand_separator Whether to include thousand separators. (optional, default: true)
     *
     * @return string The formatted amount.
     *
     * @dev Note: If you don't want the thousand separator to be applied,
     * you need to set $formatted to true and $thousand_separator to false.
     *
     * @hook fluent_cart/hide_unnecessary_decimals - Filter to control whether unnecessary decimals (like .00) should be hidden.
     * Usage: add_filter('fluent_cart/hide_unnecessary_decimals', '__return_true'); // This will show 10 instead of 10.00
     */
    public static function toDecimal($amount, $withCurrency = true, $currencyCode = null, $formatted = true, $showDecimals = true, $thousand_separator = true)
    {
        if (!is_numeric($amount)) {
            return $amount;
        }

        // Set default decimal places to 2
        $decimal = 2;

        // Check if the shop is using a zero-decimal currency
        if (self::shopConfig('is_zero_decimal')) {
            $decimal = 0;
        }

        // Use provided or default currency code
        if (!$currencyCode) {
            $currencyCode = self::shopConfig('currency');
        }

        // Get currency sign
        $sign = CurrenciesHelper::getCurrencySign($currencyCode);

        // Adjust amount for currencies that aren't zero-decimal
        if (!CurrenciesHelper::isZeroDecimal($sign)) {
            $amount = floatVal($amount / 100);
        }

        // If $showDecimals is false, we override decimal places to 0
        if (!$showDecimals) {
            $decimal = 0;
        }

        // Format the amount based on the decimal configuration
        if ($formatted) {
            $decimal_separator = self::shopConfig('decimal_separator') === 'comma' ? ',' : '.';

            $thousand_separator = $decimal_separator === ',' ? '.' : ',';

            // Check if we should hide unnecessary decimal places (e.g., 10.00 -> 10)
            $hideUnnecessaryDecimals = apply_filters('fluent_cart/hide_unnecessary_decimals', false, [
                'amount'  => $amount,
                'decimal' => $decimal
            ]);

            $amount = number_format(
                $amount,
                $decimal,
                $decimal_separator,
                $thousand_separator
            );

            if ($hideUnnecessaryDecimals && $decimal > 0) {
                // Remove trailing zeros only from the decimal portion
                $parts = explode($decimal_separator, $amount);
                if (count($parts) === 2) {
                    $parts[1] = rtrim($parts[1], '0');
                    if ($parts[1] === '') {
                        $amount = $parts[0];
                    } else {
                        $amount = $parts[0] . $decimal_separator . $parts[1];
                    }
                }
            }
        }

        // If $withCurrency is false, just return the formatted amount
        if (!$withCurrency) {
            return $amount;
        }

        // Get currency position and return formatted amount with currency
        $position = self::shopConfig('currency_position');

        switch ($position) {
            case 'before':
                return $sign . $amount;
            case 'after':
                return $amount . $sign;
            case 'iso_before':
                return $currencyCode . ' ' . $amount;
            case 'iso_after':
                return $amount . ' ' . $currencyCode;
            case 'symbool_before_iso':
                return $sign . $amount . ' ' . $currencyCode;
            case 'symbool_after_iso':
                return $currencyCode . ' ' . $amount . $sign;
            case 'symbool_and_iso':
                return $currencyCode . ' ' . $sign . $amount;
            default:
                return $sign . $amount;
        }
    }

    public static function toCent($amount): int
    {
        if (!is_numeric($amount)) {
            return 0;
        }

        $amount = floatval($amount) * 100; // Convert to float and multiply
        $amount = (int)round($amount); // Round to nearest integer, then cast
        return $amount;
    }

    public static function toDecimalWithoutComma($amount)
    {

        if (!is_numeric($amount)) {
            return 0;
        }

        // Convert to float and divide by 100
        $result = floatval($amount) / 100;

        // Ensure exactly two decimal places
        return round($result, 2);
    }

    public static function getCustomerByUser($user)
    {
        if (is_numeric($user)) {
            $user = get_user_by('ID', $user);
        }

        if (!$user) {
            return null;
        }

        return Customer::query()->where('user_id', $user->ID)
            ->orWhere('email', $user->user_email)
            ->first();
    }

    /**
     * @param array $order_data
     *
     * @return array return ['billing_address','shipping_address','others'];
     */

    /**
     *
     * @return string
     */
    public static function getProductImageBaseUri(): string
    {
        $uploads = wp_upload_dir();

        return $uploads['baseurl'] . '/' . FLUENTCART_UPLOAD_DIR . '/product_image/';
    }

    public static function getProductImageBaseDir()
    {
        $uploads = wp_upload_dir();

        return $uploads['basedir'] . '/' . FLUENTCART_UPLOAD_DIR . '/product_image/';
    }

    public static function getAvailableCurrencyList()
    {
        return apply_filters('fluent-cart/available_currencies', [
            'BDT' => [
                "label"  => __('Bangladeshi Taka', 'fluent-cart'),
                "value"  => 'BDT',
                "symbol" => '৳',
            ],
            'USD' => [
                "label"  => __('United State Dollar', 'fluent-cart'),
                "value"  => 'USD',
                "symbol" => '$',
            ],
            'GBP' => [
                "label"  => __('United Kingdom', 'fluent-cart'),
                "value"  => 'GBP',
                "symbol" => '£',
            ],
        ], []);
    }

    public static function getSymbolForCurrency($currency = 'BDT')
    {

        $symbol = '৳';
        $list = self::getAvailableCurrencyList();

        return $list[$currency]['symbol'] ?? $symbol;
    }

    public function getConfirmationSettings()
    {
        return (new Confirmation())->get();
    }


    /**
     *
     * @param $remove
     * @return string
     */
    public static function getCheckoutPageLinkAfterRemovingGetParams($remove = [])
    {

        global $fct_store;

        $link = Arr::get($fct_store, 'checkout_link');

        $link .= '?';

        if (!empty($remove)) {
            $params = App::request()->all();

            foreach ($params as $key => $val) {

                if (!in_array($key, $remove)) {

                    $link .= $key . '=' . $val . '&';
                }
            }
        }

        return rtrim($link, '&');
    }


    /**
     *
     * @return bool
     */
    public static function isSingleProductPage(): bool
    {
        return is_singular([FluentProducts::CPT_NAME]);
    }

    public static function isTrue($array, $key)
    {
        $value = $array[$key] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 'false' || !$value) {
            return false;
        }
        return true;
    }

    public static function is_valid_json($string): bool
    {
        if (!is_string($string)) {
            return false;
        }

        $trimmed = trim($string);

        // Basic check: must start with { or [ and end with } or ]
        if (!preg_match('/^(\{.*\}|\[.*\])$/s', $trimmed)) {
            return false;
        }

        json_decode($trimmed);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function getStockStatuses($withLabel = true)
    {
        $statues = [
            'in-stock'     => __('In Stock', 'fluent-cart'),
            'out-of-stock' => __('Out Of Stock', 'fluent-cart'),
        ];

        if ($withLabel) {
            return $statues;
        }

        return array_keys($statues);
    }

    public static function getFulfilmentTypes($withLabel = true)
    {
        $statues = [
            'physical' => __('Physical', 'fluent-cart'),
            'digital'  => __('Digital', 'fluent-cart'),
        ];

        if ($withLabel) {
            return $statues;
        }

        return array_keys($statues);
    }

    public static function getVariationTypes($withLabel = true)
    {
        $statues = [
            'simple'            => __('Simple', 'fluent-cart'),
            'simple_variations' => __('Simple Variation', 'fluent-cart'),
        ];

        if ($withLabel) {
            return $statues;
        }

        return array_keys($statues);
    }

    public static function isValueEncrypted($raw_value)
    {
        if (!$raw_value || !is_string($raw_value) || !extension_loaded('openssl')) {
            return false;
        }

        // Check if input is valid base64
        if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $raw_value)) {
            return false;
        }

        $decoded = base64_decode($raw_value, true);
        if ($decoded === false) {
            return false;
        }

        // Check if decoded string is long enough for IV
        $method = 'aes-256-ctr';
        $ivlen = openssl_cipher_iv_length($method);
        if (strlen($decoded) < $ivlen) {
            return false;
        }

        $iv = substr($decoded, 0, $ivlen);
        $ciphertext = substr($decoded, $ivlen);

        $key = (defined('FLUENT_CART_ENCRYPTION_KEY'))
            ? FLUENT_CART_ENCRYPTION_KEY
            : ((defined('LOGGED_IN_KEY') && '' !== LOGGED_IN_KEY)
                ? LOGGED_IN_KEY
                : 'this-is-a-fallback-key-but-not-secure');
        $salt = (defined('LOGGED_IN_SALT') && '' !== LOGGED_IN_SALT)
            ? LOGGED_IN_SALT
            : 'this-is-a-fallback-salt-but-not-secure';

        $value = openssl_decrypt($ciphertext, $method, $key, 0, $iv);
        if ($value === false) {
            return false;
        }


        return substr($value, -strlen($salt)) === $salt;
    }

    public static function encryptKey($value)
    {
        if (!$value) {
            return $value;
        }

        if (!extension_loaded('openssl')) {
            return $value;
        }

        if (self::isValueEncrypted($value)) {
            return $value;
        }

        $salt = (defined('LOGGED_IN_SALT') && '' !== LOGGED_IN_SALT) ? LOGGED_IN_SALT : 'this-is-a-fallback-salt-but-not-secure';

        if (defined('FLUENT_CART_ENCRYPTION_KEY')) {
            $key = FLUENT_CART_ENCRYPTION_KEY;
        } else {
            $key = (defined('LOGGED_IN_KEY') && '' !== LOGGED_IN_KEY) ? LOGGED_IN_KEY : 'this-is-a-fallback-key-but-not-secure';
        }

        $method = 'aes-256-ctr';
        $ivlen = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($ivlen);

        $raw_value = openssl_encrypt($value . $salt, $method, $key, 0, $iv);
        if (!$raw_value) {
            return false;
        }

        return base64_encode($iv . $raw_value); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    public static function decryptKey($raw_value)
    {

        if (!$raw_value) {
            return $raw_value;
        }

        if (!extension_loaded('openssl')) {
            return $raw_value;
        }

        $raw_value = base64_decode($raw_value, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

        $method = 'aes-256-ctr';
        $ivlen = openssl_cipher_iv_length($method);
        $iv = substr($raw_value, 0, $ivlen);

        $raw_value = substr($raw_value, $ivlen);

        if (defined('FLUENT_CART_ENCRYPTION_KEY')) {
            $key = FLUENT_CART_ENCRYPTION_KEY;
        } else {
            $key = (defined('LOGGED_IN_KEY') && '' !== LOGGED_IN_KEY) ? LOGGED_IN_KEY : 'this-is-a-fallback-key-but-not-secure';
        }

        $salt = (defined('LOGGED_IN_SALT') && '' !== LOGGED_IN_SALT) ? LOGGED_IN_SALT : 'this-is-a-fallback-salt-but-not-secure';

        $value = openssl_decrypt($raw_value, $method, $key, 0, $iv);
        if (!$value || substr($value, -strlen($salt)) !== $salt) {
            return false;
        }

        return substr($value, 0, -strlen($salt));
    }

    /**
     * @return array
     * For kses_post to allow some html tags
     */
    public static function allowedHTMLForCheckout(): array
    {

        $allowedTags = [
            'svg'   => [
                'class'           => true,
                'aria-hidden'     => true,
                'aria-labelledby' => true,
                'role'            => true,
                'xmlns'           => true,
                'width'           => true,
                'height'          => true,
                'viewbox'         => true,
                'fill'            => true
            ],
            'g'     => [
                'fill' => true
            ],
            'title' => ['title' => true],
            'path'  => [
                'd'         => true,
                'fill'      => true,
                'stroke'    => true,
                'fill-rule' => true,
                'clip-rule' => true,
            ],
        ];

        foreach (['input', 'label', 'div', 'span', 'p', 'select', 'option', 'textarea', 'button', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $allowedTags[$tag] = [
                'selected',
                'type'                                                                              => [],
                "selected='selected'"                                                               => [],
                'name'                                                                              => [],
                'value'                                                                             => [],
                'autocomplete'                                                                      => [],
                'placeholder'                                                                       => [],
                'data-required'                                                                     => [],
                'data-type'                                                                         => [],
                'id'                                                                                => [],
                'class'                                                                             => [],
                'required'                                                                          => [],
                'disabled'                                                                          => [],
                'for'                                                                               => [],
                'style'                                                                             => [],
                'checked'                                                                           => [],
                'maxlength'                                                                         => [],
                'data-id'                                                                           => [],
                'data-country'                                                                      => [],
                'data-fluent-cart-checkout-page-form-address-modal-open-button'                     => [],
                'data-fluent-cart-checkout-page-form-address-modal-wrapper'                         => [],
                'data-fluent-cart-checkout-page-form-input-wrapper'                                 => [],
                'data-fluent_cart_checkout_error'                                                   => [],
                'data-fluent-cart-checkout-page-form-address-modal-body'                            => [],
                'data-fluent-cart-checkout-page-form-address-modal-address-selector-button'         => [],
                'data-fluent-cart-checkout-page-form-address-input'                                 => [],
                'data-fluent-cart-checkout-page-form-address-select-wrapper'                        => [],
                'data-fluent-cart-checkout-page-form-address-modal-close-button'                    => [],
                'data-fluent-cart-checkout-page-form-address-modal-address-selector-button-wrapper' => [],
                'data-fluent-cart-checkout-page-form-address-show-add-new-modal-button'             => [],
                'data-fluent-cart-checkout-page-form-address-modal-apply-button'                    => [],
                'data-fluent-cart-checkout-page-form-address-show-add-new-modal-form-wrapper'       => [],
                'data-fluent-cart-checkout-page-form-address-show-add-new-modal-submit-button'      => [],
                'data-fluent-cart-checkout-page-form-address-show-add-new-modal-cancel-button'      => [],
                'data-fluent-cart-address-type'                                                     => [],
                'data-fluent-cart-checkout-page-form-address-info-wrapper'                          => [],
                'data-fluent-cart-checkout-page-form-error'                                         => [],
                'data-fluent-cart-checkout-page-form-section'                                       => [],
                'data-fluent-cart-checkout-page-discount-container'                                 => [],
                'data-fluent-cart-checkout-page-final-amount-container'                             => [],
                'data-fluent-cart-checkout-page-new-total-amount'                                   => [],
                'data-fluent-cart-checkout-page-final-amount'                                       => [],
                'data-fluent-cart-checkout-page-coupon-validate'                                    => [],
                'data-fluent-cart-checkout-coupon-items-toggle'                                     => [],
                'data-fluent-cart-checkout-coupon-items-wrapper'                                    => []


            ];
        }

        return $allowedTags;
    }

    public static function getCouponStatuses()
    {
        return apply_filters('fluent-cart/coupon_statuses', [
            'active'   => __('Active', 'fluent-cart'),
            'expired'  => __('Expired', 'fluent-cart'),
            'disabled' => __('Disabled', 'fluent-cart'),
        ], []);
    }

    public static function getCouponSuccessStatuses()
    {
        return [
            'active'
        ];
    }


    /**
     * Compact subscription terms as text
     *
     * Expected $data keys:
     * - trial_days (int)
     * - interval ('daily'|'weekly'|'monthly'|'yearly')
     * - interval_count (int, default 1)
     * - times (int; 0 = open-ended, >0 = finite number of payments/cycles)
     * - price (string; formatted with currency)
     * - signup_fee (string; 0 if none)
     * - compare_price (string; 0 if none)
     *
     * Output examples:
     * - "30 days free then $100.00 per year"
     * - "$100 per year + $10 one-time signup fee"
     * - "$100/month for 4 months"
     * - "30 days free then $100/month for 4 months"
     * - "$99 per month"
     */
    public static function getSubscriptionTermText(array $data, $asHtml = false): string
    {
        // Fixed interval options
        $intervalOptions = static::getAvailableSubscriptionIntervalMaps();

        // Normalize / defaults
        $trialDays = $data['trial_days'] ?? 0;
        $interval = (string)($data['interval'] ?  $data['interval'] : 'monthly');
        
        $unit = '';
        if (isset($intervalOptions[$interval])) {
            $unit = $intervalOptions[$interval];
        } else if ($interval) {
            $intervalOptions = static::getAvailableSubscriptionIntervalOptions();
            foreach ($intervalOptions as $option) {
                if ($option['value'] === $interval) {
                    $unit = strtolower($option['label']);
                    break;
                }
            }
            
            if (!$unit) {
                $unit = strtolower(str_replace(['_', '-'], ' ', $interval));
            }
        }

        if (!$unit) {
            $unit = 'year';
        }
        
        $count = max(1, (int)($data['interval_count'] ?? 1));
        $times = (int)($data['times'] ?? 0);
        $price = (string)($data['price'] ?? '');
        $signupFee = $data['signup_fee'] ?? '';
        $compare = $data['compare_price'] ?? '';

        $signupFeeLabel = $data['signup_fee_label'] ?? __('signup fee', 'fluent-cart');

        // helpers
        $pluralUnit = static function (string $unit, int $n): string {
            switch ($unit) {
                case 'day':
                    return _n('day', 'days', $n, 'fluent-cart');
                case 'week':
                    return _n('week', 'weeks', $n, 'fluent-cart');
                case 'quarter':
                    return _n('quarter', 'quarters', $n, 'fluent-cart');
                case 'half_year':
                    return _n('half year', 'half years', $n, 'fluent-cart');
                case 'year':
                    return _n('year', 'years', $n, 'fluent-cart');
                case 'month':
                    return _n('month', 'months', $n, 'fluent-cart');
                default:
                    // For custom intervals, add 's' for plural
                    return ($n > 1) ? $unit . 's' : $unit;
            }
        };

        // Build the “per …” phrase (e.g., "per month", "per 3 months")
        $perPhrase = ($count === 1)
            ? sprintf(
            /* translators: %s is the singular unit name (e.g., day, month, year) */
                __('per %s', 'fluent-cart'),
                $pluralUnit($unit, 1)
            )
            : sprintf(
            /* translators: %1$d is the count number, %2$s is the plural unit name (e.g., days, months, years) */
                __('per %1$d %2$s', 'fluent-cart'),
                $count,
                $pluralUnit($unit, $count)
            );

        // Main price phrase:
        // - If monthly installments (times > 0 and unit == month and count == 1): "$X/month for N months"
        // - Else if times > 0: "$X per {count unit(s)} for N {cycle(s)}"
        // - Else: "$X per {count unit(s)}"
        if ($times > 0) {
            if ($unit === 'month' && $count === 1) {
                $installmentTail = sprintf(
                /* translators: %s: pluralized 'month(s)' */
                    __('for %1$d %2$s', 'fluent-cart'),
                    $times,
                    $pluralUnit('month', $times)
                );
                $main = sprintf(
                /* translators: Compact monthly installment, e.g. "$100/month for 4 months" */
                    __('%1$s/%2$s %3$s', 'fluent-cart'),
                    $price,
                    __('month', 'fluent-cart'),
                    $installmentTail
                );
            } else {
                // Generic installment wording: "for N cycle(s)"
                $cyclesTail = sprintf(
                /* translators: %1$d is the number of cycles, %2$s is "cycle" or "cycles" */
                    __('for %1$d %2$s', 'fluent-cart'),
                    $times,
                    _n('cycle', 'cycles', $times, 'fluent-cart')
                );
                $main = sprintf(
                /* translators: e.g. "$100 per 3 months for 4 cycles" */
                    __('%1$s %2$s %3$s', 'fluent-cart'),
                    $price,
                    $perPhrase,
                    $cyclesTail
                );
            }
        } else {
            // Open-ended subscription
            $main = sprintf(
            /* translators: e.g. "$99 per month" */
                __('%1$s %2$s', 'fluent-cart'),
                $price,
                $perPhrase
            );
        }

        // Prefix trial if present: "N days free then …"
        if ($trialDays > 0) {
            $trialFrag = sprintf(
            /* translators: "30 days free then" */
                __('%1$d %2$s free then', 'fluent-cart'),
                $trialDays,
                $pluralUnit('day', $trialDays)
            );
            $main = $trialFrag . ' ' . $main;
        }

        // Append signup fee if any: " + $10 one-time signup fee"
        if ($signupFee) {
            $main .= ' ' . sprintf(
                /* translators: e.g. "+ $10 one-time signup fee" */
                    __('+ %1$s one-time %2$s', 'fluent-cart'),
                    $signupFee,
                    $signupFeeLabel
                );
        }

        // No trailing period (matches your examples)
        return $main;
    }


    public static function generateSubscriptionInfo($otherInfo, $itemPrice): ?string
    {
        // Convert to array only if it's an object
        if (is_object($otherInfo)) {
            $otherInfo = json_decode(json_encode($otherInfo), true);
        }

        $price = self::toDecimal($itemPrice);
        $repeatInterval = Arr::get($otherInfo, 'repeat_interval', '');
        $occurrence = (int)Arr::get($otherInfo, 'times', 0);

        $intervalOptions = static::getAvailableSubscriptionIntervalMaps();

        $intervalUnit = '';
        if (isset($intervalOptions[$repeatInterval])) {
            $intervalUnit = $intervalOptions[$repeatInterval];
        } else if ($repeatInterval) {
                $intervalOptions = static::getAvailableSubscriptionIntervalOptions();
                foreach ($intervalOptions as $option) {
                    if ($option['value'] === $repeatInterval) {
                        $intervalUnit = strtolower($option['label']);
                        break;
                    }
                }
                
                if (!$intervalUnit) {
                    $intervalUnit = ucwords(str_replace(['_', '-'], ' ', $repeatInterval));
                }
        }


        $interval = $intervalUnit
            ? sprintf(
            /* translators: %s is the interval (e.g., day, week, month, quarter, half_year, year) */
                __(' per %s', 'fluent-cart'), $intervalUnit)
            : '';

        $time = $intervalUnit
            ? ($occurrence > 1 ? $intervalUnit . 's' : $intervalUnit)
            : '';

        $paymentInfo = sprintf(
        /* translators: %1$s is the price, %2$s is the interval, %3$s is "until cancel" text, %4$s is the occurrence count, %5$s is the time period */
            __('%1$s %2$s, for %3$s %4$s', 'fluent-cart'),
            $price,
            $interval,
            $occurrence,
            $time
        );

        if (empty($occurrence)) {
            $paymentInfo = sprintf(
            /* translators: %1$s is the price, %2$s is the interval, %3$s is "until cancel" text */
                __('%1$s %2$s %3$s', 'fluent-cart'),
                $price,
                $interval,
                __('until cancel', 'fluent-cart')
            );
        }

        return !empty($otherInfo) ? $paymentInfo : null;
    }

    public static function generateSetupFeeInfo($otherInfo): ?string
    {
        // Convert to array if it's an object
        if (is_object($otherInfo)) {
            $otherInfo = json_decode(json_encode($otherInfo), true);
        }

        $signupFeeName = Arr::get($otherInfo, 'signup_fee_name', __('Setup Fee', 'fluent-cart'));
        $fee = Arr::get($otherInfo, 'signup_fee', 0);
        $manageSetupFee = Arr::get($otherInfo, 'manage_setup_fee', 'no');

        if ($manageSetupFee !== 'yes' || !$fee) {
            return '';
        }


        if ($originalSetupFee = Arr::get($otherInfo, 'original_signup_fee', 0)) {
            if ($fee != $originalSetupFee) {
                return __('Adjusted setup fee', 'fluent-cart') . CurrencySettings::getPriceHtml($fee);
            }
        }


        return $signupFeeName . ' ' . CurrencySettings::getPriceHtml($fee);
    }

    public static function generateTrialInfo($otherInfo)
    {
        $trialInfo = '';

        $trialDays = Arr::get($otherInfo, 'trial_days', 0);

        if ($trialDays && Arr::get($otherInfo, 'is_trial_days_simulated', 'no') !== 'yes') {
            $trialInfo = sprintf(
            /* translators: %d is the number of trial days */
                __('Free Trial: %d days', 'fluent-cart'),
                $trialDays
            );
        }

        return apply_filters('fluent_cart/trial_info', $trialInfo, $otherInfo);
    }


    public static function getCountryList(): array
    {
        $options = App::getInstance('localization')->countriesOptions();
        return apply_filters('fluent-cart/util/countries', $options, []);
    }

    public static function getCountyIsoLists(): array
    {
        return App::getInstance('localization')->getCountyIsoLists();
    }

    public static function getCountryCode($country_name)
    {
        $countries = self::getCountryList();
        foreach ($countries as $country) {
            if ($country['name'] === $country_name) {
                return $country['value'];
            }
        }
        return '';
    }

    /**
     * Get the country's name with country code,
     *
     * @param $code
     * @return string
     */
    public static function getCountryName($code): string
    {
        if (!$code || !is_string($code)) {
            return '';
        }

        $countries = self::getCountyIsoLists();

        return $countries[$code] ?? $code;
    }

    public static function languageCodes(): array
    {
        return $langCodes = [
            'AF' => 'fa-AF',
            'AL' => 'sq-AL',
            'DZ' => 'ar-DZ',
            'AS' => 'sm-AS',
            'AD' => 'ca-AD',
            'AO' => 'pt-AO',
            'AR' => 'es-AR',
            'AM' => 'hy-AM',
            'AU' => 'en-AU',
            'AT' => 'de-AT',
            'AZ' => 'az-AZ',
            'BH' => 'ar-BH',
            'BD' => 'bn-BD',
            'BY' => 'be-BY',
            'BE' => 'nl-BE',
            'BZ' => 'en-BZ',
            'BJ' => 'fr-BJ',
            'BT' => 'dz-BT',
            'BO' => 'es-BO',
            'BA' => 'bs-BA',
            'BW' => 'en-BW',
            'BR' => 'pt-BR',
            'BN' => 'ms-BN',
            'BG' => 'bg-BG',
            'BF' => 'fr-BF',
            'BI' => 'fr-BI',
            'KH' => 'km-KH',
            'CM' => 'en-CM',
            'CA' => 'en-CA',
            'CV' => 'pt-CV',
            'CF' => 'fr-CF',
            'TD' => 'fr-TD',
            'CL' => 'es-CL',
            'CN' => 'zh-CN',
            'CO' => 'es-CO',
            'KM' => 'ar-KM',
            'CD' => 'fr-CD',
            'CG' => 'fr-CG',
            'CR' => 'es-CR',
            'CI' => 'fr-CI',
            'HR' => 'hr-HR',
            'CU' => 'es-CU',
            'CY' => 'el-CY',
            'CZ' => 'cs-CZ',
            'DK' => 'da-DK',
            'DJ' => 'fr-DJ',
            'DM' => 'en-DM',
            'DO' => 'es-DO',
            'EC' => 'es-EC',
            'EG' => 'ar-EG',
            'SV' => 'es-SV',
            'GQ' => 'es-GQ',
            'ER' => 'ti-ER',
            'EE' => 'et-EE',
            'ET' => 'am-ET',
            'FJ' => 'en-FJ',
            'FI' => 'fi-FI',
            'FR' => 'fr-FR',
            'GA' => 'fr-GA',
            'GM' => 'en-GM',
            'GE' => 'ka-GE',
            'DE' => 'de-DE',
            'GH' => 'en-GH',
            'GR' => 'el-GR',
            'GD' => 'en-GD',
            'GT' => 'es-GT',
            'GN' => 'fr-GN',
            'GW' => 'pt-GW',
            'GY' => 'en-GY',
            'HT' => 'fr-HT',
            'HN' => 'es-HN',
            'HU' => 'hu-HU',
            'IS' => 'is-IS',
            'IN' => 'hi-IN',
            'ID' => 'id-ID',
            'IR' => 'fa-IR',
            'IQ' => 'ar-IQ',
            'IE' => 'en-IE',
            'IL' => 'he-IL',
            'IT' => 'it-IT',
            'JM' => 'en-JM',
            'JP' => 'ja-JP',
            'JO' => 'ar-JO',
            'KZ' => 'kk-KZ',
            'KE' => 'sw-KE',
            'KI' => 'en-KI',
            'KR' => 'ko-KR',
            'KW' => 'ar-KW',
            'KG' => 'ky-KG',
            'LA' => 'lo-LA',
            'LV' => 'lv-LV',
            'LB' => 'ar-LB',
            'LS' => 'en-LS',
            'LR' => 'en-LR',
            'LY' => 'ar-LY',
            'LI' => 'de-LI',
            'LT' => 'lt-LT',
            'LU' => 'lb-LU',
            'MG' => 'mg-MG',
            'MW' => 'en-MW',
            'MY' => 'ms-MY',
            'MV' => 'dv-MV',
            'ML' => 'fr-ML',
            'MT' => 'mt-MT',
            'MH' => 'mh-MH',
            'MR' => 'ar-MR',
            'MU' => 'mfe-MU',
            'MX' => 'es-MX',
            'FM' => 'en-FM',
            'MD' => 'ro-MD',
            'MC' => 'fr-MC',
            'MN' => 'mn-MN',
            'ME' => 'sr-ME',
            'MA' => 'ar-MA',
            'MZ' => 'pt-MZ',
            'NA' => 'en-NA',
            'NR' => 'en-NR',
            'NP' => 'ne-NP',
            'NL' => 'nl-NL',
            'NZ' => 'en-NZ',
            'NI' => 'es-NI',
            'NG' => 'en-NG',
            'NO' => 'no-NO',
            'OM' => 'ar-OM',
            'PK' => 'ur-PK',
            'PW' => 'en-PW',
            'PA' => 'es-PA',
            'PG' => 'en-PG',
            'PY' => 'es-PY',
            'PE' => 'es-PE',
            'PH' => 'en-PH',
            'PL' => 'pl-PL',
            'PT' => 'pt-PT',
            'QA' => 'ar-QA',
            'RO' => 'ro-RO',
            'RU' => 'ru-RU',
            'RW' => 'rw-RW',
            'WS' => 'sm-WS',
            'SM' => 'it-SM',
            'SA' => 'ar-SA',
            'SN' => 'fr-SN',
            'RS' => 'sr-RS',
            'SC' => 'fr-SC',
            'SL' => 'en-SL',
            'SG' => 'en-SG',
            'SK' => 'sk-SK',
            'SI' => 'sl-SI',
            'SB' => 'en-SB',
            'SO' => 'so-SO',
            'ZA' => 'en-ZA',
            'ES' => 'es-ES',
            'LK' => 'si-LK',
            'SD' => 'ar-SD',
            'SR' => 'nl-SR',
            'SZ' => 'en-SZ',
            'SE' => 'sv-SE',
            'CH' => 'de-CH',
            'SY' => 'ar-SY',
            'TW' => 'zh-TW',
            'TJ' => 'tg-TJ',
            'TZ' => 'sw-TZ',
            'TH' => 'th-TH',
            'TL' => 'pt-TL',
            'TG' => 'fr-TG',
            'TO' => 'to-TO',
            'TT' => 'en-TT',
            'TN' => 'ar-TN',
            'TR' => 'tr-TR',
            'TM' => 'tk-TM',
            'TV' => 'en-TV',
            'UG' => 'en-UG',
            'UA' => 'uk-UA',
            'AE' => 'ar-AE',
            'GB' => 'en-GB',
            'US' => 'en-US',
            'UY' => 'es-UY',
            'UZ' => 'uz-UZ',
            'VU' => 'bi-VU',
            'VE' => 'es-VE',
            'VN' => 'vi-VN',
            'YE' => 'ar-YE',
            'ZM' => 'en-ZM',
            'ZW' => 'en-ZW'
        ];
    }

    /**
     * Returns a translatable string with a shortcode inserted in the correct format.
     *
     * @param string $shortcode The shortcode to be inserted (e.g., '[fluent_cart_receipt]').
     * @return string The formatted translatable string.
     */
    public static function getShortcodeInstructionString(string $shortcode, $pageName = ''): string
    {
        $copyToClipboard = __('Copy to clipboard', 'fluent-cart');
        return sprintf(
        /* translators: %s: Shortcode */
            '<p>' . _x('Use %1$s shortcode in your page.', 'Shortcode instruction message', 'fluent-cart') . '</p>',
            '<code class="copyable-content" title="' . $copyToClipboard . '">' . ($shortcode) . '</code>',
        //$pageName
        );
    }


    /**
     * Get the current user Model.
     * @return User|\FluentCart\Framework\Database\Orm\Builder|\FluentCart\Framework\Database\Orm\Builder[]|\FluentCart\Framework\Database\Orm\Collection|\FluentCart\Framework\Database\Orm\Model|null
     */
    public static function getCurrentUser()
    {
        static $user = false;

        if ($user !== false) {
            return $user;
        }

        $userId = get_current_user_id();
        if (!$userId) {
            $user = null;
            return $user;
        }

        $user = User::query()->find($userId);

        return $user;

    }

    public static function hasLicense($product): bool
    {
        if (empty($product)) {
            return false;
        }

        $meta = Arr::get($product, 'licensesMeta.meta_value', []);

        if (empty($meta)) {
            return false;
        }

        $meta = is_string($meta) ? json_decode($meta, true) : $meta;

        return Arr::get($meta, 'enabled') === 'yes';

    }


    public static function generateDownloadFileLink($productDownload, $orderId = null, $validityInMinutes = 60, $isAdmin = false): string
    {
        $identifier = Arr::get($productDownload, 'download_identifier', '');

        $validityInMinutes = apply_filters('fluent_cart/download_link_validity_in_minutes', $validityInMinutes, [
            'product_download' => $productDownload,
            'order_id'         => $orderId,
            'is_admin'         => $isAdmin,
        ]);

        $signParams = [
            'download_identifier' => $identifier,
            'valid_till'          => DateTime::now()
                ->addMinutes($validityInMinutes ?? 60)
                ->getTimestamp()
        ];

        if ($orderId) {
            $orderId = Arr::wrap($orderId);
            $signParams['order_id'] = json_encode($orderId);
        }

        $url = (new BaseUrl())->sign(site_url('/'), $signParams);

        return URL::appendQueryParams($url, [
            'fluent-cart' => 'download-by-id',
        ]);

    }


    public static function readableFileSize($bytes): string
    {
        // Converts bytes to a human-readable format (e.g., KB, MB, GB)
        // Example: 1024 -> "1 KB"
        // Example: 1048576 -> "1 MB"

        if (!$bytes && $bytes !== 0) return '';
        $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 2) / 10);
        $size = $bytes / pow(1024, $i);
        return round($size, 2) . ' ' . $units[$i];
    }


    public static function getSitePrefix()
    {
        $siteUrl = rtrim(home_url(), '/');
        // remove http:// or https:// from the URL
        $siteUrl = preg_replace('#^https?://#', '', $siteUrl);
        $sitePrefix = str_replace(['/', '.'], '_', $siteUrl);

        return apply_filters('fluent_cart/site_prefix', $sitePrefix, []);
    }

    public static function humanIntervalMaps($interval = '')
    {
        $intervals = [
            'daily'   => 'day',
            'weekly'  => 'week',
            'monthly' => 'month',
            'yearly'  => 'year'
        ];

        return Arr::get($intervals, $interval);
    }

    /**
     * @return array Array of intervals with label and value
     */
    public static function getAvailableSubscriptionIntervalOptions(): array
    {
        $intervals = [
            [
                'label' => __('Yearly', 'fluent-cart'),
                'value' => 'yearly',
                'map_value' => 'year',
            ],
            [
                'label' => __('Half Yearly', 'fluent-cart'),
                'value' => 'half_yearly',
                'map_value' => 'half_year',
            ],
            [
                'label' => __('Quarterly', 'fluent-cart'),
                'value' => 'quarterly',
                'map_value' => 'quarter',
            ],
            [
                'label' => __('Monthly', 'fluent-cart'),
                'value' => 'monthly',
                'map_value' => 'month',
            ],
            [
                'label' => __('Weekly', 'fluent-cart'),
                'value' => 'weekly',
                'map_value' => 'week',
            ],
            [
                'label' => __('Daily', 'fluent-cart'),
                'value' => 'daily',
                'map_value' => 'day',
            ]
        ];

        return apply_filters('fluent_cart/available_subscription_interval_options', $intervals);
    }

    public static function translateIntervalToStandardFormat($repeatInterval)
    {
        if (empty($repeatInterval)) {
            return 'year';
        }

        $intervalMaps = static::getAvailableSubscriptionIntervalMaps();

        if (!isset($intervalMaps[$repeatInterval])) {
            return 'year';
        }

        return $intervalMaps[$repeatInterval];
    }

    public static function getAvailableSubscriptionIntervalMaps()
    {
        $intervalOptions = static::getAvailableSubscriptionIntervalOptions();

        $intervalMaps = [];
        foreach ($intervalOptions as $option) {
            $intervalMaps[$option['value']] = $option['map_value'];
        }

        return $intervalMaps;
        
    }

    public static function calculateAdjustedTrialDaysForInterval($trialDays, $repeatInterval)
    {
        $intervalInDays = static::subscriptionIntervalInDays($repeatInterval);

        $maxTrialDaysAllowed = apply_filters('fluent_cart/max_trial_days_allowed', 365, [
            'existing_trial_days' => $trialDays,
            'repeat_interval' => $repeatInterval,
            'interval_in_days' => $intervalInDays,
        ]);

        return min($trialDays + $intervalInDays, $maxTrialDaysAllowed); // return the minimum of the sum of the existing trial days and the interval days, and the max trial allowed

    }

    public static function subscriptionIntervalInDays($interval)
    {
        switch ($interval) {
            case 'daily':
                return 1;
            case 'weekly':
                return 7;
            case 'monthly':
                return 30;
            case 'quarterly':
                return 90;
            case 'half_yearly':
                return 182;
            case 'yearly':
                return 365;
            default:
                return apply_filters('fluent_cart/subscription_interval_in_days', 0, [
                    'interval' => $interval,
                ]);
        }
    }

    public static function parseTermIdsForFilter($filters): array
    {
        $taxonomies = Taxonomy::getTaxonomies();
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }
        $formattedFilters = [];

        foreach ($taxonomies as $key => $taxonomy) {
            $terms = Arr::get($filters, $key, []);
            if (!is_array($terms)) {
                $terms = [$terms];
            }

            $terms = array_filter($terms, function ($term) {
                return !empty($term);
            });

            if (!empty($terms)) {
                $terms = array_map(function ($term) {
                    return sanitize_text_field((string)$term);
                }, $terms);


                $formattedFilters[$key] = $terms;
            }


        }

        return $formattedFilters;
    }

    public static function mergeTermIdsForFilter($array1 = [], $array2 = []): array
    {
        $result = [];

        foreach ([$array1, $array2] as $array) {
            foreach ($array as $key => $values) {
                if (!isset($result[$key])) {
                    $result[$key] = [];
                }
                $result[$key] = array_values(array_unique(array_merge($result[$key], $values)));
            }
        }

        return $result;
    }

}
