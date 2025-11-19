<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Http\Requests\TaxClassRequest;
use FluentCart\App\Http\Requests\TaxRateRequest;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Models\TaxRate;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class TaxRateController extends Controller
{

    public function index(Request $request)
    {
        $taxManager = TaxManager::getInstance();
        $rates = $taxManager->getTaxRates();

        return $this->sendSuccess([
            'tax_rates' => $rates
        ]);
    }

    public function show(Request $request)
    {
        $countryCode = sanitize_text_field($request->get('country_code'));

        // get taxRates by $countryCode
        $taxRates = TaxRate::query()
            ->where('country', $countryCode)
            ->with(['tax_class:id,title'])
            ->orderBy('priority', 'DESC')
            ->get();

        $taxManager = TaxManager::getInstance();
        $settings = $taxManager->getCountryConfiguration($countryCode);

        return $this->sendSuccess([
            'tax_rates' => $taxRates,
            'settings'  => $settings
        ]);
    }

    public function update(TaxRateRequest $request, $id)
    {
        $data = $request->getSafe($request->sanitize());

        $taxRate = TaxRate::query()->findOrFail($id);
        $isUpdated = $taxRate->update($data);

        if (!$isUpdated) {
            return $this->sendError([
                'message' => __('Failed to update tax rate', 'fluent-cart')
            ]);
        }
        $taxClass = TaxClass::query()->select('id', 'title')->find($taxRate->class_id);
        $taxRate->tax_class = [
            'id' => 0,
            'title' => ''
        ];
        if ($taxClass) {
            $taxRate->tax_class = $taxClass;
        }

        return $this->sendSuccess([
            'tax_rate' => $taxRate,
            'message'  => __('Tax rate has been updated successfully', 'fluent-cart')
        ]);
    }

    public function store(TaxRateRequest $request)
    {
        $data = $request->getSafe($request->sanitize());

        $classId = Arr::get($data, 'class_id');

        if (!$classId) {
            return $this->sendError([
                'message' => __('Tax class is required', 'fluent-cart')
            ]);
        }


        $taxRate = TaxRate::query()->create($data);

        if (!$taxRate) {
            return $this->sendError([
                'message' => __('Failed to create tax rate', 'fluent-cart')
            ]);
        }

        $taxClass = TaxClass::query()->select('id', 'title')->find($taxRate->class_id);
        $taxRate->tax_class = [
            'id' => 0,
            'title' => ''
        ];
        if ($taxClass) {
            $taxRate->tax_class = $taxClass;
        }

        return $this->sendSuccess([
            'tax_rate' => $taxRate,
            'message'  => __('Tax rate has been created successfully', 'fluent-cart')
        ]);

    }

    public function delete(Request $request, $id)
    {
        $taxRate = TaxRate::query()->findOrFail($id);
        $isDeleted = $taxRate->delete();

        if (!$isDeleted) {
            return $this->sendError([
                'message' => __('Failed to delete tax rate', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Tax rate has been deleted successfully', 'fluent-cart')
        ]);
    }

    public function deleteCountry(Request $request, $country_code)
    {
        $countryCode = sanitize_text_field($country_code);

        $taxRates = TaxRate::query()->where('country', $countryCode)->delete();

        if (!$taxRates) {
            return $this->sendError([
                'message' => __('Failed to delete country', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Country has been deleted successfully', 'fluent-cart')
        ]);
    }

    public function getCountryTaxId(Request $request, $country_code)
    {
        $countryCode = sanitize_text_field($country_code);
        $taxData = Meta::query()
            ->where('meta_key', 'fluent_cart_tax_id_' . $countryCode)
            ->where('object_type', 'tax')
            ->value('meta_value');

        if (!$taxData) {
            return $this->sendSuccess([
                'tax_data' => [
                    'tax_id' => ''
                ]
            ]);
        }

        return $this->sendSuccess([
            'tax_data' => $taxData
        ]);
    }

    public function saveCountryTaxId(Request $request, $country_code)
    {
        $countryCode = sanitize_text_field($country_code);
        $taxId = sanitize_text_field($request->get('tax_id'));

        $data = [
            'tax_id' => $taxId
        ];

        // save taxId to fct_meta
        $meta = Meta::query()
            ->where('meta_key', 'fluent_cart_tax_id_' . $countryCode)
            ->where('object_type', 'tax')
            ->first();

        if ($meta) {
            $meta->meta_value = $data;
            $meta->save();
        } else {
            Meta::query()->create([
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'    => 'fluent_cart_tax_id_' . $countryCode,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'  => [
                    'tax_id' => $taxId
                ],
                'object_type' => 'tax'
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Tax ID has been saved successfully', 'fluent-cart')
        ]);

    }

    public function deleteShippingOverride(Request $request, $id)
    {
        $taxRate = TaxRate::query()->findOrFail($id);
        $taxRate->for_shipping = null;
        $taxRate->save();

        return $this->sendSuccess([
            'message' => __('Shipping override has been deleted successfully', 'fluent-cart')
        ]);
    }
    

    public function saveShippingOverride(Request $request)
    {
        $taxRate = TaxRate::query()->findOrFail($request->getSafe('id', 'intval'));

        if (!$taxRate) {
            return $this->sendError([
                'message' => __('Tax rate not found', 'fluent-cart')
            ]);
        }

        $taxRate->for_shipping = $request->getSafe('override_tax_rate', 'intval');
        $taxRate->save();

        return $this->sendSuccess([
            'message' => __('Tax override has been saved successfully', 'fluent-cart')
        ]);
    }

    public function addCountry(Request $request)
    {
        $countryCode = sanitize_text_field($request->get('country'));
        $taxClassId = intval($request->get('class_id'));
        if (!$countryCode) {
            return $this->sendError([
                'message' => __('Country code is required', 'fluent-cart')
            ]);
        }

        if (!$taxClassId) {
            return $this->sendError([
                'message' => __('Tax class is required', 'fluent-cart')
            ]);
        }

        $taxClass = TaxClass::query()->select('id')->find($taxClassId);
        if (!$taxClass) {
            return $this->sendError([
                'message' => __('Tax class not found', 'fluent-cart')
            ]);
        }

        $localization = App::localization();
        $continent = $localization->continentFromCountry($countryCode);

        $taxRate = TaxRate::query()->create([
            'country' => $countryCode,
            'group'   => $continent,
            'class_id' => $taxClass->id,
        ]);

        if (!$taxRate) {
            return $this->sendError([
                'message' => __('Failed to add country', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Country has been added successfully', 'fluent-cart')
        ]);
    }

}
