<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\Api\Contracts\CanEnqueue;
use FluentCart\App\App;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

abstract class ShortCode
{
    use CanEnqueue;

    protected static string $shortCodeName;
    protected ?array $shortCodeAttributes = null;
    protected ?string $slugPrefix = null;

    public function __construct(array $shortcodeAttributes = [])
    {
        $this->shortCodeAttributes = $this->parseAttribute($shortcodeAttributes);
        $this->slugPrefix = App::config()->get('app.slug');
    }

    abstract public function render(?array $viewData = null);


    public function renderShortcode($block = null)
    {
        $this->enqueueAssets();
        ob_start(null);
        $view = $this->render(
            $this->viewData()
        );
        return $view ?? ob_get_clean();
    }

    public function parseAttribute(array $shortcodeAttributes): array
    {
        return $shortcodeAttributes;
    }

    public function viewData(): ?array
    {
        return $this->shortCodeAttributes;
    }

    public static function getShortCodeName(): string
    {
        return static::$shortCodeName;
    }

    public static function register()
    {
        add_shortcode(static::getShortCodeName(), function ($shortcodeAttributes, $content, $block) {
            return static::make($shortcodeAttributes)->renderShortcode($block);
        });
    }

    protected function generateEnqueueSlug(): string
    {
        return Str::of(
            $this->slugPrefix . '_' . static::getShortCodeName()
        )->snake('')->replace('-', '_')->toString();
    }

    public static function make($shortcodeAttributes = null): ShortCode
    {
        return new static(
            Arr::wrap($shortcodeAttributes)
        );
    }
}
