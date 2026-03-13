<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Columns Block Renderer for Email
 *
 * Converts Gutenberg core/columns blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class ColumnsBlock extends BaseBlock
{
    /**
     * Render the columns block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        if (empty($this->innerBlocks)) {
            return '';
        }

        $columnCount = count($this->innerBlocks);
        $columnWidth = floor(100 / $columnCount);

        $colorResult = $this->getColorStyles();
        $styles = $colorResult['styles'];
        $styles .= $this->getBorderStyles();
        $styles .= $this->getSpacingStyles('padding');
        $styles .= $this->getSpacingStyles('margin');

        // Column gap from block attributes (style.spacing.blockGap)
        $blockGapRaw = $this->style['spacing']['blockGap'] ?? '0';
        // Handle object format {left: "...", top: "..."} — use left for horizontal gap
        if (is_array($blockGapRaw)) {
            $blockGapRaw = $blockGapRaw['left'] ?? ($blockGapRaw['top'] ?? '0');
        }
        // Resolve preset spacing values (e.g., "var:preset|spacing|40")
        $blockGap = $this->resolveSpacingValue($blockGapRaw);
        $gapValue = (float) $blockGap;
        $gapUnit = preg_replace('/[\d.]+/', '', $blockGap) ?: 'px';
        $halfGap = $gapValue / 2;

        // Read parent-level vertical alignment default
        $parentVerticalAlignment = $this->attrs['verticalAlignment'] ?? '';

        $html = '<table role="presentation" class="fluent-columns" width="100%" cellspacing="0" cellpadding="0" border="0" style="' . trim($styles) . '"><tr>';

        foreach ($this->innerBlocks as $index => $column) {
            // Get column width from attrs if specified
            $width = $column['attrs']['width'] ?? $columnWidth . '%';
            if (is_numeric($width)) {
                $width = $width . '%';
            }

            $columnBlock = new ColumnBlock(
                $column['attrs'] ?? [],
                $column['innerHTML'] ?? '',
                $column['innerBlocks'] ?? [],
                $this->parser
            );

            // Apply half gap as padding between columns, none on outer edges
            $tdPadding = '';
            if ($halfGap > 0) {
                if ($index > 0) {
                    $tdPadding .= " padding-left: {$halfGap}{$gapUnit};";
                }
                if ($index < $columnCount - 1) {
                    $tdPadding .= " padding-right: {$halfGap}{$gapUnit};";
                }
            }

            $columnStyles = $columnBlock->getTdStyles();
            $vAlign = $columnBlock->getVerticalAlignment($parentVerticalAlignment);
            $html .= '<td width="' . $width . '" style="vertical-align: ' . $vAlign . ';' . $columnStyles . $tdPadding . '">';
            $html .= $columnBlock->render();
            $html .= '</td>';
        }

        $html .= '</tr></table>';

        return $html;
    }
}