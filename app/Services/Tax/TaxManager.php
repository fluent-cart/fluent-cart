<?php

namespace FluentCart\App\Services\Tax;


use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\TaxRate;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Models\BatchQuery\Batch;
use FluentCart\Framework\Database\Query\Expression;
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
     * @var array
     */
    private array $countryEnabledCache = [];

    /** ISO country codes whose rates are stored under a parent country with state=<code>. */
    private array $parentCountryMap = [
        //'GP' => 'FR', // Guadeloupe
        //'MQ' => 'FR', // Martinique
        //'RE' => 'FR', // Réunion
    ];

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
                    'name'         => $rate['name'] ?? (
                        ($data['group'] ?? '') === 'EU' && $typeKey === 'standard'
                            ? $this->buildDefaultRateLabel($country)
                            : $country . ' ' . ($taxClassLabels[$typeKey] ?? ucfirst($typeKey)) . ' Tax'
                    ),
                    'class_id'     => $taxClassIds[$typeKey],
                    'rate'         => $rate['rate'],
                    'is_compound'  => $compound ? 1 : 0,
                    'group'        => $data['group'] ?? '',
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

    /**
     * Map territory country codes (e.g. GP) to their parent country + state
     * so tax rate lookups hit the correct DB rows (e.g. country=FR, state=GP).
     */
    public function resolveTaxCountryAndState(string $country, ?string $state): array
    {
        $country = strtoupper($country);
        if (isset($this->parentCountryMap[$country])) {
            return ['country' => $this->parentCountryMap[$country], 'state' => $country];
        }
        return ['country' => $country, 'state' => $state];
    }

    public function normalizeTaxStatusCountryCode(string $countryCode): string
    {
        $countryCode = strtoupper(sanitize_text_field($countryCode));

        if ($countryCode === 'EU') {
            return 'EU';
        }

        // Resolve territory codes (GP→FR) before the EU group check.
        if (isset($this->parentCountryMap[$countryCode])) {
            $countryCode = $this->parentCountryMap[$countryCode];
        }

        if (Arr::get($this->rates, $countryCode . '.group') === 'EU') {
            return 'EU';
        }

        return $countryCode;
    }

    public function getCountryTaxEnabledMetaKey(string $countryCode): string
    {
        return 'fluent_cart_tax_enabled_' . $this->normalizeTaxStatusCountryCode($countryCode);
    }

    public function getCountryTaxEnabledMap(array $countryCodes): array
    {
        $countryCodes = array_values(array_unique(array_filter(array_map(function ($countryCode) {
            return strtoupper(sanitize_text_field($countryCode));
        }, $countryCodes))));

        if (!$countryCodes) {
            return [];
        }

        $normalizedMap = [];
        foreach ($countryCodes as $countryCode) {
            $normalizedMap[$countryCode] = $this->normalizeTaxStatusCountryCode($countryCode);
        }

        $normalizedCodes = array_values(array_unique(array_values($normalizedMap)));
        $metaKeys = array_map(function ($countryCode) {
            return 'fluent_cart_tax_enabled_' . $countryCode;
        }, $normalizedCodes);

        $metaRows = Meta::query()
            ->where('object_type', 'tax')
            ->whereIn('meta_key', $metaKeys)
            ->get();

        $enabledByNormalizedCode = array_fill_keys($normalizedCodes, true);

        foreach ($metaRows as $metaRow) {
            $normalizedCountryCode = strtoupper(str_replace('fluent_cart_tax_enabled_', '', $metaRow->meta_key));
            $enabledByNormalizedCode[$normalizedCountryCode] = $this->parseCountryTaxEnabledValue($metaRow->meta_value);
            $this->countryEnabledCache[$normalizedCountryCode] = $enabledByNormalizedCode[$normalizedCountryCode];
        }

        $enabledMap = [];
        foreach ($normalizedMap as $countryCode => $normalizedCountryCode) {
            $enabledMap[$countryCode] = $enabledByNormalizedCode[$normalizedCountryCode] ?? true;
        }

        return $enabledMap;
    }

    public function isTaxEnabledForCountry(string $countryCode): bool
    {
        $normalizedCountryCode = $this->normalizeTaxStatusCountryCode($countryCode);

        if (array_key_exists($normalizedCountryCode, $this->countryEnabledCache)) {
            return $this->countryEnabledCache[$normalizedCountryCode];
        }

        $metaKey = 'fluent_cart_tax_enabled_' . $normalizedCountryCode;
        $meta = Meta::query()
            ->where('meta_key', $metaKey)
            ->where('object_type', 'tax')
            ->first();

        if ($meta === null) {
            $this->countryEnabledCache[$normalizedCountryCode] = true;
            return true;
        }

        $isEnabled = $this->parseCountryTaxEnabledValue($meta->meta_value);
        $this->countryEnabledCache[$normalizedCountryCode] = $isEnabled;

        return $isEnabled;
    }

    public function setTaxEnabledForCountry(string $countryCode, bool $enabled): void
    {
        $normalizedCountryCode = $this->normalizeTaxStatusCountryCode($countryCode);
        $metaKey = $this->getCountryTaxEnabledMetaKey($normalizedCountryCode);

        Meta::query()
            ->where('meta_key', $metaKey)
            ->where('object_type', 'tax')
            ->delete();

        if (!$enabled) {
            Meta::query()->create([
                'meta_key'    => $metaKey,
                'meta_value'  => ['enabled' => 0],
                'object_type' => 'tax'
            ]);
        }

        $this->countryEnabledCache[$normalizedCountryCode] = $enabled;
    }

    private function parseCountryTaxEnabledValue($metaValue): bool
    {
        $enabledValue = is_array($metaValue) ? Arr::get($metaValue, 'enabled', 1) : $metaValue;

        return intval($enabledValue) === 1;
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

    private function buildDefaultRateLabel(string $countryCode): string
    {
        $countryName = preg_replace('/\s*\([^)]+\)$/', '', AddressHelper::getCountryNameByCode($countryCode));
        /* translators: %1$s: country code (e.g. DE), %2$s: country name (e.g. Germany), %3$s: tax class type (e.g. Standard) */
        return sprintf(__('%1$s VAT - %2$s - %3$s rate', 'fluent-cart'), $countryCode, $countryName, __('Standard', 'fluent-cart'));
    }

    public function resetEuRates($classSlug = 'standard'): void
    {
        $taxClasses = TaxClass::query()->get()->keyBy('slug');

        $targetClass = $taxClasses->get($classSlug);
        if (!$targetClass) {
            return;
        }

        $existing = TaxRate::query()
            ->where('group', 'EU')
            ->where('class_id', $targetClass->id)
            ->where(function ($q) {
                $q->whereNull('state')->orWhere('state', '');
            })
            ->where(function ($q) {
                $q->whereNull('city')->orWhere('city', '');
            })
            ->where(function ($q) {
                $q->whereNull('postcode')->orWhere('postcode', '');
            })
            ->get(['id', 'country', 'class_id', 'name', 'rate', 'for_shipping'])
            ->keyBy(function ($row) {
                return $row->country . ':' . $row->class_id;
            });

        $typeLabels = [
            'standard' => __('Standard', 'fluent-cart'),
            'reduced'  => __('Reduced', 'fluent-cart'),
            'zero'     => __('Zero', 'fluent-cart'),
        ];

        $toCreate = [];
        $toUpdate = [];

        foreach ($this->rates as $countryCode => $data) {
            if (Arr::get($data, 'group') !== 'EU' || empty($data['tax'])) {
                continue;
            }

            foreach ($data['tax'] as $rateData) {
                // Only reset country-level rates; skip state-specific entries (e.g. ES-Mainland, ES-Canary)
                if (!empty($rateData['state'])) {
                    continue;
                }

                $typeKey  = $rateData['type'] ?? 'standard';
                if ($typeKey !== $classSlug) {
                    continue;
                }
                $taxClass = $taxClasses->get($typeKey);
                if (!$taxClass) {
                    continue;
                }

                $name = $rateData['name'] ?? (
                    $typeKey === 'standard'
                        ? $this->buildDefaultRateLabel($countryCode)
                        : $countryCode . ' ' . ($typeLabels[$typeKey] ?? ucfirst($typeKey)) . ' Tax'
                );
                $rate = $rateData['rate'];
                $key  = $countryCode . ':' . $taxClass->id;

                if ($existing->has($key)) {
                    $existingRow = $existing->get($key);
                    $shippingIsWrong = $existingRow->for_shipping !== null;
                    if ($existingRow->name !== $name || (float) $existingRow->rate !== (float) $rate || $shippingIsWrong) {
                        $toUpdate[] = ['id' => $existingRow->id, 'name' => $name, 'rate' => $rate, 'for_shipping' => null];
                    }
                } else {
                    $toCreate[] = [
                        'country'      => $countryCode,
                        'group'        => 'EU',
                        'class_id'     => $taxClass->id,
                        'state'        => '',
                        'city'         => '',
                        'name'         => $name,
                        'rate'         => $rate,
                        'is_compound'  => 0,
                        'for_order'    => 0,
                        'priority'     => 0,
                    ];
                }
            }
        }

        if (empty($toCreate) && empty($toUpdate)) {
            return;
        }

        $db = App::db();
        try {
            $db->beginTransaction();
            if (!empty($toCreate)) {
                TaxRate::query()->insert($toCreate);
            }
            if (!empty($toUpdate)) {
                (new Batch())->update(new TaxRate(), $toUpdate, 'id');
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function getProductOverrideById($overrideId)
    {
        return Meta::query()
            ->where('id', $overrideId)
            ->where('object_type', 'tax_override')
            ->where('meta_key', 'product_category_override')
            ->first();
    }

    public static function clearShippingOverrideById($taxRateId)
    {
        TaxRate::query()->findOrFail($taxRateId);

        // wpdb->prepare() converts PHP null bindings to '' (empty string), which MySQL
        // coerces to 0 on TINYINT columns rather than NULL. Expression inlines NULL
        // directly into the SQL, bypassing the binding path.
        TaxRate::query()->where('id', $taxRateId)->update(['for_shipping' => new Expression('NULL')]);
    }

    // EU VAT registration helpers — stored in fct_meta, keyed by country code.

    public function getEuVatRegistrations()
    {
        $rows = Meta::query()
            ->where('object_type', 'eu_vat_registration')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = (array) $row->meta_value;
        }
        return $result;
    }

    public function getEuVatRegistration($country)
    {
        $row = Meta::query()
            ->where('object_type', 'eu_vat_registration')
            ->where('meta_key', strtoupper($country))
            ->first();

        return $row ? (array) $row->meta_value : null;
    }

    public function saveEuVatRegistration($country, $data)
    {
        Meta::query()->updateOrCreate(
            ['object_type' => 'eu_vat_registration', 'meta_key' => strtoupper($country)],
            ['meta_value' => $data, 'object_id' => 0]
        );
    }

    public function deleteEuVatRegistration($country)
    {
        Meta::query()
            ->where('object_type', 'eu_vat_registration')
            ->where('meta_key', strtoupper($country))
            ->delete();
    }
}
