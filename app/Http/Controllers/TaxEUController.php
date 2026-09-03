<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Models\Meta;
use FluentCart\App\Models\TaxRate;
use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Framework\Http\Request\Request;

class TaxEUController extends Controller
{
    public function saveEuVatSettings(Request $request)
    {
        $action = $request->getSafe('action', 'sanitize_text_field');

        if ($action === 'euCrossBorderSettings') {
            return $this->euCrossBorderSettings($request);
        } else if ($action === 'saveCountryRegistration') {
            return $this->saveCountryRegistration($request);
        } else if ($action === 'deleteCountryRegistration') {
            return $this->deleteCountryRegistration($request);
        } else {
            return $this->sendError([
                'message' => __('Invalid method', 'fluent-cart')
            ], 422);
        }
    }

    public function saveCountryRegistration(Request $request)
    {
        $countryCode = $this->sanitizeCountryCode($request->get('country', ''));
        $vat = $request->getSafe('vat', 'sanitize_text_field');
        $rawRates = $request->get('rates', []);
        $rateErrors = [];

        // Normalise rates: each entry is either {rate, label} or a legacy plain float
        $rates = [];
        if (is_array($rawRates)) {
            foreach ($rawRates as $slug => $rateData) {
                $cleanSlug = sanitize_key($slug);
                if (!$cleanSlug) {
                    continue;
                }
                if (is_array($rateData)) {
                    $rates[$cleanSlug] = [
                        'rate'  => floatval($rateData['rate'] ?? 0),
                        'label' => sanitize_text_field($rateData['label'] ?? ''),
                    ];
                } else {
                    // Legacy plain-number format
                    $rates[$cleanSlug] = ['rate' => floatval($rateData), 'label' => ''];
                }
            }
        }

        // Legacy single-rate fallback (old clients send rate= not rates=)
        if (empty($rates)) {
            $legacyRate = floatval($request->get('rate', 0));
            if ($legacyRate > 0) {
                $rates['standard'] = ['rate' => $legacyRate, 'label' => sanitize_text_field($request->get('tax_label', ''))];
            }
        }

        if (!$countryCode) {
            return $this->sendError([
                'message' => __('Select a registration country', 'fluent-cart'),
            ], 422);
        }

        if (!$this->isEuVatCountry($countryCode)) {
            return $this->sendError([
                'message' => __('Select a valid EU VAT registration country', 'fluent-cart'),
                'errors'  => [
                    'country' => __('Select a valid EU VAT registration country', 'fluent-cart')
                ]
            ], 422);
        }

        if (strlen($vat) > 50) {
            return $this->sendError([
                'message' => __('VAT number is too long', 'fluent-cart'),
            ], 422);
        }

        $hasNonZeroRate = false;
        foreach ($rates as $rateData) {
            if (floatval($rateData['rate'] ?? 0) > 0) {
                $hasNonZeroRate = true;
                break;
            }
        }

        if (!$hasNonZeroRate) {
            return $this->sendError([
                'message' => __('At least one tax rate must be greater than 0%', 'fluent-cart'),
            ], 422);
        }

        $requestedClassSlugs = array_keys($rates);
        $taxClasses = TaxClass::query()
            ->whereIn('slug', $requestedClassSlugs)
            ->get()
            ->keyBy('slug');

        foreach ($requestedClassSlugs as $classSlug) {
            if (!$taxClasses->has($classSlug)) {
                /* translators: %1$s: tax class slug (e.g. standard, reduced) */
                $rateErrors['rates.' . $classSlug] = sprintf(
                    __('Tax class "%1$s" could not be found. Create the class first and try again.', 'fluent-cart'),
                    $classSlug
                );
            }
        }

        if ($rateErrors) {
            return $this->sendError([
                'message' => __('Validation failed for VAT registration rates', 'fluent-cart'),
                'errors'  => $rateErrors,
            ], 422);
        }

        $standardRate  = floatval($rates['standard']['rate'] ?? 0);
        $standardLabel = sanitize_text_field($rates['standard']['label'] ?? '');

        TaxManager::getInstance()->saveEuVatRegistration($countryCode, [
            'country'   => $countryCode,
            'vat'       => $vat,
            'rate'      => $standardRate,
            'rates'     => $rates,
            'tax_label' => $standardLabel,
        ]);

        $currentSettings = (new TaxModule())->getSettings();
        $euVatSettings = Arr::get($currentSettings, 'eu_vat_settings', []);
        if (Arr::get($euVatSettings, 'method') === 'home' && Arr::get($euVatSettings, 'home_country') === $countryCode) {
            $euVatSettings['home_vat'] = $vat;
            $currentSettings['eu_vat_settings'] = $euVatSettings;
            update_option('fluent_cart_tax_configuration_settings', $currentSettings, true);
        }

        return $this->sendSuccess([
            'message' => __('Country VAT registration saved successfully', 'fluent-cart')
        ]);
    }

    public function deleteCountryRegistration(Request $request)
    {
        $countryCode = $this->sanitizeCountryCode($request->get('country', ''));

        if (!$countryCode) {
            return $this->sendError([
                'message' => __('Country code is required', 'fluent-cart'),
            ], 422);
        }

        if (!$this->isEuVatCountry($countryCode)) {
            return $this->sendError([
                'message' => __('Select a valid EU VAT registration country', 'fluent-cart'),
            ], 422);
        }

        TaxManager::getInstance()->deleteEuVatRegistration($countryCode);

        return $this->sendSuccess([
            'message' => __('Country registration removed successfully', 'fluent-cart')
        ]);
    }

    public function getOssCountryRates()
    {
        $euCountries = TaxModule::euVatCountyOptions();

        // Get all tax classes
        $taxClasses = TaxClass::query()->orderBy('id', 'ASC')->get();
        $classMap = $taxClasses->keyBy('slug');

        // Load all EU rates grouped by country then class_id
        $allDbRates = TaxRate::query()
            ->where('group', 'EU')
            ->where(function ($q) {
                $q->whereNull('state')->orWhere('state', '');
            })
            ->get()
            ->groupBy('country');

        $rates = [];
        foreach ($euCountries as $country) {
            $code = $country['value'];
            $countryDbRates = $allDbRates->get($code, new Collection())->keyBy('class_id');
            $defaultRates = $country['default_rates'] ?? [];

            $classRates = [];
            foreach ($taxClasses as $tc) {
                $dbRate = $countryDbRates->get($tc->id);
                $defaultRate = $defaultRates[$tc->slug] ?? 0;
                // Strip auto-generated fallback names like "AT Reduced Tax" → show as empty so UI shows placeholder
                $dbLabel = '';
                if ($dbRate && $dbRate->name && !preg_match('/^[A-Z]{2} \w+ Tax$/', $dbRate->name)) {
                    $dbLabel = $dbRate->name;
                }
                $classRates[$tc->slug] = [
                    'rate'         => $dbRate ? (float) $dbRate->rate : ($defaultRate ?? 0),
                    'default_rate' => $defaultRate ?? 0,
                    'has_custom'   => (bool) $dbRate,
                    'label'        => $dbLabel,
                ];
            }

            // Tax label for top-level (from standard class, for backward compat)
            $standardClassId = $classMap->has('standard') ? $classMap->get('standard')->id : 1;
            $standardDbRate = $countryDbRates->get($standardClassId);
            $taxLabel = $classRates['standard']['label'] ?? 'VAT';
            if (!$taxLabel) $taxLabel = 'VAT';

            $rates[] = [
                'country'      => $code,
                'label'        => $country['label'],
                'rate'         => $classRates['standard']['rate'] ?? ($country['default_rate'] ?? 0),
                'tax_label'    => $taxLabel,
                'default_rate' => $country['default_rate'] ?? 0,
                'has_custom'   => $classRates['standard']['has_custom'] ?? false,
                'class_rates'  => $classRates,
            ];
        }

        $classesInfo = $taxClasses->map(function ($tc) {
            return ['slug' => $tc->slug, 'title' => $tc->title, 'id' => $tc->id];
        })->values();

        return $this->sendSuccess([
            'rates'   => $rates,
            'classes' => $classesInfo,
        ]);
    }

    public function saveOssCountryRates(Request $request)
    {
        $rates = $request->get('rates', []);
        $taxClasses = TaxClass::query()->get()->keyBy('slug');
        $errors = [];

        foreach ($rates as $index => $item) {
            $country = $this->sanitizeCountryCode($item['country'] ?? '');
            $taxLabel = sanitize_text_field($item['tax_label'] ?? '');
            if (!$country) continue;

            if (!$this->isEuVatCountry($country)) {
                $errors['rates.' . $index . '.country'] = __('Select a valid EU VAT country', 'fluent-cart');
                continue;
            }

            $classRates = $item['class_rates'] ?? [];

            // If no class_rates provided, fall back to single 'rate' field (backward compat)
            if (empty($classRates)) {
                $standardClass = $taxClasses->get('standard');
                $classId = $standardClass ? $standardClass->id : 1;
                $rate = floatval($item['rate'] ?? 0);
                $rateName = $taxLabel ?: ($country . ' Standard Tax');

                $this->upsertOssRate($country, $classId, $rate, $rateName);
                continue;
            }

            // Save rate for each class — use per-class label, fall back to shared tax_label
            foreach ($classRates as $classSlug => $classData) {
                $classSlug = sanitize_key($classSlug);
                $taxClass = $taxClasses->get($classSlug);
                if (!$taxClass) continue;

                $rate      = floatval($classData['rate'] ?? 0);
                $classLabel = sanitize_text_field($classData['label'] ?? '');
                $rateName  = $classLabel ?: ($taxLabel ?: ($country . ' ' . ucfirst($classSlug) . ' Tax'));

                $this->upsertOssRate($country, $taxClass->id, $rate, $rateName);
            }
        }

        if ($errors) {
            return $this->sendError([
                'message' => __('Validation failed for OSS country rates', 'fluent-cart'),
                'errors'  => $errors
            ], 422);
        }

        return $this->sendSuccess([
            'message' => __('OSS country rates saved successfully', 'fluent-cart')
        ]);
    }

    private function sanitizeNestedArray(array $data)
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeNestedArray($value);
            } elseif (is_numeric($value)) {
                $sanitized[$key] = $value + 0;
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }
        return $sanitized;
    }

    private function upsertOssRate($country, $classId, $rate, $rateName)
    {
        TaxRate::query()->updateOrCreate(
            [
                'country'  => $country,
                'group'    => 'EU',
                'class_id' => $classId,
                'state'    => '',
            ],
            [
                'name'     => $rateName,
                'rate'     => $rate,
                'city'     => '',
                'postcode' => '',
            ]
        );
    }

    public function getEuProductOverrides()
    {
        $euCountryCodes = array_column(TaxModule::euVatCountyOptions(), 'value');

        $taxClasses = TaxClass::query()->get()->keyBy('id');

        $overrides = Meta::query()
            ->productCategoryTaxOverrides()
            ->forTaxOverrideCountries($euCountryCodes)
            ->get();

        foreach ($overrides as $override) {
            $classId = (int) Arr::get($override->meta_value, 'class_id', 0);
            $taxClass = $classId ? $taxClasses->get($classId) : null;
            $override->setAttribute('class_id', $classId);
            $override->setAttribute('class_label', $taxClass ? $taxClass->title : '');
        }

        $shippingOverrides = TaxRate::query()
            ->where('group', 'EU')
            ->whereNotNull('for_shipping')
            ->where(function ($q) {
                $q->whereNull('state')->orWhere('state', '');
            })
            ->orderBy('country', 'asc')
            ->orderBy('class_id', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($rate) use ($taxClasses) {
                $taxClass = $taxClasses->get($rate->class_id);
                $rate->setAttribute('class_label', $taxClass ? $taxClass->title : '');
                return $rate;
            })
            ->values();

        return $this->sendSuccess([
            'overrides'          => $overrides,
            'shipping_overrides' => $shippingOverrides,
        ]);
    }

    public function resetEuRatesToDefaults()
    {
        $taxManager = TaxManager::getInstance();
        $taxManager->resetEuRates();

        return $this->sendSuccess([
            'message' => __('EU tax rates have been reset to defaults', 'fluent-cart')
        ]);
    }

    public function euCrossBorderSettings(Request $request)
    {
        $newEuVatSettings = (array) $request->get('eu_vat_settings', []);
        $sanitizedEuVatSettings = $this->sanitizeNestedArray($newEuVatSettings);
        $method = Arr::get($sanitizedEuVatSettings, 'method');
        $ossCountry = $this->sanitizeCountryCode(Arr::get($sanitizedEuVatSettings, 'oss_country'));
        $homeCountry = $this->sanitizeCountryCode(Arr::get($sanitizedEuVatSettings, 'home_country'));
        Arr::set($sanitizedEuVatSettings, 'oss_country', $ossCountry);
        Arr::set($sanitizedEuVatSettings, 'home_country', $homeCountry);
        $errors = [];

        if (!in_array($method, ['oss', 'home', 'specific'], true)) {
            $errors['method'] = __('Select a cross-border registration type', 'fluent-cart');
        } else if ($method === 'oss') {
            if (!$ossCountry) {
                $errors['oss_country'] = __('Select country of OSS registration', 'fluent-cart');
            } else if (!$this->isEuVatCountry($ossCountry)) {
                $errors['oss_country'] = __('Select a valid EU VAT country', 'fluent-cart');
            }
        } else if ($method === 'home') {
            if (!$homeCountry) {
                $errors['home_country'] = __('Select home country of registration', 'fluent-cart');
            } else if (!$this->isEuVatCountry($homeCountry)) {
                $errors['home_country'] = __('Select a valid EU VAT country', 'fluent-cart');
            }
        }

        if ($errors) {
            return $this->sendError([
                'message' => __('Validation failed for EU VAT settings', 'fluent-cart'),
                'errors'  => $errors
            ], 422);
        }
        $currentSettings = (new TaxModule())->getSettings();

        $currentSettings['eu_vat_settings'] = array_merge(
            Arr::get($currentSettings, 'eu_vat_settings', []),
            $sanitizedEuVatSettings
        );

        if ($request->getSafe('reset_registration', 'sanitize_text_field') === 'yes') {
            Arr::set($currentSettings['eu_vat_settings'], 'method', '');
        }

        update_option('fluent_cart_tax_configuration_settings', $currentSettings, true);

        return $this->sendSuccess([
            'message' => __('EU VAT settings saved successfully', 'fluent-cart')
        ]);

    }

    private function sanitizeCountryCode($countryCode)
    {
        return strtoupper(sanitize_text_field($countryCode));
    }

    private function isEuVatCountry($countryCode)
    {
        static $euCountryCodes = null;

        if ($euCountryCodes === null) {
            $euCountryCodes = array_map(function ($country) {
                return strtoupper($country['value']);
            }, TaxModule::euVatCountyOptions());
        }

        return in_array($countryCode, $euCountryCodes, true);
    }
}
