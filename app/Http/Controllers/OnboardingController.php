<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Invokable\DummyProduct;
use FluentCart\Api\StoreSettings;
use FluentCart\App\CPT\Pages;
use FluentCart\App\Helpers\Helper as HelperService;
use FluentCart\App\Http\Requests\CreatePageRequest;
use FluentCart\App\Models\Meta;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Framework\Foundation\Async;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class OnboardingController extends Controller
{
    public function index(Request $request): \WP_REST_Response
    {
        $defaultSettings = (new StoreSettings())->toArray();

        $pages = new Pages();

        foreach ($pages->getGeneratablePage(true) as $pageName => $page) {

            if (empty(Arr::get($defaultSettings, "{$pageName}_page_id"))) {
                Arr::set(
                    $defaultSettings,
                    "{$pageName}_page_id",
                    Arr::get($page, 'page_id')
                );
            }

        }

        $taxSettings   = (new TaxModule())->getSettings();
        $euVatSettings = Arr::get($taxSettings, 'eu_vat_settings', []);
        $euMethod      = Arr::get($euVatSettings, 'method', 'oss');
        $storeCountry  = strtoupper(Arr::get($defaultSettings, 'store_country', ''));
        $isEuCountry   = !empty($storeCountry) && (new LocalizationManager())->isEuTaxCountry($storeCountry);

        if ($isEuCountry) {
            $vatNumber = $euMethod === 'oss'
                ? Arr::get($euVatSettings, 'oss_vat', '')
                : Arr::get($euVatSettings, 'home_vat', '');
        } elseif (!empty($storeCountry)) {
            $nonEuMeta = Meta::query()
                ->where('meta_key', 'fluent_cart_tax_id_' . $storeCountry)
                ->where('object_type', 'tax')
                ->first();
            $vatNumber = $nonEuMeta ? Arr::get((array) $nonEuMeta->meta_value, 'tax_id', '') : '';
        } else {
            $vatNumber = '';
        }

        return $this->response->sendSuccess([
            'pages'            => Pages::getPages('', true),
            'currencies'       => CurrencySettings::getFormattedCurrencies(),
            'default_settings' => $defaultSettings,
            'tax_settings'     => [
                'enable_tax'    => Arr::get($taxSettings, 'enable_tax', 'no'),
                'tax_inclusion' => Arr::get($taxSettings, 'tax_inclusion', 'excluded'),
                'eu_method'     => $euMethod,
                'vat_number'    => $vatNumber,
            ],
        ]);
    }

    public function createPages(Request $request): \WP_REST_Response
    {
        $excluded = [];
        $pages = new Pages();
        $storeSettings = new StoreSettings();

        foreach ($pages->getGeneratablePage() as $pageName => $page) {
            $pageId = $storeSettings->get("{$pageName}_page_id");
            if (!empty($pageId) && !Pages::isPage($pageId)) {
                $excluded[] = $pageName;
            }
        }

        $pages->createPages($excluded);

        return $this->index($request);
    }

    public function createPage(CreatePageRequest $request): \WP_REST_Response
    {
        $content = sanitize_text_field($request->get('content'));
        $pageKey = $content;
        $content = Str::of($content)->replaceFirst('_page_id', '')->toString();
        $saveSettings = filter_var($request->get('save_settings'), FILTER_VALIDATE_BOOLEAN);

        $generateablePages = (new Pages())->getGeneratablePage();
        $pageData = Arr::get($generateablePages, $content, null);

        if (!empty($pageData)) {
            $page = [
                'post_type'    => 'page',
                'post_title'   => sanitize_text_field($request->get('page_name')),
                'post_content' => $pageData['content'],
                'post_status'  => 'publish'
            ];

            $pageId = (string)wp_insert_post($page);

            if ($saveSettings) {
                (new StoreSettings())->save([
                    $pageKey => $pageId
                ]);

                flush_rewrite_rules(true);
                delete_option('rewrite_rules');
            }

            return $this->response->sendSuccess([
                'page_id'   => $pageId,
                'page_name' => $pageData['title'],
                'link'      => get_page_link($pageId)
            ]);
        }

        return $this->response->sendError([
            'message' => __('Unable to create page', 'fluent-cart')
        ]);
    }

    public function saveTaxSettings(Request $request): \WP_REST_Response
    {
        $enableTax = sanitize_text_field($request->get('enable_tax', 'no'));

        if (!in_array($enableTax, ['yes', 'no'], true)) {
            return $this->response->sendError([
                'message' => __('Invalid enable_tax value', 'fluent-cart')
            ], 422);
        }

        if ($enableTax === 'no') {
            $currentSettings               = (new TaxModule())->getSettings();
            $currentSettings['enable_tax'] = 'no';
            update_option('fluent_cart_tax_configuration_settings', $currentSettings, true);

            return $this->response->sendSuccess([
                'data'    => [
                    'tax_enabled'       => false,
                    'classes_created'   => false,
                    'eu_rates_imported' => false,
                ],
                'message' => __('Tax settings saved', 'fluent-cart')
            ]);
        }

        $taxInclusion = sanitize_text_field($request->get('tax_inclusion', 'excluded'));
        $storeCountry = strtoupper(sanitize_text_field($request->get('store_country', '')));

        if (!empty($storeCountry) && (!ctype_alpha($storeCountry) || strlen($storeCountry) > 3)) {
            return $this->response->sendError([
                'message' => __('Invalid store_country value', 'fluent-cart')
            ], 422);
        }

        $localization = new LocalizationManager();
        $isEuCountry  = !empty($storeCountry) && $localization->isEuTaxCountry($storeCountry);
        $euMethod     = sanitize_text_field($request->get('eu_method', 'oss'));
        $vatNumber    = substr(sanitize_text_field($request->get('vat_number', '')), 0, 50);

        if (!in_array($taxInclusion, ['included', 'excluded'], true)) {
            return $this->response->sendError([
                'message' => __('Invalid tax_inclusion value', 'fluent-cart')
            ], 422);
        }

        if ($isEuCountry && !in_array($euMethod, ['oss', 'home', 'specific'], true)) {
            return $this->response->sendError([
                'message' => __('Invalid eu_method value', 'fluent-cart')
            ], 422);
        }

        $currentSettings               = (new TaxModule())->getSettings();
        $currentSettings['enable_tax'] = 'yes';
        $currentSettings['tax_inclusion'] = $taxInclusion;

        $classesCreated  = true;
        $euRatesImported = false;
        $taxManager      = TaxManager::getInstance();

        if ($isEuCountry) {
            $euVatSettings = Arr::get($currentSettings, 'eu_vat_settings', []);
            $euVatSettings['method'] = $euMethod;
            if ($euMethod === 'oss') {
                $euVatSettings['oss_vat']     = $vatNumber;
                $euVatSettings['oss_country'] = $storeCountry;
            } else {
                // home and specific both register the home country VAT
                $euVatSettings['home_vat']     = $vatNumber;
                $euVatSettings['home_country'] = $storeCountry;
            }

            // Upsert country_registrations so the VAT number appears in Tax → EU VAT settings
            $rawRates     = $taxManager->getEuTaxRatesFromPhp($storeCountry);
            $ratesMap     = [];
            $standardRate = 0.0;
            foreach ($rawRates as $rateEntry) {
                $typeKey = isset($rateEntry['type']) ? $rateEntry['type'] : '';
                if (!$typeKey) {
                    continue;
                }
                $ratesMap[$typeKey] = [
                    'rate'  => (float) $rateEntry['rate'],
                    'label' => '',
                ];
                if ($typeKey === 'standard') {
                    $standardRate = (float) $rateEntry['rate'];
                }
            }

            if (!empty($ratesMap)) {
                $taxManager->saveEuVatRegistration($storeCountry, [
                    'country'   => $storeCountry,
                    'vat'       => $vatNumber,
                    'rate'      => $standardRate,
                    'rates'     => $ratesMap,
                    'tax_label' => '',
                ]);
            }

            $currentSettings['eu_vat_settings'] = $euVatSettings;
        }

        // Direct update_option used here intentionally — TaxModule::getSettings() caches its result
        // on the instance, so going through the module would return stale data on the same request.
        update_option('fluent_cart_tax_configuration_settings', $currentSettings, true);

        $euCountries = Arr::get($localization->taxContinents('EU'), 'countries', []);

        try {
            if ($isEuCountry) {
                $taxManager->generateTaxClasses($euCountries);
                $taxManager->setTaxEnabledForCountry($storeCountry, true);
                $euRatesImported = true;
            } else {
                $taxManager->generateTaxClasses(!empty($storeCountry) ? [$storeCountry] : []);
                if (!empty($storeCountry)) {
                    $taxManager->setTaxEnabledForCountry($storeCountry, true);
                }
            }
        } catch (\Exception $e) {
            $classesCreated = false;
        }

        if (!$isEuCountry && !empty($vatNumber) && !empty($storeCountry)) {
            $meta = Meta::query()
                ->where('meta_key', 'fluent_cart_tax_id_' . $storeCountry)
                ->where('object_type', 'tax')
                ->first();

            if ($meta) {
                $meta->meta_value = ['tax_id' => $vatNumber];
                $meta->save();
            } else {
                Meta::query()->create([
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_key'    => 'fluent_cart_tax_id_' . $storeCountry,
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    'meta_value'  => ['tax_id' => $vatNumber],
                    'object_type' => 'tax'
                ]);
            }
        }

        return $this->response->sendSuccess([
            'data'    => [
                'tax_enabled'       => true,
                'classes_created'   => $classesCreated,
                'eu_rates_imported' => $euRatesImported,
            ],
            'message' => __('Tax settings saved', 'fluent-cart')
        ]);
    }

    public function saveSettings(Request $request)
    {
        $settings = array_merge((new StoreSettings())->toArray(), $request->all());

        $savedStoreSettings = (new StoreSettings())->save(
            Arr::except($settings, 'category')
        );

        if ($category = $request->get('category')) {
            Async::call(DummyProduct::class, ['category' => $category]);
        }

        if ($savedStoreSettings) {
            return $this->response->sendSuccess([
                'message' => __('Store has been updated successfully', 'fluent-cart')
            ]);
        }

        return $this->response->sendError([
            'errors' => __('Failed to update!', 'fluent-cart')
        ], 400);
    }
}
