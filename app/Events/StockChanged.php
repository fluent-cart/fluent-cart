<?php

namespace FluentCart\App\Events;

use FluentCart\App\Listeners\UpdateDefaultVariation;

class StockChanged extends EventDispatcher
{
    public string $hook = 'fluent_cart/product_stock_changed';
    protected array $listeners = [
        //UpdateDefaultVariation::class
    ];

    /**
     * @var $postIds array
     */
    public $postIds;

    /**
     * @var $otherInfo array|null
     */
    public ?array $otherInfo;

    public function __construct($postIds, $otherInfo = null)
    {
        $this->postIds = $postIds;
        $this->otherInfo = $otherInfo;
    }

    public function toArray(): array
    {
        return [
            'post_ids'   => $this->postIds,
            'other_info' => $this->otherInfo,
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