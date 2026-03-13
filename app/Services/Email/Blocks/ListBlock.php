<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * List Block Renderer for Email
 *
 * Converts Gutenberg core/list blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class ListBlock extends BaseBlock
{
    /**
     * Render the list block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $ordered = $this->attrs['ordered'] ?? false;
        $tag = $ordered ? 'ol' : 'ul';

        $styles = "margin: 0 0 16px 0; padding-left: 30px; line-height: 1.6;";

        // Override defaults with custom spacing when set
        $customPadding = $this->getSpacingStyles('padding');
        if (!empty($customPadding)) {
            $styles = "line-height: 1.6;" . $customPadding;
        }
        $customMargin = $this->getSpacingStyles('margin');
        if (!empty($customMargin)) {
            // Remove default margin, apply custom
            $styles = preg_replace('/margin:[^;]+;/', '', $styles);
            $styles .= $customMargin;
        }

        // Add color styles
        $colorResult = $this->getColorStyles();
        $styles .= $colorResult['styles'];

        // Add typography styles
        $styles .= $this->getTypographyStyles();

        // Add font size preset
        $styles .= $this->getFontSizePresetStyle();

        // Render from innerBlocks if available
        if (!empty($this->innerBlocks)) {
            $listItems = '';
            foreach ($this->innerBlocks as $block) {
                if ($block['blockName'] === 'core/list-item') {
                    $listItemBlock = new ListItemBlock(
                        $block['attrs'] ?? [],
                        $block['innerHTML'] ?? '',
                        $block['innerBlocks'] ?? [],
                        $this->parser
                    );
                    $listItems .= $listItemBlock->render();
                }
            }
            return $this->wrapInTable("<{$tag} style=\"{$styles}\">{$listItems}</{$tag}>", 'fluent-list');
        }

        // Otherwise use innerHTML
        $innerContent = $this->extractListContent();

        return $this->wrapInTable("<{$tag} style=\"{$styles}\">{$innerContent}</{$tag}>", 'fluent-list');
    }

    /**
     * Extract list content from innerHTML
     *
     * @return string
     */
    protected function extractListContent(): string
    {
        if (preg_match('/<(ul|ol)[^>]*>(.*?)<\/(ul|ol)>/s', $this->innerHTML, $matches)) {
            return $matches[2];
        }

        return $this->innerHTML;
    }
}