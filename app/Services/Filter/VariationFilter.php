<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\Product;
use FluentCart\Framework\Database\Orm\Builder;

class VariationFilter extends BaseFilter
{

    public function applySimpleFilter(?string $search = null): void
    {

        $this->query = $this->query->when($search ?? $this->search, function ($query, $search) {
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

    /**
     * Intentionally empty. This filter cannot receive a request `with` today:
     * its only caller is AdvanceFilterController::getFilterOption(), which
     * hand-builds a five-key argument array (remote_data_key, search,
     * include_ids, limit, parent_id) and never forwards the raw request.
     *
     * That is the reason the map is empty, NOT an oversight — if this filter is
     * ever refactored to take `$request->all()`, an empty map is what keeps it
     * closed, and any relation it then needs must be added here deliberately as
     * a context key mapped to a callable.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [];
    }

    public function applyActiveViewFilter(?string $activeView = null): void
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