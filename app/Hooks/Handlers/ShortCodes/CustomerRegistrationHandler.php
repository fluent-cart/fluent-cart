<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;


use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\Helper;

class CustomerRegistrationHandler extends ShortCode
{
    const SHORT_CODE = 'fluent_cart_registration_form';
    protected static string $shortCodeName = 'fluent_cart_registration_form';


    public static function register()
    {
        parent::register();

        add_action('wp_enqueue_scripts', function () {
            if (App::request()->get('action') === 'elementor') {
                return;
            }

            if (is_page() && is_main_query()) {
                $page_id = get_queried_object_id();
                $storePageId = (new StoreSettings())->getRegistrationPageId();
                if ($page_id == $storePageId) {
                    (new static())->enqueueStyles();
                }
                return;
            }
            if (has_shortcode(get_the_content(), static::SHORT_CODE) || has_block('fluent-cart/registration-form')) {
                (new static())->enqueueStyles();
            }
        }, 10);
    }

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'public/checkout/registration.js',
                'dependencies' => [],
                'inFooter'     => true
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            [
                'source' => 'public/checkout/style/registration.scss',
            ]
        ];
    }

    protected function localizeData(): array
    {
        return [
            'fluentcart_checkout_info' => [
                'rest'    => Helper::getRestInfo(),
            ]
        ];
    }

    public function viewData(): ?array
    {
        return [
            'checkout' => CartCheckoutHelper::make()
        ];
    }

    public function render(?array $viewData = null)
    {
        ob_start();
        do_action('fluent_cart/views/checkout_page_registration_form', $viewData);
        return ob_get_clean();
    }

}
