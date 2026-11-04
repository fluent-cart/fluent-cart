<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\Label;
use FluentCart\App\Models\LabelRelationship;

class LabelFactory
{
    private array $attributes = [];

    private static int $counter = 0;

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    protected function defaults(): array
    {
        self::$counter++;
        return [
            'value' => [
                'title' => 'Test Label ' . self::$counter,
                'color' => '#3b82f6',
            ],
        ];
    }

    public function create(array $overrides = []): Label
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        return Label::query()->create($attributes);
    }

    /**
     * Attach a label to a customer (or any labelable model).
     */
    public function attachTo(int $labelId, int $labelableId, string $labelableType): LabelRelationship
    {
        return LabelRelationship::query()->create([
            'label_id'       => $labelId,
            'labelable_id'   => $labelableId,
            'labelable_type' => $labelableType,
        ]);
    }
}
