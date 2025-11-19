<?php

namespace FluentCart\App\Services\Tax;


use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\TaxRate;
use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\Framework\Support\Collection;
use FluentCart\App\Services\DateTime\DateTime;

class TaxManager
{
    /**
     * @var TaxManager|null
     */
    private static $instance = null;

    /**
     * @var array
     */
    private $rates = [];


    /**
     * @var array
     */
    private $config = [];


    /**
     * @var array
     */
    private array $descriptionMap = [];

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        $this->descriptionMap = [
            'standard' => __('Default tax class for most products.', 'fluent-cart'),
            'zero'     => __('For items with 0% tax.', 'fluent-cart'),
            'reduced'  => __('For items with a reduced tax rate.', 'fluent-cart'),
        ];
        $this->rates = require __DIR__ . '/tax.php';
        $this->config = require __DIR__ . '/config.php';
    }

    /**
     * Get the singleton instance
     *
     * @return TaxManager
     */
    public static function getInstance(): TaxManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get all tax rates
     *
     * @return array
     */
    public function getRates(): array
    {
        return $this->rates;
    }

    /**
     * Generate human-readable label from a tax key
     *
     * @param string $key
     * @return string
     */
    private function formatLabel(string $key): string
    {
        // Special mappings
        $map = [
            'standard' => __('Standard', 'fluent-cart'),
            'zero'     => __('Zero', 'fluent-cart'),
        ];

        if (isset($map[$key])) {
            return $map[$key];
        }

        // If key ends with number (like reduced_1, reduced_2)
        if (preg_match('/^(.+?)_(\d+)$/', $key, $matches)) {
            $prefix = ucfirst(str_replace('_', ' ', $matches[1]));
            $num = (int)$matches[2];

            $numberMap = [
                1  => __('One', 'fluent-cart'),
                2  => __('Two', 'fluent-cart'),
                3  => __('Three', 'fluent-cart'),
                4  => __('Four', 'fluent-cart'),
                5  => __('Five', 'fluent-cart'),
                6  => __('Six', 'fluent-cart'),
                7  => __('Seven', 'fluent-cart'),
                8  => __('Eight', 'fluent-cart'),
                9  => __('Nine', 'fluent-cart'),
                10 => __('Ten', 'fluent-cart'),
                11 => __('Eleven', 'fluent-cart'),
                12 => __('Twelve', 'fluent-cart'),
                13 => __('Thirteen', 'fluent-cart'),
                14 => __('Fourteen', 'fluent-cart'),
                15 => __('Fifteen', 'fluent-cart')
            ];

            return $prefix . ' ' . ($numberMap[$num] ?? $num);
        }

        // Default: convert snake_case → words
        return ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Iterate all countries and collect all unique tax labels
     *
     * @return array
     */
    public function generateAllTaxLabels($only = []): array
    {
        $labels = [];

        $rates = $this->rates;

        if (!empty($only)) {
            $rates = Arr::only($rates, $only);
        }

        foreach ($rates as $country => $data) {
            if (!isset($data['tax'])) {
                continue;
            }

            foreach (array_keys($data['tax']) as $key) {
                if (!isset($labels[$key])) {
                    $labels[$key] = $this->formatLabel($key);
                }
            }
        }

        return $labels;
    }

    public function generateTaxClasses($only = [])
    {

        $taxClassLabels = [
            'standard' => __('Standard', 'fluent-cart'),
            'reduced'  => __('Reduced', 'fluent-cart'),
            'zero'     => __('Zero', 'fluent-cart'),
        ];

        $taxClassIds = [];

        foreach ($taxClassLabels as $key => $label) {
            $description = $this->descriptionMap[$key];
            $priority = $key === 'standard' ? 10 : ($key === 'reduced' ? 5 : 2);
            $taxClass = TaxClass::query()->firstOrCreate(
                ['title' => $label], // search by title
                [
                    'slug'        => $key,
                    'description' => $description,
                    'meta' => [
                        'categories' => [],
                        'priority' => $priority,
                    ]
                ]
            );

            $taxClassIds[$key] = $taxClass->id;
        }

        $ratesMap = [];

        $rates = $this->rates;

        if (!empty($only)) {
            $rates = Arr::only($rates, $only);
        }

        // Get existing countries already in tax_rates
        $existingCountries = TaxRate::query()
            ->pluck('country')
            ->unique()
            ->toArray();

        foreach ($rates as $country => $data) {
            if (in_array($country, $existingCountries)) {
                // Skip if this country already exists
                continue;
            }

            if (!isset($data['tax'] )) {
                continue;
            }

            foreach ($data['tax'] as $key => $rate) {
                $compound = $rate['compound'] ?? false;
                $shipping = $rate['shipping'] ?? false;

                $typeKey = $rate['type'] ?? explode('_', $key)[0];


                $ratesMap[] = [
                    'country'      => $country,
                    'name'         => $rate['name'] ?? $country . ' ' . $taxClassLabels[$typeKey] . ' Tax',
                    'class_id'     => $taxClassIds[$typeKey],
                    'rate'         => $rate['rate'],
                    'is_compound'  => $compound ? 1 : 0,
                    'group'        => $data['group'] ?? '',
                    'for_shipping' => null,
                    'state'        => $rate['state'] ?? '',
                    'city'         => $rate['city'] ?? '',
                ];
                //$this->rates[$country]['tax'][$key]['tax_class_id'] = $idMap[$key] ?? null;
            }
        }

        TaxRate::query()->insert($ratesMap);
    }


    public function getEuTaxRatesFromPhp(string $country = '', $taxClassSlug = ''): array
    {
        if (!empty($country)) {
            $countryData = $this->rates[$country] ?? null;
            $rates = Arr::get($countryData, 'tax', []);
            if (empty($taxClassSlug)) {
                return $rates;
            }
            $rates = array_filter($rates, function ($tax) use ($taxClassSlug) {
                return $tax['type'] === $taxClassSlug;
            });
            return $rates;
        }

        $formattedData = [];
        foreach ($this->rates as $countryCode => $rate) {
            if (isset($rate['group']) && $rate['group'] === 'EU' && isset($rate['tax'])) {
                $formattedData[$countryCode] = $rate['tax'];
            }
        }
        
        return $formattedData;
    }

    public function getTaxRatesFromTaxPhp(): array
    {
        $rates = $this->rates;
        $formattedData = [];

        foreach ($rates as $countryCode => $countries) {
            $group = $countries['group'];

            $countryName = AddressHelper::getCountryNameByCode($countryCode);

            if (!isset($formattedData[$group])) {
                $localization = App::localization();
                $continent = $localization->continents($group);

                if ($group === 'EU') {
                    $groupName = __('European Union', 'fluent-cart');
                } else {
                    $groupName = Arr::get($continent, 'name') ?? __('Rest of the World', 'fluent-cart');
                }

                $formattedData[$group] = [
                    'group_name'      => $groupName,
                    'group_code'      => $group,
                    'countries'       => [],
                    'total_countries' => 0
                ];
            }

            $formattedData[$group]['countries'][] = [
                'country_code' => $countryCode,
                'country_name' => $countryName,
                'total_rates'  => count($countries['tax']),
                'rates'        => $countries['tax']
            ];

            $formattedData[$group]['total_countries'] += 1;
        }
        return $formattedData;
    }

    public function getTaxRates(): array
    {
        $taxRates = TaxRate::query()->select('group', 'country', 'name', 'rate', 'class_id')
            ->orderBy('group')
            ->orderBy('country')
            ->orderBy('class_id')
            ->get();

        $groupedTaxRates = $this->groupTaxRatesByGroup($taxRates);

        return $groupedTaxRates;
    }


    public function groupTaxRatesByGroup($taxRates): array
    {
        $grouped = [];

        foreach ($taxRates as $rate) {
            $localization = App::localization();
            $continent = $localization->continents($rate->group);
            $groupName = Arr::get($continent, 'name') ?? __('Other', 'fluent-cart');
            $countryCode = $rate->country;
            $countryName = AddressHelper::getCountryNameByCode($countryCode);

            // Initialize group
            if (!isset($grouped[$groupName])) {
                $grouped[$groupName] = [
                    'group_name'      => $groupName,
                    'group_code'      => $rate->group,
                    'countries'       => [],
                    'total_countries' => 0
                ];
            }

            // Initialize country
            if (!isset($grouped[$groupName]['countries'][$countryCode])) {
                $grouped[$groupName]['countries'][$countryCode] = [
                    'country_code' => $countryCode,
                    'country_name' => $countryName,
                    'rates'        => [],
                    'total_rates'  => 0
                ];
            }

            // Add rate
            $grouped[$groupName]['countries'][$countryCode]['rates'][] = [
                'class_id' => $rate->class_id,
                'name'     => $rate->name,
                'rate'     => $rate->rate,
                'for_shipping' => $rate->for_shipping
            ];
        }

        // Format result
        $result = [];
        foreach ($grouped as $groupName => $groupData) {
            $countries = [];
            foreach ($groupData['countries'] as $countryData) {
                $countryData['total_rates'] = count($countryData['rates']);
                $countries[] = $countryData;
            }

            $result[] = [
                'group_name'      => $groupName,
                'group_code'      => $groupData['group_code'],
                'countries'       => $countries,
                'total_countries' => count($countries)
            ];
        }

        return $result;
    }

    public function getCountryConfiguration(string $countryCode)
    {
        $config = Arr::get($this->config, 'countries.' . $countryCode);

        if(!empty($config)){
            return $config;
        }

        $group = Arr::get($this->rates, $countryCode . '.group');
        return Arr::get($this->config, 'continents.' . $group);
    }

    public function calculateTotalCartTax()
    {
        $getCart = \FluentCart\App\Helpers\CartHelper::getCart();

        $taxSettings = (new TaxModule())->getSettings();

        if (Arr::get($taxSettings, 'tax_calculation_basis') === 'store') {
            $country = (new StoreSettings())->get('store_country') ?? '';
            $state = (new StoreSettings())->get('store_state') ?? null;
            $city = (new StoreSettings())->get('store_city') ?? null;
            $postCode = (new StoreSettings())->get('store_postcode') ?? null;
        } else if (Arr::get($taxSettings, 'tax_calculation_basis') === 'billing') {
            $country = Arr::get($getCart, 'checkout_data.form_data.billing_country') ?? '';
            $state = Arr::get($getCart, 'checkout_data.form_data.billing_state') ?? null;
            $city = Arr::get($getCart, 'checkout_data.form_data.billing_city') ?? null;
            $postCode = Arr::get($getCart, 'checkout_data.form_data.billing_postcode') ?? null;
        } else {
            $country = Arr::get($getCart, 'checkout_data.form_data.shipping_country') ?? '';
            $state = Arr::get($getCart, 'checkout_data.form_data.shipping_state') ?? null;
            $city = Arr::get($getCart, 'checkout_data.form_data.shipping_city') ?? null;
            $postCode = Arr::get($getCart, 'checkout_data.form_data.shipping_postcode') ?? null;
        }

        $calculator = TaxCalculator::calculateTaxForCart($getCart, $country, $state, $city, $postCode);
        return $calculator->getTotalTax();
    }
}
