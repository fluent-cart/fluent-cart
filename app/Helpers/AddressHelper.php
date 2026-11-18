<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\FrontendResource\CustomerAddressResource;
use FluentCart\Api\Resource\FrontendResource\OrderAddressResource;
use FluentCart\Api\Resource\FrontendResource\OrderResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\PriceConverter;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrier;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrierSettings;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\Framework\Support\Arr;

class AddressHelper
{
    /**
     * Live carrier rates per carrier + destination + cart + quote, for this request.
     *
     * @var array<string, array[]>
     */
    protected static $carrierRateCache = [];

    protected static $reuseCarrierQuotes = false;

    /** @var array<string, array[]> Pending rate sources per carrier rate-cache key, for this request. */
    protected static $carrierPendingCache = [];

    /** @var array|null [carrier slug, source id] the next rate list may quote live. */
    protected static $fetchRateSource = null;

    /** @var array The last rate list's ['key' => list key, 'pending' => [[carrier, source, label]]]. */
    protected static $rateSourceState = ['key' => '', 'pending' => []];

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
            $customerId = Order::query()->where('id', $orderId)->value('customer_id');
            $savedAddress = $customerId ? CustomerAddresses::query()
                ->where('id', $addressId)
                ->where('customer_id', $customerId)
                ->first() : null;

            if ($savedAddress) {
                $address = $savedAddress->toArray();
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
            if (Arr::get($addressFieldsData, 'order_id') !== null) {
                $alreadyOrderHasAddress = OrderAddressResource::find(
                    null,
                    [
                        'order_id' => $orderId,
                        'type' => $type
                    ]
                );

                if ($alreadyOrderHasAddress) {
                    OrderAddressResource::update($addressFieldsData, $alreadyOrderHasAddress->id);
                } else {
                    OrderAddressResource::create($addressFieldsData);
                }
            }
        }
    }

    /**
     * Copy an existing OrderAddress's meta (company/VAT/legal-reg id/label) onto the
     * order-address row of the same type already created for $orderId. insertOrderAddresses()
     * only accepts flat column data, so a parent-order snapshot copy (renewals) must
     * carry meta over separately.
     */
    public static function copyOrderAddressMeta($orderId, $type, $sourceAddress): void
    {
        if (!$sourceAddress || empty($sourceAddress->meta)) {
            return;
        }

        OrderAddress::query()
            ->where('order_id', $orderId)
            ->where('type', $type)
            ->update(['meta' => json_encode($sourceAddress->meta)]);
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
                $ipAddress = (string) rest_is_ip_address(trim(current(preg_split('/,/', sanitize_text_field($HTTP_X_FORWARDED_FOR)))));
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
                while (count($x) < 4)
                    $x[] = '0';
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
                $lower_dec = (float) sprintf("%u", ip2long($lower));
                $upper_dec = (float) sprintf("%u", ip2long($upper));
                $ip_dec = (float) sprintf("%u", ip2long($ip));
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
                'last_name' => ''
            ];
        }
        $lastName = array_pop($parts);
        $firstName = implode(' ', $parts);

        return [
            'first_name' => trim($firstName),
            'last_name' => trim($lastName)
        ];
    }

    /**
     * Whether the zone-based ("manual") shipping methods may be offered.
     *
     * These rows belong to the Manual carrier, and its "Enable Manual Shipping"
     * toggle is what withholds them from checkout. Absent or unregistered carrier
     * means enabled — a store with no carrier framework booted must keep shipping.
     */
    public static function manualZonesEnabled(): bool
    {
        $manualCarrier = CarrierManager::carrier(Shipment::CARRIER_MANUAL);

        return !$manualCarrier || $manualCarrier->isEnabled();
    }

    /**
     * Form keys of the contact fields a live carrier quotes with ('name', 'address_1',
     * 'phone'), only those the checkout shows; [] when no live carrier is enabled.
     * Checkout JS gets the same lists (shipping_quote_fields), so its hold and this re-quote agree.
     *
     * @return array<string, string[]>
     */
    public static function quoteContactFields(bool $shipToDifferent): array
    {
        $liveCarriers = array_diff_key(CarrierManager::getInstance()->enabled(), [Shipment::CARRIER_MANUAL => true]);
        if (!$liveCarriers) {
            return [];
        }

        $prefix = $shipToDifferent ? 'shipping_' : 'billing_';
        $settings = CheckoutFieldsSchema::getFieldsSettings();

        if (CheckoutFieldsSchema::isFullNameRequired()) {
            // The shipping form always shows its full name; billing's is a basic-info field.
            $showsName = $shipToDifferent || Arr::get($settings, 'basic_info.full_name.enabled') === 'yes';
            $name = $showsName ? [$prefix . 'full_name'] : [];
        } else {
            // Split names exist only in basic info, so they name the parcel either way.
            $name = array_keys(array_filter([
                'billing_first_name' => CheckoutFieldsSchema::isFirstNameEnabled(),
                'billing_last_name'  => CheckoutFieldsSchema::isLastNameEnabled(),
            ]));
        }

        $fields = ['name' => $name];
        foreach (['address_1', 'phone'] as $key) {
            if (Arr::get($settings, $prefix . 'address.' . $key . '.enabled') === 'yes') {
                $fields[$key] = [$prefix . $key];
            }
        }

        return array_filter($fields);
    }

    /**
     * Whether an enabled carrier builds its own rate request, so any checkout
     * form field may change its quote (ProvidesLiveRates::liveRatesRequest()).
     */
    public static function carriersBuildRequests(): bool
    {
        foreach (CarrierManager::getInstance()->enabled() as $carrier) {
            if (method_exists($carrier, 'buildsLiveRatesRequests') && $carrier->buildsLiveRatesRequests()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The checkout fields a live carrier may quote from, each with where the cart
     * stores it. Checkout saves exactly these, plus the shipping and payment method.
     *
     * @return array<string, string> field name => checkout_data path
     */
    public static function quoteFormFields(): array
    {
        $fields = [
            'ship_to_different'             => 'form_data.ship_to_different',
            'billing_email'                 => 'form_data.billing_email',
            'billing_first_name'            => 'form_data.billing_first_name',
            'billing_last_name'             => 'form_data.billing_last_name',
            'billing_address_id'            => 'form_data.billing_address_id',
            'shipping_address_id'           => 'form_data.shipping_address_id',
            'billing_company'               => 'form_data.billing_company',
            'order_notes'                   => 'form_data.order_notes',
            'billing_company_name'          => 'form_data.billing_company_name',
            'billing_legal_registration_id' => 'form_data.billing_legal_registration_id',
            'is_business'                   => 'form_data.is_business',
            'fct_billing_tax_id'            => 'tax_data.vat_number',
        ];

        foreach (['full_name', 'country', 'address_1', 'address_2', 'state', 'city', 'postcode', 'company_name', 'phone'] as $addressField) {
            $fields['billing_' . $addressField] = 'form_data.billing_' . $addressField;
            $fields['shipping_' . $addressField] = 'form_data.shipping_' . $addressField;
        }

        return $fields;
    }

    /**
     * quoteFormFields() read from a cart's checkout_data, by field name.
     */
    public static function checkoutFormFields(array $checkoutData): array
    {
        $values = [];
        foreach (static::quoteFormFields() as $field => $path) {
            $value = Arr::get($checkoutData, $path);
            if ($value !== null) {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    /**
     * The checkout form for live carriers (CarrierContract::getRates 'form_data'):
     * the quoteFormFields() that are scalar, as plain text, at most 255 characters.
     * In quoteFormFields() order, so display and Place Order build the same request.
     */
    public static function quoteFormData(array $formData): array
    {
        $clean = [];
        foreach (array_keys(static::quoteFormFields()) as $key) {
            if (isset($formData[$key]) && is_scalar($formData[$key])) {
                $clean[$key] = mb_substr(sanitize_text_field((string) $formData[$key]), 0, 255);
            }
        }

        return $clean;
    }

    /**
     * The contact a live carrier quote uses, read from checkout form data. All ''
     * until every field quoteContactFields() lists is filled, so a half-typed
     * contact never makes a new quote.
     *
     * @return array{name: string, address_1: string, phone: string}
     */
    public static function quoteContact(array $formData): array
    {
        $empty = ['name' => '', 'address_1' => '', 'phone' => ''];
        $contact = $empty;

        foreach (static::quoteContactFields(Arr::get($formData, 'ship_to_different') === 'yes') as $key => $formKeys) {
            $values = [];
            foreach ($formKeys as $formKey) {
                $value = Arr::get($formData, $formKey, '');
                $value = is_scalar($value) ? trim((string) $value) : '';
                if ($value === '') {
                    return $empty;
                }
                $values[] = $value;
            }
            $contact[$key] = implode(' ', $values);
        }

        return $contact;
    }

    /**
     * Every enabled carrier's rates for a destination, as one list of rate arrays
     * (see CarrierContract::getRates). Each rate is converted once, here, into the
     * checkout currency, so rendering, validation, the charge and the tax/shipping
     * modules all read the same `cost`. A carrier that declares canQuoteInCurrency()
     * shows only the currencies carrierCurrencyPlan() accepts.
     *
     * @param string      $country
     * @param string|null $state
     * @param array|null  $items       Cart line items; null uses the current cart's.
     * @param array       $destination ['postcode' => , 'city' => , 'name' => , 'address_1' => , 'phone' => ]
     *                                 (contact from quoteContact()); empty uses the current cart's address.
     * @param bool        $zonesOnly   Skip live carriers; zone (manual) methods only.
     * @return array[]
     */
    protected static function collectCarrierRates($country, $state = null, $items = null, array $destination = [], bool $zonesOnly = false): array
    {
        if (!$country) {
            return [];
        }

        $cart = null;
        if ($items === null || !$destination) {
            $cart = CartHelper::getCart();
        }

        // Live carriers must quote the real parcel on every path, not an empty one.
        if ($items === null) {
            $items = $cart ? (array) $cart->cart_data : [];
        }

        if (!$destination && $cart) {
            $cartAddress = (array) $cart->getShippingAddress();
            if (strcasecmp((string) Arr::get($cartAddress, 'country', ''), (string) $country) === 0) {
                $formData = (array) Arr::get((array) $cart->checkout_data, 'form_data', []);
                $destination = array_merge($cartAddress, static::quoteContact($formData), ['form_data' => static::checkoutFormFields((array) $cart->checkout_data)]);
            }
        }

        $args = [
            'country'   => $country,
            'state'     => $state,
            'postcode'  => (string) Arr::get($destination, 'postcode', ''),
            'city'      => (string) Arr::get($destination, 'city', ''),
            'name'      => (string) Arr::get($destination, 'name', ''),
            'address_1' => (string) Arr::get($destination, 'address_1', ''),
            'phone'     => (string) Arr::get($destination, 'phone', ''),
            'items'     => is_array($items) ? $items : [],
        ];

        $quote = CartCurrency::currentQuote();
        $baseCurrency = BaseCurrency::get();
        $quoteCurrency = strtoupper((string) Arr::get($quote, 'currency', '')) ?: $baseCurrency;
        $args['currency'] = $quoteCurrency;
        $args['base_currency'] = $baseCurrency;
        // Key on what a quote depends on: a line's shipping and tax shares change with
        // the chosen method, and must not make the same address a new rate list.
        $parcel = array_map(function ($item) {
            return Arr::only((array) $item, ['object_id', 'post_id', 'quantity', 'fulfillment_type', 'other_info', 'unit_price', 'subtotal']);
        }, $args['items']);
        $requestKey = md5(serialize([array_merge($args, ['items' => $parcel]), CartCurrency::revisionOfQuote($quote)]));
        // After $requestKey: checkout's rates_key must not change on every keystroke.
        $args['form_data'] = static::quoteFormData((array) Arr::get($destination, 'form_data', []));
        // Read-only context: items and destination are what is priced, and the
        // cache follows the carrier's request, so the cart never keys a quote.
        $args['cart'] = $cart ?: CartHelper::getCart();
        $formKey = md5(serialize($args['form_data']));
        if (static::$reuseCarrierQuotes) {
            $args['reuse_quote'] = true;
        }
        // A carrier that splits its quote into sources answers from caches only;
        // checkout fetches each pending source through fetchRateSource().
        $args['defer_sources'] = true;
        $fetch = static::$fetchRateSource;

        $manager = CarrierManager::getInstance();
        $carriers = $manager->enabled();
        if (!$manager->get(Shipment::CARRIER_MANUAL)) {
            // Carrier framework not booted: zone methods must keep shipping.
            $carriers = [Shipment::CARRIER_MANUAL => new ManualCarrier(new ManualCarrierSettings())] + $carriers;
        }

        $rates = [];
        $liveRates = [];
        $pending = [];
        foreach ($carriers as $slug => $carrier) {
            if ($zonesOnly && $slug !== Shipment::CARRIER_MANUAL) {
                continue;
            }
            $carrierArgs = $args;
            if ($fetch && $fetch[0] === (string) $slug) {
                $carrierArgs['fetch_source'] = $fetch[1];
            }
            $accepted = null;
            if ($slug !== Shipment::CARRIER_MANUAL) {
                $plan = static::carrierCurrencyPlan($carrier, (string) $slug, $quoteCurrency, $baseCurrency);
                if (!$plan) {
                    continue;
                }
                $carrierArgs['currency'] = $plan['currency'];
                $accepted = $plan['accepted'];
            }

            // Zone rates are read fresh. Live rates are held for the request so the
            // rate validated is the rate charged, even if the carrier answers differently.
            $cacheKey = $slug . '|' . $carrierArgs['currency'] . '|' . $requestKey . '|' . $formKey . '|' . (isset($carrierArgs['fetch_source']) ? $carrierArgs['fetch_source'] : '');
            if ($slug !== Shipment::CARRIER_MANUAL && array_key_exists($cacheKey, static::$carrierRateCache)) {
                $liveRates = array_merge($liveRates, static::$carrierRateCache[$cacheKey]);
                $pending = array_merge($pending, static::$carrierPendingCache[$cacheKey]);
                continue;
            }

            try {
                $quoted = (array) $carrier->getRates($carrierArgs);
            } catch (\Throwable $e) {
                if ($slug === Shipment::CARRIER_MANUAL) {
                    throw $e;
                }
                // One failing live carrier must not take checkout down with it.
                $quoted = [];
            }

            $carrierRates = [];
            foreach ($quoted as $rate) {
                // A numeric id is a zone method's; another carrier using one would collide.
                if (!is_array($rate) || ($slug !== Shipment::CARRIER_MANUAL && is_numeric(Arr::get($rate, 'id')))) {
                    continue;
                }
                $rate['carrier'] = $slug;
                if ($accepted !== null && !in_array(strtoupper(trim((string) Arr::get($rate, 'currency', ''))), $accepted, true)) {
                    continue;
                }
                $converted = static::convertRateToQuote($rate, $quote);
                if ($converted) {
                    $carrierRates[] = $converted;
                }
            }

            if ($slug === Shipment::CARRIER_MANUAL) {
                $rates = array_merge($rates, $carrierRates);
                continue;
            }

            $carrierPending = [];
            $sources = method_exists($carrier, 'getPendingRateSources') ? (array) $carrier->getPendingRateSources() : [];
            foreach ($sources as $source => $label) {
                $carrierPending[] = ['carrier' => (string) $slug, 'source' => (string) $source, 'label' => (string) $label];
            }

            static::$carrierRateCache[$cacheKey] = $carrierRates;
            static::$carrierPendingCache[$cacheKey] = $carrierPending;
            $liveRates = array_merge($liveRates, $carrierRates);
            $pending = array_merge($pending, $carrierPending);
        }

        // A carrier that builds its own request may quote from any form field, so its
        // list changes with the form; other stores keep one key per address.
        static::$rateSourceState = ['key' => md5($requestKey . (static::carriersBuildRequests() ? '|' . $formKey : '')), 'pending' => $pending];

        // Zone methods keep the merchant's order; live rates follow, cheapest first.
        $rates = array_merge($rates, static::sortRatesByCost($liveRates));

        // The filter sees only rates this state can use. State-limited ones pass
        // through untouched, so getShippingMethods() can still ask for a state.
        $byKey = [];
        $stateLimited = [];
        foreach ($rates as $rate) {
            $states = (array) Arr::get($rate, 'states', []);
            if ($states && !($state && in_array($state, $states))) {
                $stateLimited[] = $rate;
                continue;
            }
            $byKey[$rate['carrier'] . '|' . $rate['id']] = $rate;
        }
        $rates = array_values($byKey);

        /**
         * Hide or reorder the shipping rates offered for this cart. Checkout and
         * Place Order read the same list, so a hidden rate can't be picked.
         *
         * @since 2.4.0
         *
         * @param array[] $rates   Rate arrays (see CarrierContract::getRates), cost in cents.
         * @param array   $context ['items' => cart lines, 'destination' => address args,
         *                         'currency' => checkout ISO]
         */
        $filtered = apply_filters('fluent_cart/shipping/available_rates', $rates, [
            'items'       => $args['items'],
            'destination' => Arr::except($args, ['items', 'currency', 'base_currency', 'reuse_quote', 'defer_sources', 'form_data', 'cart']),
            'currency'    => $quoteCurrency,
        ]);

        if (!is_array($filtered)) {
            return array_merge($rates, $stateLimited);
        }

        // Only rates core quoted, as quoted: a filter can't add one or change a price.
        $kept = [];
        foreach ($filtered as $rate) {
            $key = is_array($rate) ? Arr::get($rate, 'carrier') . '|' . Arr::get($rate, 'id') : '';
            if (isset($byKey[$key])) {
                $kept[$key] = $byKey[$key];
            }
        }

        return array_merge(array_values($kept), $stateLimited);
    }

    /**
     * The last rate list's key and the carrier rate sources it left pending.
     *
     * @return array ['key' => string, 'pending' => [['carrier' =>, 'source' =>, 'label' =>], ...],
     *                'ids' => string[]|null after fetchRateSource(): the whole list's rate ids]
     */
    public static function rateSourceState(): array
    {
        return static::$rateSourceState;
    }

    /**
     * Quotes one pending rate source live and returns its rates from the full list
     * for this address. Only an enabled carrier's own declared source is ever called.
     *
     * @param array|null $items       Cart line items; null uses the current cart's.
     * @param array      $destination ['postcode' => , 'city' => ]; empty uses the current cart's address.
     * @return array[]
     */
    public static function fetchRateSource(string $carrier, string $source, $country, $state = null, $items = null, array $destination = []): array
    {
        static::$fetchRateSource = [$carrier, $source];
        try {
            $rates = static::getShippingMethods($country, $state, null, $items, $destination);
        } finally {
            static::$fetchRateSource = null;
        }

        // The whole list's ids, so checkout can drop shown rows the
        // available_rates filter hides now that this source has quoted.
        static::$rateSourceState['ids'] = is_wp_error($rates) ? null : array_map('strval', array_column($rates, 'id'));

        if (is_wp_error($rates)) {
            return [];
        }

        return array_values(array_filter($rates, function ($rate) use ($carrier, $source) {
            return Arr::get($rate, 'carrier') === $carrier && (string) Arr::get($rate, 'rate_source', '') === $source;
        }));
    }

    /**
     * Cheapest first. Equal costs keep their quoted order: usort is not stable before PHP 8.
     */
    protected static function sortRatesByCost(array $rates): array
    {
        $positions = array_keys($rates);
        $costs = array_map(function ($rate) {
            return (int) $rate['cost'];
        }, $rates);

        array_multisort($costs, SORT_ASC, SORT_NUMERIC, $positions, SORT_ASC, SORT_NUMERIC);

        return array_map(function ($position) use ($rates) {
            return $rates[$position];
        }, $positions);
    }

    /**
     * The currency to ask a carrier in and the rate currencies it may show. A
     * quote in the checkout currency always shows; its base-currency rate shows,
     * converted at the store's rate, only when the conversion filter allows it.
     * Null skips the carrier: it could show nothing. 'accepted' null means the
     * carrier did not declare canQuoteInCurrency(), so every rate converts as before.
     *
     * @return array|null ['currency' => ISO, 'accepted' => string[]|null]
     */
    protected static function carrierCurrencyPlan($carrier, string $slug, string $checkoutCurrency, string $baseCurrency): ?array
    {
        $quotesCheckout = method_exists($carrier, 'canQuoteInCurrency') ? $carrier->canQuoteInCurrency($checkoutCurrency) : null;
        if ($quotesCheckout === null) {
            return ['currency' => $checkoutCurrency, 'accepted' => null];
        }

        $accepted = $quotesCheckout ? [$checkoutCurrency] : [];

        if ($checkoutCurrency !== $baseCurrency && $carrier->canQuoteInCurrency($baseCurrency)) {
            /**
             * Show a carrier's base-currency rate, converted at the store's exchange
             * rate, at a checkout in a currency the carrier does not quote in.
             *
             * @param bool  $allow   Default false: such rates are hidden.
             * @param array $context ['carrier' => slug, 'checkout_currency' => ISO, 'base_currency' => ISO]
             */
            $allow = apply_filters('fluent_cart/shipping/carrier_rates_allow_conversion', false, [
                'carrier'           => $slug,
                'checkout_currency' => $checkoutCurrency,
                'base_currency'     => $baseCurrency,
            ]);

            if ($allow) {
                $accepted[] = $baseCurrency;
            }
        }

        if (!$accepted) {
            return null;
        }

        return ['currency' => $accepted[0], 'accepted' => $accepted];
    }

    /**
     * A carrier rate in the checkout currency. The carrier's own amount stays on
     * source_cost / source_currency. Null drops the rate: no currency, or one we
     * have no exchange rate for.
     */
    protected static function convertRateToQuote(array $rate, array $quote): ?array
    {
        $currency = strtoupper(trim((string) Arr::get($rate, 'currency', '')));
        $cost = Arr::get($rate, 'cost');
        if ($currency === '' || !is_numeric($cost) || $cost < 0 || Arr::get($rate, 'id', '') === '') {
            return null;
        }

        $quoteCurrency = strtoupper((string) Arr::get($quote, 'currency', ''));

        if ($currency === $quoteCurrency) {
            $converted = PriceConverter::toQuoteUnit($cost, $quote);
        } elseif (BaseCurrency::is($currency)) {
            $converted = PriceConverter::toQuote($cost, $quote);
        } else {
            $from = CartCurrency::freshQuote($currency);
            $converted = $from ? PriceConverter::between($cost, $from, $quote) : null;
        }

        if ($converted === null) {
            return null;
        }

        $rate['source_cost'] = Helper::roundCent($cost);
        $rate['source_currency'] = $currency;
        $rate['cost'] = (int) $converted;
        $rate['currency'] = $quoteCurrency;

        return $rate;
    }

    public static function resetRateCache(): void
    {
        static::$carrierRateCache = [];
        static::$carrierPendingCache = [];
        static::$reuseCarrierQuotes = false;
        static::$rateSourceState = ['key' => '', 'pending' => []];
    }

    /**
     * For the rest of this request, live carriers get `reuse_quote` and may
     * answer from the rates they last quoted for the same address and cart.
     * Place Order sets it so the customer does not wait on a carrier API.
     */
    public static function reuseCarrierQuotes(): void
    {
        static::$reuseCarrierQuotes = true;
    }

    /**
     * The chosen rate from the same address-scoped list every checkout path prices
     * against. Ids compare as exact strings, so "1<b>2" or a re-cased token never
     * matches. Null when the id is not in the list.
     *
     * @param string     $country
     * @param string     $state
     * @param array|null $items       Cart line items; null uses the current cart's.
     * @param mixed      $selectedId  Zone method id or carrier rate token.
     * @param array      $destination ['postcode' => , 'city' => ]
     * @return array|null Rate array (see CarrierContract::getRates).
     */
    public static function resolveSelectedRate($country, $state, $items, $selectedId, array $destination = []): ?array
    {
        if (!is_scalar($selectedId) || (string) $selectedId === '') {
            return null;
        }

        return static::findRate(static::getShippingMethods($country, $state, null, $items, $destination), $selectedId);
    }

    /**
     * The rate whose id is exactly $selectedId, compared as strings.
     *
     * @param array[]|\WP_Error $rates
     * @param mixed             $selectedId
     */
    public static function findRate($rates, $selectedId): ?array
    {
        if (is_wp_error($rates) || !$rates || !is_scalar($selectedId) || (string) $selectedId === '') {
            return null;
        }

        foreach ($rates as $rate) {
            if ((string) Arr::get($rate, 'id') === (string) $selectedId) {
                return $rate;
            }
        }

        return null;
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
                'status' => false,
                'error_type' => 'no_country',
                'message' => __('Please provide your address to view shipping options', 'fluent-cart')
            ];
        }

        // Manual off drops only its zones; another carrier's rates still show.
        $availableShippingMethods = static::collectCarrierRates($countryCode, $state, Arr::get($data, 'items'), [], (bool) Arr::get($data, 'zones_only'));

        if (!$availableShippingMethods) {
            $settingView = '<div class="fct-empty-state">'
                . esc_html__('No shipping methods available for this address.', 'fluent-cart');

            if (current_user_can('manage_options')) {
                $settingsPageUrl = admin_url('admin.php?page=fluent-cart#/settings/shipping/carriers/' . Shipment::CARRIER_MANUAL);

                $settingsLink = '<a href="' . esc_url($settingsPageUrl ?? '') . '" target="_blank">' . esc_html__('Activate from settings.', 'fluent-cart') . '</a>';

                $settingView .= ' ' . $settingsLink;
            }

            $settingView .= '</div>';

            return [
                'status' => false,
                'country_code' => $countryCode,
                'view' => $settingView
            ];
        }

        return [
            'available_shipping_methods' => $availableShippingMethods,
            'country_code' => $countryCode
        ];
    }

    /**
     * @param array|null $items       Cart line items; null uses the current cart's.
     * @param array      $destination ['postcode' => , 'city' => ]
     * @return array[]|\WP_Error Rate arrays (see CarrierContract::getRates).
     */
    public static function getShippingMethods($country, $state = null, $timezone = null, $items = null, array $destination = [])
    {
        if (!$country && $timezone) {
            $country = LocalizationManager::guessCountryFromTimezone($timezone);
        }

        if (!$country) {
            return new \WP_Error('no_country', __('Please provide your shipping address to get shipping options', 'fluent-cart'));
        }

        // Manual off drops only its zones; other carriers' rates survive.
        $shippingMethods = static::collectCarrierRates($country, $state, $items, $destination);

        // let's filter the shipping methods
        $formattedMethods = [];

        $requireState = false;

        foreach ($shippingMethods as $shippingMethod) {
            $methodStates = (array) Arr::get($shippingMethod, 'states', []);

            if (!$methodStates) {
                $formattedMethods[] = $shippingMethod;
                continue;
            }

            // now we have states for this shipping method
            if (!$state) {
                $requireState = true;
                continue;
            }

            if (in_array($state, $methodStates)) {
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
            unset($requirementsFields['first_name']);
            unset($requirementsFields['last_name']);
            unset($requirementsFields['company_name']);
        }

        $addresses = CustomerAddressResource::get([
            'type' => $type,
            'customer_id' => $customer->id,
            'status' => 'active'
        ]);

        $allowedAddresses = [];

        foreach ($addresses as $address) {
            $isValid = true;


            // Resolve name fields for validation from available sources
            $addressName = trim(Arr::get($address, 'name') ?? '');
            $metaFirstName = trim(Arr::get($address, 'meta.other_data.first_name') ?? '');
            $metaLastName = trim(Arr::get($address, 'meta.other_data.last_name') ?? '');

            if (empty(Arr::get($address, 'first_name'))) {
                if ($metaFirstName) {
                    $address['first_name'] = $metaFirstName;
                    $address['last_name'] = $metaLastName;
                } else if ($addressName) {
                    $nameParts = static::guessFirstNameAndLastName($addressName);
                    $address['first_name'] = Arr::get($nameParts, 'first_name', '');
                    $address['last_name'] = Arr::get($nameParts, 'last_name', '');
                }
            }

            if (empty(Arr::get($address, 'full_name'))) {
                if ($addressName) {
                    $address['full_name'] = $addressName;
                } else if ($metaFirstName) {
                    $address['full_name'] = trim($metaFirstName . ' ' . $metaLastName);
                }
            }

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

        $orderId = Arr::get($config, 'order_id', null);
        if ($orderId) {
            $order = Order::find($orderId);

            if ($type === 'billing') {
                if ($order && $order->billing_address) {
                    $primaryAddress = $order->billing_address->toArray();
                } else if ($order && $order->shipping_address) {
                    $primaryAddress = $order->shipping_address->toArray();
                }
            }
        } else {
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

                $businessInfoFields = [$type . '_company_name', $type . '_legal_registration_id'];
                foreach ($addressData as $key => $value) {
                    if ($value !== '') {
                        if (in_array($key, $businessInfoFields) && !empty($data[$key])) {
                            continue;
                        }
                        $data[$key] = $value;
                    }
                }

                if ($type === 'billing') {
                    $vatNumber = Arr::get($addressData, 'billing_vat_number', '');
                    if ((!isset($data['fct_billing_tax_id']) || $data['fct_billing_tax_id'] === '') && $vatNumber !== '') {
                        $data['fct_billing_tax_id'] = $vatNumber;
                    }
                }
            }
        }
        return $data;
    }

    public static function mergeBillingWithShipping($data)
    {
        $keys = [
            'full_name',
            'address_1',
            'address_2',
            'city',
            'state',
            'phone',
            'postcode',
            'country',
            'company_name'
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
