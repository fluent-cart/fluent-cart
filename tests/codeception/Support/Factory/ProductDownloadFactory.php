<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\ProductDownload;
use FluentCart\Framework\Support\Str;

class ProductDownloadFactory
{
    private array $attributes = [];

    private static int $counter = 0;

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    protected function defaults(int $postId): array
    {
        self::$counter++;
        return [
            'post_id'              => $postId,
            'product_variation_id' => [],
            'download_identifier'  => Str::uuid(),
            'title'                => 'Test Download ' . self::$counter,
            'type'                 => 'local',
            'driver'               => 'local',
            'file_name'            => 'test-file-' . self::$counter . '.zip',
            'file_path'            => '/uploads/test-file-' . self::$counter . '.zip',
            'file_url'             => 'http://example.com/test-file-' . self::$counter . '.zip',
            'file_size'            => 1024,
            'settings'             => ['download_limit' => -1, 'download_expiry' => -1],
            'serial'               => self::$counter,
        ];
    }

    public function create(int $postId, array $overrides = []): ProductDownload
    {
        $attributes = array_merge($this->defaults($postId), $this->attributes, $overrides);
        return ProductDownload::query()->create($attributes);
    }
}
