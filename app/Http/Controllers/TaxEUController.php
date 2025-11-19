<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Models\TaxRate;
use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Framework\Http\Request\Request;

class TaxEUController extends Controller
{
    public function saveEuVatSettings(Request $request)
    {
        if ($request->getSafe('action', 'sanitize_text_field') === 'euCrossBorderSettings') {
            return $this->euCrossBorderSettings($request);
        } else {
            return $this->sendError([
                'message' => __('Invalid method', 'fluent-cart')
            ], 423);
        }
    }

    public function getEuTaxRates(Request $request)
    {
        $taxRates = TaxRate::query()->where('group', 'EU')->select('group', 'country', 'name', 'rate', 'class_id','for_shipping')
        ->orderBy('group')
        ->orderBy('country')
        ->orderBy('class_id')
        ->get();

        $taxManager = TaxManager::getInstance();
        $groupedTaxRates = $taxManager->groupTaxRatesByGroup($taxRates);

        return $this->sendSuccess([
            'tax_rates' => $groupedTaxRates
        ]);
    }

    public function saveOssTaxOverride(Request $request)
    {
        $newOssTaxOverride = $request->get('overrides', []);
        $countryCode = $request->get('country_code');

        $errors = [];
        if (!$countryCode) {
            $errors['country_code'] = __('Select country of OSS registration', 'fluent-cart');
        }

        if ($errors) {
            return $this->sendError([
                'message' => __('Validation failed for OSS tax override', 'fluent-cart'),
                'errors'  => $errors
            ], 423);
        }

        $taxClassesFromType = TaxClass::query()->whereIn('slug', ['standard', 'reduced', 'zero'])->get()->mapWithKeys(function ($item) {
            return [$item->slug => $item->id];
        })->toArray();
  

        foreach ($newOssTaxOverride as $override) {
            if (!isset($override['type']) || !isset($taxClassesFromType[$override['type']])) {
                continue;
            }

            $taxClassId = $taxClassesFromType[$override['type']];

            $existingTaxRate = TaxRate::query()->where('country', $countryCode)->where('group', 'EU')->
            where('class_id', intval($taxClassId))->first();


            if ($existingTaxRate) {
                $existingTaxRate->rate = $override['rate'];
                $existingTaxRate->save();
            } else {
                TaxRate::query()->create([
                    'country' => $countryCode,
                    'name' => $override['type'],
                    'rate' => $override['rate'],
                    'group' => 'EU',
                    'class_id' => intval($taxClassId),
                    'tax_rate' => $override['rate']
                ]);
            }
        }

        return $this->sendSuccess([
            'message' => __('OSS tax override saved successfully', 'fluent-cart')
        ]);
    }

     public function saveOssShippingOverride(Request $request)
    {
        $newOssTaxOverride = $request->get('overrides', []);
        $countryCode = $request->get('country_code');

        $errors = [];
        if (!$countryCode) {
            $errors['country_code'] = __('Select country of OSS registration', 'fluent-cart');
        }

        if ($errors) {
            return $this->sendError([
                'message' => __('Validation failed for OSS tax override', 'fluent-cart'),
                'errors'  => $errors
            ], 423);
        }

        $taxClassesFromType = TaxClass::query()->whereIn('slug', ['standard', 'reduced', 'zero'])->get()->mapWithKeys(function ($item) {
            return [$item->slug => $item->id];
        })->toArray();
  

        foreach ($newOssTaxOverride as $override) {
            if (!isset($override['type']) || !isset($taxClassesFromType[$override['type']])) {
                continue;
            }

            $taxClassId = $taxClassesFromType[$override['type']];

            $existingTaxRate = TaxRate::query()->where('country', $countryCode)->where('group', 'EU')->
            where('class_id', intval($taxClassId))->first();


            if ($existingTaxRate) {
                $existingTaxRate->rate = $override['rate'];
                $existingTaxRate->save();
            } else {
                TaxRate::query()->create([
                    'country' => $countryCode,
                    'name' => $override['type'],
                    'rate' => $override['rate'],
                    'for_shipping' => $override['for_shipping'] ?? 0,
                    'group' => 'EU',
                    'class_id' => intval($taxClassId),
                    'tax_rate' => $override['rate']
                ]);
            }
        }

        return $this->sendSuccess([
            'message' => __('OSS tax override saved successfully', 'fluent-cart')
        ]);
    }

    public function deleteOssTaxOverride(Request $request)
    {
        $countryCode = $request->getSafe('country', 'sanitize_text_field');
        $state = $request->getSafe('state', 'sanitize_text_field');

        if (!$countryCode) {
            return $this->sendError([
                'message' => __('Country code is required', 'fluent-cart')
            ], 423);
        }

        // select on base of countryCode, state if given and group eu
        $query = TaxRate::query()->where('country', $countryCode)->where('group', 'EU');
        if ($state) {
            $query->where('state', $state);
        }

        $deleted = $query->delete();

        if (!$deleted) {
            return $this->sendError([
                'message' => __('No matching OSS tax override found to delete', 'fluent-cart')
            ], 423);
        }

        return $this->sendSuccess([
            'message' => __('OSS tax override deleted successfully', 'fluent-cart')
        ]);
    }

    public function deleteOssShippingOverride(Request $request)
    {
        $countryCode = $request->getSafe('country', 'sanitize_text_field');
        $state = $request->getSafe('state', 'sanitize_text_field');

        if (!$countryCode) {
            return $this->sendError([
                'message' => __('Country code is required', 'fluent-cart')
            ], 423);
        }

        // select on base of countryCode, state if given and group eu
        $query = TaxRate::query()->where('country', $countryCode)->where('group', 'EU');
        if ($state) {
            $query->where('state', $state);
        }

        $deleted = $query->delete();

        if (!$deleted) {
            return $this->sendError([
                'message' => __('No matching OSS shipping override found to delete', 'fluent-cart')
            ], 423);
        }

        return $this->sendSuccess([
            'message' => __('OSS shipping override deleted successfully', 'fluent-cart')
        ]);
    }
    
    public function euCrossBorderSettings(Request $request)
    {
        $newEuVatSettings = $request->get('eu_vat_settings', []);
        $sanitizedEuVatSettings = array_map('sanitize_text_field', $newEuVatSettings);
        $method = Arr::get($sanitizedEuVatSettings, 'method');
        $errors = [];

        if (!in_array($method, ['oss', 'home', 'specific'], true)) {
            $errors['method'] = __('Select a cross-border registration type', 'fluent-cart');
        } else if ($method === 'oss') {
            if (!Arr::get($sanitizedEuVatSettings, 'oss_country')) {
                $errors['oss_country'] = __('Select country of OSS registration', 'fluent-cart');
            }
        } else if ($method === 'home') {
            if (!Arr::get($sanitizedEuVatSettings, 'home_country')) {
                $errors['home_country'] = __('Select home country of registration', 'fluent-cart');
            }
        }

        if ($errors) {
            return $this->sendError([
                'message' => __('Validation failed for EU VAT settings', 'fluent-cart'),
                'errors'  => $errors
            ], 423);
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
}