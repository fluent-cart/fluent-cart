<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class CheckoutFieldsController extends Controller
{

    public function getFields()
    {
        return [
            'fields'   => CheckoutFieldsSchema::getFieldsSchemaConfig(),
            'settings' => CheckoutFieldsSchema::getFieldsSettings(),
        ];
    }

    public function saveFields(Request $request)
    {
        $settings = $request->get('settings', []);
        $prevSettings = CheckoutFieldsSchema::getFieldsSettings();

        $settings = Arr::only($settings, array_keys($prevSettings));

        $isFirstNameEnabled = Arr::get($settings, 'basic_info.first_name.enabled', 'no') === 'yes';
        $isLastNameEnabled = Arr::get($settings, 'basic_info.last_name.enabled', 'no') === 'yes';
        //if any of first_name and last_name is enabled, we won't show full name
        if ($isFirstNameEnabled || $isLastNameEnabled) {
            Arr::set($settings, 'basic_info.full_name.enabled', 'no');
            Arr::set($settings, 'basic_info.full_name.required', 'no');
        } else {
            //else forcefully enable full_name
            Arr::set($settings, 'basic_info.full_name.enabled', 'yes');
            Arr::set($settings, 'basic_info.full_name.required', 'yes');
        }

        if ($isFirstNameEnabled) {
            Arr::set($settings, 'basic_info.first_name.required', 'yes');
        } else if ($isLastNameEnabled) {
            Arr::set($settings, 'basic_info.last_name.required', 'yes');
        }

        fluent_cart_update_option('_fc_checkout_fields', $settings);

        return [
            'message' => __('Checkout fields has been updated successfully.', 'fluent-cart'),
        ];
    }

}
