<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Framework\Http\Request\Request;

class TaxConfigurationController extends Controller
{
    public function getTaxRates()
    {
        $taxManager = TaxManager::getInstance();
        $rates = $taxManager->getTaxRatesFromTaxPhp();

        return $this->sendSuccess([
            'tax_rates' => $rates
        ]);
    }

    public function saveConfiguredCountries(Request $request)
    {
        $countryCodes = $request->get('countries', []);
        $taxManager = TaxManager::getInstance();
        $taxManager->generateTaxClasses($countryCodes);

        return $this->sendSuccess([
            'message' => __('Countries saved successfully', 'fluent-cart')
        ]);
    }

    public function getSettings()
    {
       $settings = (new TaxModule())->getSettings();

        return $this->sendSuccess([
            'settings' => $settings
        ]);
    }

    public function saveSettings(Request $request)
    {
        $settings = $request->get('settings', $this->defaultSettings());

        foreach ($settings as $key => $value) {
            if ($key === 'eu_vat_settings' && is_array($value)) {
                // Sanitize nested eu_vat_settings
                $eu = $value;
                foreach ($eu as $ek => $ev) {
                    if ($ek === 'vat_reverse_excluded_categories') {
                        $cats = is_array($ev) ? $ev : [];
                        $eu[$ek] = array_values(array_filter(array_map('intval', $cats), function ($v) { return $v > 0; }));
                    } else {
                        $eu[$ek] = is_array($ev) ? $ev : sanitize_text_field($ev);
                    }
                }
                $settings[$key] = $eu;
            } else if (is_array($value)) {
                $settings[$key] = array_map('sanitize_text_field', $value);
            } else {
                $settings[$key] = sanitize_text_field($value);
            }
        }

        // save settings to wp_options
        update_option('fluent_cart_tax_configuration_settings', $settings, true);

        if (Arr::get($settings, 'enable_tax') === 'yes') {
            (new TaxClassController())->checkAndCreateInitialTaxClasses();
        }

        return $this->sendSuccess([
            'message' => __('Settings saved successfully', 'fluent-cart')
        ]);
    }

    private function defaultSettings()
    {
        return [
            'tax_inclusion'         => 'included',
            'tax_calculation_basis' => 'shipping',
            'tax_rounding'          => 'item',
            'enable_tax'            => 'no',
            'eu_vat_settings'       => [
                'require_vat_number' => 'no',
                'local_reverse_charge' => 'yes',
                'vat_reverse_excluded_categories' => [],
                'country_wise_vat' => []
            ]
        ];
    }

}

