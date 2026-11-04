<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\ProductMeta;

class ProductMetaFactory
{
    private array $attributes = [];

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    protected function defaults(int $objectId): array
    {
        return [
            'object_id'   => $objectId,
            'object_type' => null,
            'meta_key'    => 'meta_key_' . $objectId,
            'meta_value'  => [],
        ];
    }

    public function create(int $objectId, array $overrides = []): ProductMeta
    {
        $data = array_merge($this->defaults($objectId), $this->attributes, $overrides);
        return ProductMeta::query()->create($data);
    }

    public function createIntegrationFeed(int $productId, string $integrationName, array $settings = [], bool $enabled = true): ProductMeta
    {
        return $this->create($productId, [
            'object_type' => 'product_integration',
            'meta_key'    => $integrationName,
            'meta_value'  => array_merge([
                'name'         => $integrationName,
                'enabled'      => $enabled ? 'yes' : 'no',
                'integration'  => $integrationName,
            ], $settings),
        ]);
    }

    public function createLicenseSettings(int $productId, array $settings = []): ProductMeta
    {
        return $this->create($productId, [
            'object_type' => null,
            'meta_key'    => 'license_settings',
            'meta_value'  => array_merge([
                'enable_licensing' => 'yes',
            ], $settings),
        ]);
    }
}
