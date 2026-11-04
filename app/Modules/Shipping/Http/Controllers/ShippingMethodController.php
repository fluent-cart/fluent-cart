<?php

namespace FluentCart\App\Modules\Shipping\Http\Controllers;

use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Shipping\Http\Requests\ShippingMethodRequest;

class ShippingMethodController extends Controller
{

    public function store(ShippingMethodRequest $request): \WP_REST_Response
    {
        $data = $request->getSafe($request->sanitize());
        $data['meta'] = $this->mergeMeta([], Arr::get($data, 'meta'));

        ShippingMethod::create($data);

        return $this->sendSuccess([
            'message' => __('Shipping method has been created successfully', 'fluent-cart')
        ]);
    }

    public function update(ShippingMethodRequest $request): \WP_REST_Response
    {
        $data = $request->getSafe($request->sanitize());
        $methodId = Arr::get($data, 'method_id');

        $method = ShippingMethod::findOrFail($methodId);
        // Merge: the form sends only its own keys, and other code may store more.
        $data['meta'] = $this->mergeMeta((array) $method->meta, Arr::get($data, 'meta'));

        $method->update($data);

        return $this->sendSuccess([
            'message' => __('Shipping method has been updated successfully', 'fluent-cart')
        ]);
    }

    private function mergeMeta(array $stored, $submitted): array
    {
        // An empty value clears its key; readers treat a missing key as empty.
        return array_filter(array_merge($stored, (array) $submitted), function ($value) {
            return $value !== '';
        });
    }

    public function destroy($method_id)
    {
        $method = ShippingMethod::findOrFail($method_id);
        $method->delete();

        return $this->sendSuccess([
            'message' => __('Shipping method has been deleted successfully', 'fluent-cart')
        ]);
    }

}
