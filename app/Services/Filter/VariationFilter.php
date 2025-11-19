<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\Product;
use FluentCart\Framework\Database\Orm\Builder;

class VariationFilter extends BaseFilter
{

    public function applySimpleFilter()
    {

        $this->query = $this->query->when($this->search, function ($query, $search) {
            return $query->where('post_title', 'LIKE', "%{$search}%")
                ->orWhereHas('variants', function (Builder $query) use ($search) {
                    $query->where('variation_title', 'LIKE', "%{$search}%");
                });
        });
    }

    protected function applyMustLoadIds()
    {
        $this->query = $this->query->orWhereIn('ID', $this->includeIds)
            ->orWhereHas('variants', function (Builder $query) {
                $query->orWhereIn('id', $this->includeIds);
            });
    }

    public function tabsMap(): array
    {
        return [
            'publish'           => 'post_status',
            'simple'            => 'variation_type',
            'simple_variations' => 'variation_type',
            'physical'          => 'fulfillment_type',
            'digital'           => 'fulfillment_type',
        ];
    }

    public function getModel(): string
    {
        return Product::class;
    }

    public static function getFilterName(): string
    {
        return 'product_variation';
    }

    public function applyActiveViewFilter()
    {

    }

    public static function getTreeFilterOptions(array $args): array
    {

        return static::make($args)->get()->map(function ($product) {
            return [
                'value'    => $product->ID,
                'label'    => $product->post_title,
                'children' => $product->variants->map(function ($variation) {
                    return [
                        'value' => $variation->id,
                        'label' => $variation->variation_title,
                    ];
                })->toArray()
            ];
        })->toArray();
    }
}