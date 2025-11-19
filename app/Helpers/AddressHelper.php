<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\FrontendResource\CustomerAddressResource;
use FluentCart\Api\Resource\FrontendResource\OrderAddressResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\Framework\Support\Arr;

class AddressHelper
{
    public static function insertOrderAddresses($orderId, $billingAddress, $shippingAddress)
    {

        if (!empty($billingAddress)) {
            //Don't remove this line,
            //When an order is placed there no input for address label, so keep it empty
            $billingAddress['name'] = '';
            static::createOrderAddress($orderId, $billingAddress);
        }
        if (!empty($shippingAddress)) {
            //Don't remove this line,
            //When an order is placed there no input for address label, so keep it empty
            $shippingAddress['name'] = '';
            static::createOrderAddress($orderId, $shippingAddress);
        }
    }

    public static function createOrderAddress($orderId, array $address)
    {
        //to-do: will refactor this later
        $addressId = Arr::get($address, 'address', null);
        $type = Arr::get($address, 'type', false);

        if ($addressId !== null) {
            $address = CustomerAddressResource::find($addressId);
            $address = Arr::get($address, 'address');
            if ($address) {
                $address = $address->toArray();
                if ($type) {
                    $address['type'] = $type;
                }
            }

        }

        if (empty($address)) {
            return;
        }

        $keysToInclude = ['order_id', 'full_name', 'type', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
        $addressFieldsData = Arr::only($address, $keysToInclude);
        $addressFieldsData['name'] = Arr::get($address, 'full_name', '');

        // get other data from address without an empty value
        $addressOtherData = array_filter(Arr::except($address, $keysToInclude));
        Arr::set($addressFieldsData, 'meta.other_data', $addressOtherData);

        $validationData = Arr::except($addressFieldsData, ['type', 'name']);

        $hasValue = array_filter($validationData); // removes empty/null/false values

        if (!$hasValue) {
            return;
        }

        $addressFieldsData['order_id'] = $orderId;

        if (!empty($addressFieldsData)) {
            OrderAddressResource::create($addressFieldsData);
        }
    }

    public static function getIpAddress($anonymize = false)
    {
        static $ipAddress;

        if ($ipAddress) {
            return $ipAddress;
        }

        if (empty($_SERVER['REMOTE_ADDR'])) {
            // It's a local cli request
            return '127.0.0.1';
        }

        $ipAddress = '';

        $serverData = App::request()->server();
        $HTTP_CF_CONNECTING_IP = Arr::get($serverData, 'HTTP_CF_CONNECTING_IP');
        $RemoteAddr = Arr::get($serverData, 'REMOTE_ADDR');
        $clientIp = Arr::get($serverData, 'HTTP_CLIENT_IP');
        $HTTP_X_FORWARDED_FOR = Arr::get($serverData, 'HTTP_X_FORWARDED_FOR');
        if ($HTTP_CF_CONNECTING_IP) {
            //If it's a valid Cloudflare request

            if (self::isCfIp($RemoteAddr)) {
                //Use the CF-Connecting-IP header.
                $ipAddress = $HTTP_CF_CONNECTING_IP;
            } else {
                //If it isn't valid, then use REMOTE_ADDR.
                $ipAddress = $RemoteAddr;
            }
        } else if ($RemoteAddr == '127.0.0.1') {
            // most probably it's local reverse proxy
            if ($clientIp) {
                $ipAddress = $clientIp;
            } else if ($HTTP_X_FORWARDED_FOR) {
                $ipAddress = (string)rest_is_ip_address(trim(current(preg_split('/,/', sanitize_text_field($HTTP_X_FORWARDED_FOR)))));
            }
        }

        if (!$ipAddress) {
            $ipAddress = $RemoteAddr;
        }

        $ipAddress = preg_replace('/^(\d+\.\d+\.\d+\.\d+):\d+$/', '\1', $ipAddress);

        $ipAddress = apply_filters('fluent_auth/user_ip', $ipAddress, []);

        if ($anonymize) {
            return wp_privacy_anonymize_ip($ipAddress);
        }

        $ipAddress = sanitize_text_field(wp_unslash($ipAddress));

        return $ipAddress;
    }

    private static function isCfIp($ip = '')
    {
        $serverData = App::request()->server();
        $REMOTE_ADDR = Arr::get($serverData, 'REMOTE_ADDR');
        if (!$ip) {
            $ip = $REMOTE_ADDR;
        }
        $cloudflareIPRanges = array(
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
        );
        $validCFRequest = false;
        //Make sure that the request came via Cloudflare.
        foreach ($cloudflareIPRanges as $range) {
            //Use the ip_in_range function from Joomla.
            if (self::ipInRange($ip, $range)) {
                //IP is valid. Belongs to Cloudflare.
                return true;
            }
        }

        return false;
    }

    private static function ipInRange($ip, $range)
    {
        if (strpos($range, '/') !== false) {
            // $range is in IP/NETMASK format
            list($range, $netmask) = explode('/', $range, 2);
            if (strpos($netmask, '.') !== false) {
                // $netmask is a 255.255.0.0 format
                $netmask = str_replace('*', '0', $netmask);
                $netmask_dec = ip2long($netmask);
                return ((ip2long($ip) & $netmask_dec) == (ip2long($range) & $netmask_dec));
            } else {
                // $netmask is a CIDR size block
                // fix the range argument
                $x = explode('.', $range);
                while (count($x) < 4) $x[] = '0';
                list($a, $b, $c, $d) = $x;
                $range = sprintf("%u.%u.%u.%u", empty($a) ? '0' : $a, empty($b) ? '0' : $b, empty($c) ? '0' : $c, empty($d) ? '0' : $d);
                $range_dec = ip2long($range);
                $ip_dec = ip2long($ip);

                # Strategy 1 - Create the netmask with 'netmask' 1s and then fill it to 32 with 0s
                #$netmask_dec = bindec(str_pad('', $netmask, '1') . str_pad('', 32-$netmask, '0'));

                # Strategy 2 - Use math to create it
                $wildcard_dec = pow(2, (32 - $netmask)) - 1;
                $netmask_dec = ~$wildcard_dec;

                return (($ip_dec & $netmask_dec) == ($range_dec & $netmask_dec));
            }
        } else {
            // range might be 255.255.*.* or 1.2.3.0-1.2.3.255
            if (strpos($range, '*') !== false) { // a.b.*.* format
                // Just convert to A-B format by setting * to 0 for A and 255 for B
                $lower = str_replace('*', '0', $range);
                $upper = str_replace('*', '255', $range);
                $range = "$lower-$upper";
            }

            if (strpos($range, '-') !== false) { // A-B format
                list($lower, $upper) = explode('-', $range, 2);
                $lower_dec = (float)sprintf("%u", ip2long($lower));
                $upper_dec = (float)sprintf("%u", ip2long($upper));
                $ip_dec = (float)sprintf("%u", ip2long($ip));
                return (($ip_dec >= $lower_dec) && ($ip_dec <= $upper_dec));
            }
            return false;
        }
    }

    public static function getStateNameByCode($stateCode, $countryCode = null)
    {
        return App::localization()->getStateNameByCode($stateCode, $countryCode);
    }

    public static function getCountryNameByCode($countryCode)
    {
        return App::localization()->getCountryNameByCode($countryCode);
    }

    public static function getUserAgent($sanitize = true)
    {
        static $userAgent;

        if ($userAgent !== null) {
            return $userAgent;
        }
        $serverData = App::request()->server();
        $userAgentServer = Arr::get($serverData, 'HTTP_USER_AGENT');

        // Return empty string for CLI requests or missing user agent
        if (empty($userAgentServer)) {
            $userAgent = '';
            return $userAgent;
        }

        $userAgent = $userAgentServer;

        // Apply WordPress filter to allow customization
        $userAgent = apply_filters('fluent_auth/user_agent', $userAgent, []);

        // Sanitize the user agent if requested
        if ($sanitize) {
            $userAgent = sanitize_text_field($userAgent);
        }

        // Return empty string if user agent is invalid after sanitization
        if (empty($userAgent) || strlen($userAgent) > 1000) {
            $userAgent = '';
        }

        return $userAgent;
    }

    /**
     * Guess first name and last name from the full name.
     *
     * @param string $fullName
     * @return array
     */
    public static function guessFirstNameAndLastName($fullName): array
    {
        $fullName = trim($fullName);
        $parts = explode(' ', $fullName);
        if (count($parts) == 1) {
            return [
                'first_name' => trim($fullName),
                'last_name'  => ''
            ];
        }
        $lastName = array_pop($parts);
        $firstName = implode(' ', $parts);

        return [
            'first_name' => trim($firstName),
            'last_name'  => trim($lastName)
        ];
    }

    public static function getAvailableShippingMethodLists($data): array
    {
        $state = Arr::get($data, 'state', '');
        $countryCode = Arr::get($data, 'country_code', '');

        if (!$countryCode) {
            $timezone = Arr::get($data, 'timezone', '');
            if ($timezone) {
                $countryCode = LocalizationManager::guessCountryFromTimezone($timezone);
            }
        }

        if (!$countryCode) {
            return [
                'status'     => false,
                'error_type' => 'no_country',
                'message'    => __('Please provide your address to view shipping options', 'fluent-cart')
            ];
        }

        $availableShippingMethods = ShippingMethod::applicableToCountry($countryCode, $state)->get();

        if (!$availableShippingMethods || $availableShippingMethods->isEmpty()) {
            $settingView = '<div class="fct-empty-state">'
                . esc_html__('No shipping methods available for this address.', 'fluent-cart');

            if (current_user_can('manage_options')) {
                $settingsPageUrl = admin_url('admin.php?page=fluent-cart#/settings/shipping');

                $settingsLink = '<a href="' . esc_url($settingsPageUrl ?? '') . '" target="_blank">' . esc_html__('Activate from settings.', 'fluent-cart') . '</a>';

                $settingView .= ' ' . $settingsLink;
            }

            $settingView .= '</div>';

            return [
                'status'       => false,
                'country_code' => $countryCode,
                'view'         => $settingView
            ];
        }

        return [
            'available_shipping_methods' => $availableShippingMethods,
            'country_code'               => $countryCode
        ];
    }

    public static function getShippingMethods($country, $state = null, $timezone = null)
    {
        if (!$country && $timezone) {
            $country = LocalizationManager::guessCountryFromTimezone($timezone);
        }

        if (!$country) {
            return new \WP_Error('no_country', __('Please provide your shipping address to get shipping options', 'fluent-cart'));
        }

        $shippingMethods = ShippingMethod::applicableToCountry($country, $state)
            ->orderBy('amount', 'DESC')
            ->get();

        // let's filter the shipping methods
        $formattedMethods = [];

        $requireState = false;

        foreach ($shippingMethods as $shippingMethod) {
            if (!$shippingMethod->states) {
                $formattedMethods[] = $shippingMethod;
                continue;
            }

            // now we have states for this shipping method
            if (!$state) {
                $requireState = true;
                continue;
            }

            if (in_array($state, $shippingMethod->states)) {
                $formattedMethods[] = $shippingMethod;
            }
        }

        if (!$formattedMethods && $requireState) {
            return new \WP_Error('require_state', __('Enter your shipping address to view available shipping methods. Billing and shipping address is the same by default unless you ship to a different address.', 'fluent-cart'));
        }

        if (!$formattedMethods) {
            return new \WP_Error('no_shipping_methods', __('No shipping options available for the provided address', 'fluent-cart'));
        }

        return $formattedMethods;
    }

    public static function getCustomerValidatedAddresses($config, $customer): array
    {
        $type = Arr::get($config, 'type', 'billing'); // billing or shipping

        $addressValidations = array_filter(CheckoutFieldsSchema::getCheckoutFieldsRequirements($type, 'physical'));
        $storeCountry = (new StoreSettings())->get('store_country');

        $requirementsFields = CheckoutFieldsSchema::getCheckoutFieldsRequirements(
            $type,
            Arr::get($config, 'product_type'),
            Arr::get($config, 'with_shipping')
        );
        if ($type === 'billing') {
            unset($requirementsFields['full_name']);
            unset($requirementsFields['company_name']);
        }

        $addresses = CustomerAddressResource::get([
            'type'        => $type,
            'customer_id' => $customer->id,
            'status'      => 'active'
        ]);

        $allowedAddresses = [];

        foreach ($addresses as $address) {
            $isValid = true;

            // If country is not required in checkout fields, only allow addresses matching store country
            if (!isset($addressValidations['country']) && $storeCountry) {
                $addressCountry = Arr::get($address, 'country');
                if ($addressCountry !== $storeCountry) {
                    continue; // Skip this address
                }
            }

            foreach ($requirementsFields as $key => $requirement) {

                if ($key === 'state') {
                    $country = Arr::get($address, 'country');
                    if ($country) {
                        $states = LocalizationManager::getInstance()->statesOptions($country);
                        if (!empty($states) && !in_array(Arr::get($address, 'state'), array_column($states, 'value'))) {
                            $isValid = false;
                            break;
                        }
                    }
                    //continue;
                } else if ($requirement === 'required' && empty(Arr::get($address, $key))) {
                    $isValid = false;
                    break;
                }
            }

            if ($isValid) {
                $id = Arr::get($address, 'id');
                if ($id) {
                    $allowedAddresses[$id] = $address;
                }
            }
        }

        return $allowedAddresses;
    }

    public static function getPrimaryAddress(array $addresses, array $config, $customer, $type = 'billing')
    {
        $primaryAddress = Arr::first($addresses);
        $primaryAddressId = '';
        if ($type === 'billing' && $customer->primary_billing_address) {
            $primaryAddressId = $customer->primary_billing_address->id;
            if (!empty(Arr::get($config, 'billing_address_id', ''))) {
                $primaryAddressId = Arr::get($config, 'billing_address_id');
            }
        } else if ($customer->primary_shipping_address) {
            $primaryAddressId = $customer->primary_shipping_address->id;
            if (!empty(Arr::get($config, 'shipping_address_id', ''))) {
                $primaryAddressId = Arr::get($config, 'shipping_address_id');
            }
        }

        if (Arr::has($addresses, $primaryAddressId)) {
            $primaryAddress = $addresses[$primaryAddressId];
        }

        return $primaryAddress;
    }


    public static function maybePushAddressDataForCheckout($data, $type = 'billing')
    {
        if (!empty($data[$type . '_address_id'])) {
            $currentCustomer = CustomerResource::getCurrentCustomer();
            if (!$currentCustomer) {
                return $data;
            }

            $addressModel = CustomerAddresses::find($data[$type . '_address_id']);
            if ($addressModel && $addressModel->customer_id == $currentCustomer->id) {
                $addressData = $addressModel->getFormattedDataForCheckout($type . '_');

                //  dd($addressData, $addressModel->id);

                foreach ($addressData as $key => $value) {
                    $data[$key] = $value;
                }
            }
        }

        return $data;
    }

    public static function mergeBillingWithShipping($data)
    {
        $keys = [
            'full_name', 'address_1', 'address_2', 'city', 'state', 'phone', 'postcode', 'country'
        ];
        foreach ($keys as $key) {
            $data['shipping_' . $key] = Arr::get($data, 'billing_' . $key, '');
        }

        return $data;
    }


    public static function getDefaultBillingCountryForCheckout()
    {
        // get from cloudflare header
        $countryCode = '';
        if (isset($_SERVER["HTTP_CF_IPCOUNTRY"])) {
            $countryCode = sanitize_text_field(wp_unslash($_SERVER["HTTP_CF_IPCOUNTRY"]));
        }

        return apply_filters('fluent_cart/default_billing_country_for_checkout', $countryCode);
    }

}
