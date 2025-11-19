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
                'url'       => $baseUrl . "settings/store-settings/pages_setup"
            ],
            'store_info'   => [
                'title'     => __('Add Details to Store', 'fluent-cart'),
                'text'      => __('Store details such as addresses, company info etc.', 'fluent-cart'),
                'icon'      => 'StoreIcon',
                'completed' => false,
                'url'       => $baseUrl . "settings/store-settings/"
            ],
            'product_info' => [
                'title'     => __('Add Your First Product', 'fluent-cart'),
                'text'      => __('Share your brand story and build trust with customers.', 'fluent-cart'),
                'icon'      => 'ShoppingCartIcon',
                'completed' => false,
                'url'       => $baseUrl . "products"
            ],


            'setup_payments' => [
                'title'     => __('Setup Payment Methods', 'fluent-cart'),
                'text'      => __("Choose from fast & secure online and offline payment.", 'fluent-cart'),
                'icon'      => 'PaymentIcon',
                'completed' => true,
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

        if ($this->isAllPageSetUpDone($settings)) {
            $completed++;
            $steps['page_setup']['completed'] = true;
        }

        if (!$this->isAnyPaymentModuleEnabled()) {
            $steps['setup_payments']['completed'] = false;
        } else {
            $completed++;
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


    private function isAllPageSetUpDone(array $settings): bool
    {
        $pages = (new Pages())->getGeneratablePage();
        foreach ($pages as $pageKey => $page) {
            $pageKey = "{$pageKey}_page_id";
            if (empty(Arr::get($settings, $pageKey))) {
                return false;
            }
        }
        return true;
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
