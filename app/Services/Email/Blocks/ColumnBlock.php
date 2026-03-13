<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Column Block Renderer for Email
 *
 * Converts Gutenberg core/column blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class ColumnBlock extends BaseBlock
{
    /**
     * Get CSS styles for the column's wrapping <td> element.
     *
     * @return string Inline CSS styles string
     */
    public function getTdStyles(): string
    {
        $result = $this->buildStyles('', false, true, true, true, false);
        return $result['styles'];
    }

    /**
     * Get vertical alignment for this column, falling back to parent default
     *
     * @param string $parentDefault Parent columns' verticalAlignment
     * @return string CSS vertical-align value (top, middle, bottom)
     */
    public function getVerticalAlignment(string $parentDefault = ''): string
    {
        $align = $this->attrs['verticalAlignment'] ?? $parentDefault;

        $map = [
            'top'    => 'top',
            'center' => 'middle',
            'bottom' => 'bottom',
        ];

        return $map[$align] ?? 'top';
    }

    /**
     * Render the column block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        if (!empty($this->innerBlocks) && $this->parser) {
            return $this->parser->renderNestedBlocks($this->innerBlocks);
        }

        return $this->innerHTML;
    }
}