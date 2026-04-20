<?php

namespace FluentCart\App\Modules\Shipping\Http\Controllers;

use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\ShippingClass;
use FluentCart\App\Modules\Shipping\Http\Requests\ShippingClassRequest;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Shipping\Services\Filter\ShippingClassFilter;

class ShippingClassController extends Controller
{
    public function index(Request $request)
    {
        return $this->sendSuccess([
            'shipping_classes' => ShippingClassFilter::fromRequest($request)->paginate()
        ]);
    }

    public function store(ShippingClassRequest $request)
    {
        $data = $request->getSafe($request->sanitize());

        $shippingClass = ShippingClass::create($data);

        return $this->sendSuccess([
            'shipping_class' => $shippingClass,
            'message' => __('Shipping class has been created successfully', 'fluent-cart')
        ]);
    }

    public function show($id)
    {
        $shippingClass = ShippingClass::findOrFail($id);

        return $this->sendSuccess([
            'shipping_class' => $shippingClass
        ]);
    }

    public function update(ShippingClassRequest $request, $id)
    {
        $data = $request->getSafe($request->sanitize());

        $shippingClass = ShippingClass::findOrFail($id);
        $shippingClass->update($data);

        return $this->sendSuccess([
            'shipping_class' => $shippingClass,
            'message' => __('Shipping class has been updated successfully', 'fluent-cart')
        ]);
    }

    public function destroy($id)
    {
        $shippingClass = ShippingClass::findOrFail($id);

        $DB = \FluentCart\App\App::db();
        $DB->beginTransaction();
        try {
            // Cascade delete class-specific zones and their methods
            $zoneIds = \FluentCart\App\Models\ShippingZone::where('shipping_class_id', $id)->pluck('id')->toArray();
            if ($zoneIds) {
                \FluentCart\App\Models\ShippingMethod::whereIn('zone_id', $zoneIds)->delete();
                \FluentCart\App\Models\ShippingZone::whereIn('id', $zoneIds)->delete();
            }

            $shippingClass->delete();
            $DB->commit();
        } catch (\Exception $e) {
            $DB->rollBack();
            return $this->sendError([
                'message' => __('Failed to delete shipping class', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Shipping class has been deleted successfully', 'fluent-cart')
        ]);
    }

    public function getProfile($id)
    {
        $shippingClass = ShippingClass::with('zones.methods')->findOrFail($id);

        return $this->sendSuccess([
            'shipping_class' => $shippingClass
        ]);
    }

    public function getPackages()
    {
        $packages = fluent_cart_get_option('shipping_packages', []);

        return $this->sendSuccess([
            'packages' => $packages ?: []
        ]);
    }

    public function savePackages(Request $request)
    {
        $packages = $request->get('packages', []);

        if (!is_array($packages)) {
            $packages = [];
        }

        $packages = array_slice($packages, 0, 50);

        $validTypes = ['box', 'envelope', 'soft_package'];
        $validDimUnits = ['cm', 'mm', 'in', 'm'];
        $validWeightUnits = ['kg', 'g', 'lbs', 'oz'];

        $sanitized = [];
        foreach ($packages as $package) {
            $type = Arr::get($package, 'type', 'box');
            $sanitized[] = [
                'slug'           => sanitize_title(Arr::get($package, 'slug', Arr::get($package, 'name', ''))) ?: ('package-' . wp_generate_uuid4()),
                'name'           => sanitize_text_field(Arr::get($package, 'name', '')),
                'type'           => in_array($type, $validTypes) ? $type : 'box',
                'length'         => max(0, floatval(Arr::get($package, 'length', 0))),
                'width'          => max(0, floatval(Arr::get($package, 'width', 0))),
                'height'         => $type === 'envelope' ? null : max(0, floatval(Arr::get($package, 'height', 0))),
                'dimension_unit' => in_array(Arr::get($package, 'dimension_unit'), $validDimUnits) ? Arr::get($package, 'dimension_unit') : 'cm',
                'weight'         => max(0, floatval(Arr::get($package, 'weight', 0))),
                'weight_unit'    => in_array(Arr::get($package, 'weight_unit'), $validWeightUnits) ? Arr::get($package, 'weight_unit') : 'kg',
                'is_default'     => (bool) Arr::get($package, 'is_default', false),
            ];
        }

        // Deduplicate slugs server-side
        $usedSlugs = [];
        foreach ($sanitized as &$pkg) {
            $base = $pkg['slug'];
            $counter = 1;
            while (in_array($pkg['slug'], $usedSlugs, true)) {
                $pkg['slug'] = $base . '-' . $counter;
                $counter++;
            }
            $usedSlugs[] = $pkg['slug'];
        }
        unset($pkg);

        fluent_cart_update_option('shipping_packages', $sanitized);

        return $this->sendSuccess([
            'packages' => $sanitized,
            'message'  => __('Packages have been saved successfully', 'fluent-cart')
        ]);
    }
}
