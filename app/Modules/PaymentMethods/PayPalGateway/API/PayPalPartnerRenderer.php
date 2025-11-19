<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway\API;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\FrontendView;
use FluentCart\App\Vite;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Support\Arr;

class PayPalPartnerRenderer
{

    protected $mode;

    public function __construct($mode)
    {
        if (!$mode) {
            echo '<div class="fct_message fct_message_error">' . esc_html__('Invalid PayPal payment mode. Please try configuring paypal payment gateway again expected mode test or live !', 'fluent-cart') . '</div>';
            die();
        }

        $this->mode = $mode;
    }

    public function render($data)
    {
        $this->template($data);
        die();
    }

    public function getMode()
    {
        return $this->mode;
    }

    public function scriptJs(): string
    {
        if ($this->mode == 'test') {
            return 'https://www.sandbox.paypal.com/webapps/merchantboarding/js/lib/lightbox/partner.js';
        }
        return 'https://www.paypal.com/webapps/merchantboarding/js/lib/lightbox/partner.js';
    }

    public function template($data)
    {
        $mode = Arr::get($data, 'mode', false);
        if (!$mode) {
            return;
        }
        $paypalPartner = new PayPalPartner($mode);


        try {
            $redirectUrl = $paypalPartner->sellerOnboarding();

            if (is_wp_error($redirectUrl)) {
                FrontendView::renderNotFoundPage(
                    __('Error', 'fluent-cart'),
                    __('Error', 'fluent-cart'),
                    __('Please setup your store country and currency first. ', 'fluent-cart') . '<a href="' . admin_url('admin.php?page=fluent-cart#/settings/store-settings/') . '">' . __('Settings', 'fluent-cart') . '</a>',

                    __('Go Back to the Store', 'fluent-cart'),
                    null,
                    admin_url('admin.php?page=fluent-cart#/settings/payments')
                );
                die();
            }

        } catch (\Exception $exception) {
            FrontendView::renderNotFoundPage(
                __('Unable To connect with PayPal.', 'fluent-cart'),
                __('Please try again in a moment.', 'fluent-cart'), '',
                __('Go Back to the Store', 'fluent-cart'),
                null,
                admin_url('admin.php?page=fluent-cart#/settings/payments')
            );
            die();

        }

        $logo = Vite::getAssetUrl('images/logo/logo-full-dark.svg');
        $baseUrl = admin_url('admin.php?page=fluent-cart#/');


        App::make('view')->render('paypal.authenticate', [
            'url'       => $redirectUrl,
            'scriptJs'  => $this->scriptJs(),
            'mode'      => $mode,
            'rest'      => Helper::getRestInfo(),
            'logo'      => $logo,
            'admin_url' => $baseUrl
        ]);
    }
}
