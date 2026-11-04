<?php

namespace Tests\Support\Factory;

class ProductTermFactory
{
    private array $attributes = [];

    private static int $counter = 0;

    public const TAXONOMY_CATEGORY = 'product-categories';
    public const TAXONOMY_BRAND    = 'product-brands';

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    protected function defaults(string $taxonomy): array
    {
        self::$counter++;
        return [
            'name'        => 'Term ' . self::$counter,
            'slug'        => 'term-' . self::$counter . '-' . wp_generate_password(6, false, false),
            'description' => '',
            'parent'      => 0,
            'taxonomy'    => $taxonomy,
        ];
    }

    public function create(string $taxonomy, array $overrides = []): array
    {
        $data = array_merge($this->defaults($taxonomy), $this->attributes, $overrides);
        $taxonomy = $data['taxonomy'];
        unset($data['taxonomy']);

        $args = array_filter([
            'slug'        => $data['slug'] ?? null,
            'description' => $data['description'] ?? null,
            'parent'      => $data['parent'] ?? null,
        ], function ($v) {
            return $v !== null && $v !== '';
        });

        $result = wp_insert_term($data['name'], $taxonomy, $args);

        if (is_wp_error($result)) {
            throw new \RuntimeException(
                "Failed to insert term '{$data['name']}' into '{$taxonomy}': " . $result->get_error_message()
            );
        }

        return [
            'term_id'          => (int) $result['term_id'],
            'term_taxonomy_id' => (int) $result['term_taxonomy_id'],
            'taxonomy'         => $taxonomy,
            'name'             => $data['name'],
            'slug'             => $data['slug'],
        ];
    }

    public function attachToProduct(int $productId, string $taxonomy, array $termIds, bool $append = false): void
    {
        $result = wp_set_object_terms($productId, $termIds, $taxonomy, $append);
        if (is_wp_error($result)) {
            throw new \RuntimeException(
                "Failed to attach terms to product {$productId}: " . $result->get_error_message()
            );
        }
    }

    public function createCategory(array $overrides = []): array
    {
        return $this->create(self::TAXONOMY_CATEGORY, $overrides);
    }

    public function createBrand(array $overrides = []): array
    {
        return $this->create(self::TAXONOMY_BRAND, $overrides);
    }
}
