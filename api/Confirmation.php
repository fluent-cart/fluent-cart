<?php

namespace FluentCart\Api;

use FluentCart\App\App;
use FluentCart\App\CPT\Pages;
use FluentCart\App\Helpers\Helper as HelperService;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class Confirmation
{
    /**
     * @var string
     * 
     * Confirmation settings option name
     */
    protected $confirmationHandler = 'fluent_cart_confirmation_settings';

    /**
     * @var array key value pair
     * 
     * Confirmation settings parsed from fields
     */
    protected $confirmationSettings;

    public function __construct()
    {
        $defaults = static::fields();

        $template = "fluent_cart_order_created_template";
        ob_start();
        $defaultBannerImage = Vite::getAssetUrl('images/email-template/email-banner.png');

        App::make('view')->render('emails.' . $template, [
            'default_banner_image' => $defaultBannerImage,
        ]);
        $view = ob_get_clean();


        $defaultSettings = [
            'confirmation_type' => 'same_page',
            'message_to_show' => $view
        ];

        foreach (array_keys($defaults) as $index => $key) {
            $defaultSettings[$key] = $defaults[$key]['value'];
        }

        $settings = fluent_cart_get_option($this->confirmationHandler, []);

        $this->confirmationSettings = wp_parse_args($settings, $defaultSettings);

    }

    /**
     * @return array
     * 
     * Get all confirmation settings fields
     * @hook to use apply_filters("fluent_cart/confirmation_setting_fields", $fields)
     */
    public static function fields()
    {
        $pages = Pages::getPages('');

        $fields = [
            "confirmation_page_id" => [
                "label" => __('Select custom page', 'fluent-cart'),
                "type" => "select",
                "options" => $pages,
                "value" => "",
                "note" => \FluentCart\App\Helpers\Helper::getShortcodeInstructionString('[fluent_cart_receipt]')
            ],
        ];

        return apply_filters("fluent_cart/confirmation_setting_fields", $fields, []);
    }

    /**
     * @return array
     * 
     * @param string $key like stripe or PayPal
     * All confirmation settings if key is not provided
     */
    public function get($key = null, $default = null)
    {
        if (!$key) {
            return array_merge(
                $this->confirmationSettings,
                $default??[],
            );
        }

        return Arr::get($this->confirmationSettings, $key, $default);
    }


    /**
     * @return boolean
     *
     * @param array $settings like stripe or PayPal settings array
     * Save confirmation settings
     */
    public function save(array $settings)
    {
        if (isset($settings['message_to_show'])) {
            $settings['message_to_show'] = wp_kses_post($settings['message_to_show']);
        }
        $settings = Helper::sanitize($settings, static::fields());
        (new StoreSettings())->set('receipt_page_id', Arr::get($settings,'confirmation_page_id'));
        unset($settings['confirmation_page_id']);
        return fluent_cart_update_option($this->confirmationHandler, $settings);
    }

    public function getContent()
    {
        return $this->confirmationSettings['message_to_show'];
    }
   
}
