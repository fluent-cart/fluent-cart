<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * List Item Block Renderer for Email
 *
 * Converts Gutenberg core/list-item blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class ListItemBlock extends BaseBlock
{
    /**
     * Render the list item block
     *
     * @return string Email-compatible HTML (li element, not wrapped in table)
     */
    public function render(): string
    {
        $styles = "margin-bottom: 8px;";

        // Add color styles
        $colorResult = $this->getColorStyles();
        $styles .= $colorResult['styles'];

        // Add typography styles
        $styles .= $this->getTypographyStyles();

        // Extract content from li tags if present
        $innerContent = $this->extractInnerContent($this->innerHTML, 'li');

        // Render nested sub-lists from innerBlocks
        $nestedHtml = '';
        if (!empty($this->innerBlocks)) {
            foreach ($this->innerBlocks as $block) {
                if ($block['blockName'] === 'core/list') {
                    // Render the nested list inline (not wrapped in table)
                    $nestedHtml .= $this->renderNestedList($block);
                }
            }
        }

        return "<li style=\"{$styles}\">{$innerContent}{$nestedHtml}</li>";
    }

    /**
     * Render a nested list block inline (without table wrapper)
     *
     * @param array $block The core/list block data
     * @return string HTML for the nested list
     */
    protected function renderNestedList(array $block): string
    {
        $attrs = $block['attrs'] ?? [];
        $ordered = $attrs['ordered'] ?? false;
        $tag = $ordered ? 'ol' : 'ul';
        $innerBlocks = $block['innerBlocks'] ?? [];

        $listItems = '';
        foreach ($innerBlocks as $itemBlock) {
            if ($itemBlock['blockName'] === 'core/list-item') {
                $listItemBlock = new ListItemBlock(
                    $itemBlock['attrs'] ?? [],
                    $itemBlock['innerHTML'] ?? '',
                    $itemBlock['innerBlocks'] ?? [],
                    $this->parser
                );
                $listItems .= $listItemBlock->render();
            }
        }

        if (empty($listItems)) {
            return '';
        }

        return "<{$tag} style=\"margin: 4px 0 0 0; padding-left: 30px;\">{$listItems}</{$tag}>";
    }
}