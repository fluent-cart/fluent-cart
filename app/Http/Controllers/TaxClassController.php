<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Requests\TaxClassRequest;

class TaxClassController extends Controller
{

    public function index(Request $request)
    {
        $taxClasses = TaxClass::query()->get();

        $taxClasses = $taxClasses->sort(function ($a, $b) {
            $aPriority = (int) Arr::get($a->meta, 'priority', 0);
            $bPriority = (int) Arr::get($b->meta, 'priority', 0);
            if ($aPriority === $bPriority) {
                return $b->id <=> $a->id; // newer first when priority equal
            }
            return $bPriority <=> $aPriority;
        })->values();

        return $this->sendSuccess([
            'tax_classes' => $taxClasses
        ]);
    }

    public function store(TaxClassRequest $request)
    {

        $data = $request->getSafe($request->sanitize());

        $taxClassData = [
            'title' => Arr::get($data, 'title'),
            'meta' => [
                'priority' => Arr::get($data, 'priority', 0),
            ]
        ];

        $taxClass = TaxClass::create($taxClassData);

        if (is_wp_error($taxClass)) {
            return $this->sendError([
                'message' => $taxClass->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Tax profile has been created successfully', 'fluent-cart')
        ]);

    }

    public function checkAndCreateInitialTaxClasses()
    {
        if (get_option('fluent_cart_has_tax_configure', false)) {
            return;
        }

        TaxClass::query()->firstOrCreate(
            ['slug' => 'standard'],
            [
                'title' => __('Standard', 'fluent-cart'),
            ]
        );

        update_option('fluent_cart_has_tax_configure', true);
    }

    public function update(TaxClassRequest $request, $id)
    {
        $data = $request->getSafe($request->sanitize());
        $taxClass = TaxClass::query()->findOrFail($id);

        $taxClassData = [
            'title' => Arr::get($data, 'title'),
            'meta' => [
                'priority' => Arr::get($data, 'priority', 0),
            ]
        ];

        $isUpdated = $taxClass->update($taxClassData);

        if (!$isUpdated) {
            return $this->sendError([
                'message' => __('Failed to update tax profile', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Tax profile has been updated successfully', 'fluent-cart')
        ]);
    }

    

    public function delete(Request $request, $id)
    {
        $taxClass = TaxClass::query()->findOrFail($id);

        if ($taxClass->slug === 'standard') {
            return $this->sendError([
                'message' => __('Cannot delete the Standard tax class', 'fluent-cart')
            ], 423);
        }

        $isDeleted = $taxClass->delete();

        if (!$isDeleted) {
            return $this->sendError([
                'message' => __('Failed to delete tax profile', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Tax profile has been deleted successfully', 'fluent-cart')
        ]);
    }

}
