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

        fluent_cart_update_option('_fc_checkout_fields', $settings);

        return [
            'message' => __('Checkout fields has been updated successfully.', 'fluent-cart'),
        ];
    }

}
