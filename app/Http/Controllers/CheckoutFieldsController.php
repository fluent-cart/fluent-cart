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
            'address_field_order' => [
                'billing_address'  => CheckoutFieldsSchema::getAddressFieldOrder('billing_address'),
                'shipping_address' => CheckoutFieldsSchema::getAddressFieldOrder('shipping_address'),
            ],
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

    public function saveFieldOrder(Request $request)
    {
        $section = sanitize_key($request->get('section', ''));
        if (!in_array($section, ['billing_address', 'shipping_address'], true)) {
            return $this->sendError([
                'message' => __('Invalid address section.', 'fluent-cart'),
            ], 422);
        }

        $settings = CheckoutFieldsSchema::getFieldsSettings();
        $order = CheckoutFieldsSchema::sanitizeAddressFieldOrder([$section => $request->get('order', [])], $settings);

        update_option(CheckoutFieldsSchema::addressFieldOrderKey($section), $order[$section]);

        return [
            'message' => __('Field order has been saved.', 'fluent-cart'),
            'order'   => $order[$section],
        ];
    }

}
