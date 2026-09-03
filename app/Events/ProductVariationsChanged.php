<?php

namespace FluentCart\App\Events;

use FluentCart\App\Listeners\UpdateDefaultVariation;

/**
 * Fired when a product's variation set changes in a way that can invalidate its
 * default_variation_id — combinations regenerated/removed, or a variant set
 * active/inactive. Distinct from StockChanged (which is broad and currently
 * carries no default listener) so this stays scoped to variant-set mutations.
 * UpdateDefaultVariation re-resolves the default to the first purchasable
 * combination; works for every variation type.
 */
class ProductVariationsChanged extends EventDispatcher
{
    public string $hook = 'fluent_cart/product_variations_changed';

    protected array $listeners = [
        UpdateDefaultVariation::class,
    ];

    /**
     * @var array $postIds
     */
    public $postIds;

    public function __construct($postIds)
    {
        $this->postIds = $postIds;
    }

    public function toArray(): array
    {
        return [
            'post_ids' => $this->postIds,
        ];
    }

    public function getActivityEventModel()
    {
        return null;
    }

    public function shouldCreateActivity(): bool
    {
        return false;
    }
}
