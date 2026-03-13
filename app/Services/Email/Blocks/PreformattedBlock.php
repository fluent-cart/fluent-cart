<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Preformatted Block Renderer for Email
 *
 * Converts Gutenberg core/preformatted blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class PreformattedBlock extends BaseBlock
{
    /**
     * Render the preformatted block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        // Base styles
        $styles = "font-family: monospace; background: #f4f4f4; padding: 15px; overflow-x: auto; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word;";

        // Add color styles
        $colorResult = $this->getColorStyles();
        $styles .= $colorResult['styles'];

        // Add typography styles
        $styles .= $this->getTypographyStyles();

        // Add font size preset
        $styles .= $this->getFontSizePresetStyle();

        // Add border styles
        $styles .= $this->getBorderStyles();

        // Add spacing styles
        $styles .= $this->getSpacingStyles('padding');
        $styles .= $this->getSpacingStyles('margin');

        // Extract content from pre tags if present
        $content = $this->extractInnerContent($this->innerHTML, 'pre');

        return $this->wrapInTable("<pre style=\"{$styles}\">{$content}</pre>", 'fluent-preformatted');
    }
}