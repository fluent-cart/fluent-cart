<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\TaxRate;
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
        $countryCodes = array_values(array_filter(
            array_map('sanitize_text_field', (array) $request->get('countries', []))
        ));
        $taxManager = TaxManager::getInstance();
        $taxManager->generateTaxClasses($countryCodes);

        return $this->sendSuccess([
            'message' => __('Countries saved successfully', 'fluent-cart')
        ]);
    }

    public function getSettings()
    {
       $settings = (new TaxModule())->getSettings();
       $storeSettings = new StoreSettings();

        return $this->sendSuccess([
            'settings'      => $settings,
            'store_country' => $storeSettings->get('store_country') ?: '',
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
                    } elseif ($ek === 'reverse_charge_price_mode') {
                        $sanitized = sanitize_text_field($ev);
                        $eu[$ek] = in_array($sanitized, ['fixed', 'dynamic'], true) ? $sanitized : 'fixed';
                    } elseif (is_array($ev)) {
                        $eu[$ek] = self::sanitizeNestedArray($ev);
                    } else {
                        $eu[$ek] = sanitize_text_field($ev);
                    }
                }
                $settings[$key] = $eu;
            } else if (is_array($value)) {
                $settings[$key] = array_map('sanitize_text_field', $value);
            } else {
                $settings[$key] = sanitize_text_field($value);
            }
        }

        $enumDefaults = [
            'tax_inclusion'         => ['included', 'excluded'],
            'tax_calculation_basis' => ['shipping', 'billing', 'store'],
            'tax_rounding'          => ['item', 'total', 'subtotal'],
            'checkout_tax_breakdown_display' => ['itemized', 'simplified'],
            'enable_tax'            => ['yes', 'no'],
        ];
        foreach ($enumDefaults as $key => $allowed) {
            if (isset($settings[$key]) && !in_array($settings[$key], $allowed, true)) {
                $settings[$key] = $allowed[0];
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

    private static function sanitizeNestedArray(array $data)
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeNestedArray($value);
            } else if (is_numeric($value)) {
                $sanitized[$key] = $value + 0;
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }
        return $sanitized;
    }

    private function defaultSettings()
    {
        return [
            'tax_inclusion'          => 'included',
            'tax_calculation_basis'  => 'shipping',
            'tax_rounding'           => 'item',
            'checkout_tax_breakdown_display' => 'itemized',
            'tax_display_label'      => 'Tax',
            'enable_tax'             => 'no',
            'price_suffix_included'  => '',
            'price_suffix_excluded'  => '',
            'eu_vat_settings'        => [
                'require_vat_number' => 'no',
                'local_reverse_charge' => 'yes',
                'reverse_charge_price_mode' => 'fixed',
                'vat_reverse_excluded_categories' => [],
                'country_wise_vat' => []
            ]
        ];
    }

}
