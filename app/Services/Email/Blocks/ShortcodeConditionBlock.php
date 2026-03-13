<?php

namespace FluentCart\App\Services\Email\Blocks;

use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;

class ShortcodeConditionBlock extends BaseBlock
{
    protected $parserData = [];

    public function setParserData(array &$data): self
    {
        $this->parserData = &$data;
        return $this;
    }

    public function render(): string
    {
        $resolved = static::resolveConditionParams(
            Arr::get($this->attrs, 'preset', ''),
            Arr::get($this->attrs, 'shortcode', ''),
            Arr::get($this->attrs, 'condition', 'not_empty'),
            Arr::get($this->attrs, 'compareValue', '')
        );

        if (!$resolved) {
            return $this->renderSlot('fluent-cart/condition-fallback');
        }

        $result = static::evaluateResolved($resolved, $this->parserData, Arr::get($this->attrs, 'preset', ''), $this->attrs);

        return $this->renderSlot($result ? 'fluent-cart/condition-content' : 'fluent-cart/condition-fallback');
    }

    private function renderSlot(string $blockName): string
    {
        $blocks = [];
        foreach ($this->innerBlocks as $block) {
            if (isset($block['blockName']) && $block['blockName'] === $blockName) {
                $blocks[] = $block;
            }
        }

        if (empty($blocks) || !$this->parser) {
            return '';
        }

        return $this->parser->renderNestedBlocks($blocks);
    }
}
