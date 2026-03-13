<?php

namespace FluentCart\App\Services\Email\Blocks;

class ConditionContentBlock extends BaseBlock
{
    public function render(): string
    {
        if (empty($this->innerBlocks) || !$this->parser) {
            return '';
        }

        return $this->parser->renderNestedBlocks($this->innerBlocks);
    }
}
