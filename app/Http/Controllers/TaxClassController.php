<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\App\Modules\Tax\TaxModule;
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
        })->values()->map(function ($taxClass) {
            $taxClass->categories = Arr::get($taxClass->meta, 'categories', []);
            return $taxClass;
        });

        return $this->sendSuccess([
            'tax_classes' => $taxClasses
        ]);
    }

    public function store(TaxClassRequest $request)
    {

        $data = $request->getSafe($request->sanitize());

        $taxClassData = [
            'title' => Arr::get($data, 'title'),
            'description' => Arr::get($data, 'description', ''),
            'meta' => [
                'categories' => Arr::get($data, 'categories', []),
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
            'message' => __('Tax class has been created successfully', 'fluent-cart')
        ]);

    }

    public function checkAndCreateInitialTaxClasses()
    {
        if (get_option('fluent_cart_has_tax_configure', false)) {
            return;
        }

        $taxClasses = [
            [
                'title' => __('Standard', 'fluent-cart'),
                'slug' => 'standard',
                'description' => __('Standard tax rate for most products', 'fluent-cart'),
                'meta' => [
                    'categories' => [],
                    'priority' => 10,
                ]
            ],
            [
                'title' => __('Reduced', 'fluent-cart'),
                'slug' => 'reduced',
                'description' => __('Reduced tax rate for essential goods', 'fluent-cart'),
                'meta' => [
                    'categories' => [],
                    'priority' => 5,
                ]
            ],
            [
                'title' => __('Zero', 'fluent-cart'),
                'slug' => 'zero',
                'description' => __('Zero tax rate for exempt products', 'fluent-cart'),
                'meta' => [
                    'categories' => [],
                    'priority' => 2,
                ]
            ]
        ];

       foreach ($taxClasses as $taxClass) {
        $taxClass = TaxClass::query()->firstOrCreate(
            ['slug' => $taxClass['slug']],
            [
                'title' => $taxClass['title'],
                'description' => $taxClass['description'],
                'meta' => $taxClass['meta']
            ]
        );
       }

       update_option('fluent_cart_has_tax_configure', true);
    }

    public function update(TaxClassRequest $request, $id)
    {
        $data = $request->getSafe($request->sanitize());
        $taxClass = TaxClass::query()->findOrFail($id);

        $taxClassData = [
            'title' => Arr::get($data, 'title'),
            'description' => Arr::get($data, 'description', ''),
            'meta' => [
                'categories' => Arr::get($data, 'categories', []),
                'priority' => Arr::get($data, 'priority', 0),
            ]
        ];

        $isUpdated = $taxClass->update($taxClassData);

        if (!$isUpdated) {
            return $this->sendError([
                'message' => __('Failed to update tax class', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Tax class has been updated successfully', 'fluent-cart')
        ]);
    }

    

    public function delete(Request $request, $id)
    {
        $taxClass = TaxClass::query()->findOrFail($id);
        $isDeleted = $taxClass->delete();

        if (!$isDeleted) {
            return $this->sendError([
                'message' => __('Failed to delete tax class', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Tax class has been deleted successfully', 'fluent-cart')
        ]);
    }

}
