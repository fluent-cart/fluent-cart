<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Email Row Block Renderer (row-header / row-body)
 *
 * Simple pass-through — renders inner blocks. Styling is handled
 * by the inner core/columns block via its own attributes.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class EmailRowBlock extends BaseBlock
{
    /**
     * @var string 'header' or 'body'
     */
    protected $rowType = 'body';

    /**
     * @param string $type 'header' or 'body'
     * @return self
     */
    public function setRowType(string $type): self
    {
        $this->rowType = $type;
        return $this;
    }

    public function render(): string
    {
        return $this->renderInnerContent();
    }

    protected function renderInnerContent(): string
    {
        if (!empty($this->innerBlocks) && $this->parser) {
            return $this->parser->renderNestedBlocks($this->innerBlocks);
        }

        return $this->innerHTML;
    }
}
