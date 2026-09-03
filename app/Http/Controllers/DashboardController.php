<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\StoreSettings;
use FluentCart\App\CPT\Pages;
use FluentCart\App\Hooks\Handlers\GlobalPaymentHandler;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Widgets\DashboardWidget;
use FluentCart\Framework\Support\Arr;

class DashboardController extends Controller
{
    public function getOnboardingData()
    {
        $completed = 0;
        $baseUrl = apply_filters('fluent_cart/admin_base_url', admin_url('admin.php?page=fluent-cart#/'), []);
        $steps = [
            'page_setup'   => [
                'title'     => __('Setup Pages', 'fluent-cart'),
                'text'      => __("Customers to find what they're looking for by organising.", 'fluent-cart'),
                'icon'      => 'Cart',
                'completed' => false,
                'hash_id'   => '',
                'url'       => $baseUrl . "settings/store-settings/pages_setup"
            ],
            'store_info'   => [
                'title'     => __('Add Details to Store', 'fluent-cart'),
                'text'      => __('Store details such as addresses, company info etc.', 'fluent-cart'),
                'icon'      => 'StoreIcon',
                'completed' => false,
                'hash_id'   => '',
                'url'       => $baseUrl . "settings/store-settings"
            ],
            'product_info' => [
                'title'     => __('Add Your First Product', 'fluent-cart'),
                'text'      => __('Share your brand story and build trust with customers.', 'fluent-cart'),
                'icon'      => 'ShoppingCartIcon',
                'completed' => false,
                'hash_id'   => '',
                'url'       => $baseUrl . "products"
            ],


            'setup_payments' => [
                'title'     => __('Setup Payment Methods', 'fluent-cart'),
                'text'      => __("Choose from fast & secure online and offline payment.", 'fluent-cart'),
                'icon'      => 'PaymentIcon',
                'completed' => true,
                'hash_id'   => '',
                'url'       => $baseUrl . "settings/payments"
            ],
        ];

        $settings = (new StoreSettings)->get();

        if ($this->isStoreInfoProvided($settings)) {
            $completed++;
            $steps['store_info']['completed'] = true;
        }

        if ($this->isProductInfoProvided()) {
            $completed++;
            $steps['product_info']['completed'] = true;
        }

        $missingPage = $this->getMissingPageSetup($settings);

        if (!$missingPage) {
            $completed++;
            $steps['page_setup']['completed'] = true;
        } else {
            // Let whichever plugin registered the missing page (via
            // fluent_cart/generatable_pages) decide where it should be
            // resolved. Core stays agnostic of third-party settings screens.
            $steps['page_setup']['url'] = apply_filters(
                'fluent_cart/dashboard/page_setup_redirect_url',
                $steps['page_setup']['url'],
                [
                    'missing_page' => $missingPage,
                    'base_url'     => $baseUrl,
                ]
            );
        }

        if (!$this->isAnyPaymentModuleEnabled()) {
            $steps['setup_payments']['completed'] = false;
        } else {
            $completed++;
        }

        if (defined('BRICKS_VERSION')) {
            $steps['install_bricks_addon'] = [
                'title'     => __('Install Bricks Addon', 'fluent-cart'),
                'text'      => __('Design your store pages with FluentCart elements in Bricks.', 'fluent-cart'),
                'icon'      => 'AppsLine',
                'completed' => false,
                'hash_id'   => 'fluent-cart-bricks-blocks',
                'url'       => $baseUrl . "settings/addons"
            ];

            if (defined('FLUENT_CART_BRICKS_BLOCKS_VERSION')) {
                $steps['install_bricks_addon']['completed'] = true;
                $completed++;
            }
        }

        if (defined('ELEMENTOR_VERSION')) {
            $steps['install_elementor_addon'] = [
                'title'     => __('Install Elementor Addon', 'fluent-cart'),
                'text'      => __('Design your store pages with FluentCart widgets in Elementor.', 'fluent-cart'),
                'icon'      => 'AppsLine',
                'completed' => false,
                'hash_id'   => 'fluent-cart-elementor-blocks',
                'url'       => $baseUrl . "settings/addons"
            ];

            if (defined('FLUENTCART_ELEMENTOR_BLOCKS_VERSION')) {
                $steps['install_elementor_addon']['completed'] = true;
                $completed++;
            }
        }

        if (strpos(get_template(), 'Divi') !== false) {
            $steps['install_divi_addon'] = [
                'title'     => __('Install Divi Addon', 'fluent-cart'),
                'text'      => __('Design your store pages with FluentCart modules in Divi.', 'fluent-cart'),
                'icon'      => 'AppsLine',
                'completed' => false,
                'hash_id'   => 'fluent-cart-divi-modules',
                'url'       => $baseUrl . "settings/addons"
            ];

            // FLUENTCART_DIVI_BLOCKS_VERSION covers installs from before the slug rename
            if (defined('FLUENTCART_DIVI_MODULES_VERSION') || defined('FLUENTCART_DIVI_BLOCKS_VERSION')) {
                $steps['install_divi_addon']['completed'] = true;
                $completed++;
            }
        }

        return $this->response->json([
            'data' => [
                'steps'     => $steps,
                'completed' => $completed
            ]
        ]);
    }

    protected function isStoreInfoProvided(array $settings): bool
    {
        $storeName = Arr::get($settings, 'store_name');
        $storeLogo = Arr::get($settings, 'store_logo');
        return !(
            //$storeName === 'Fluent Cart Shop' ||
            empty($storeName) ||
            empty($storeLogo)
        );
    }

    protected function isProductInfoProvided(): bool
    {
        return Product::query()->count() > 0;
    }


    private function getMissingPageSetup(array $settings): ?array
    {
        $pagesInstance = new Pages();
        $pages = $pagesInstance->getGeneratablePage();

        // Core pages must be checked first regardless of the order a
        // fluent_cart/generatable_pages listener leaves the filtered array
        // in, so an add-on can never take priority over a missing core page.
        $orderedKeys = array_unique(array_merge(
            array_keys($pagesInstance->corePages()),
            array_keys($pages)
        ));

        foreach ($orderedKeys as $pageKey) {
            if (!isset($pages[$pageKey])) {
                continue;
            }

            $settingKey = "{$pageKey}_page_id";
            if (empty(Arr::get($settings, $settingKey))) {
                return array_merge($pages[$pageKey], [
                    'key'         => $pageKey,
                    'setting_key' => $settingKey,
                ]);
            }
        }

        return null;
    }

    private function isAnyPaymentModuleEnabled(): bool
    {
        $gateways = (new GlobalPaymentHandler())->getAll();
        foreach ($gateways as $gateway) {
            if ($gateway['status']) {
                return true;
            }
        }
        return false;
    }

    public function getDashboardStats(): \WP_REST_Response
    {
        return $this->sendSuccess([
            'stats' => DashboardWidget::widgets()
        ]);
    }
}
