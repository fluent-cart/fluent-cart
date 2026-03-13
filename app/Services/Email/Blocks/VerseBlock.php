<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Verse Block Renderer for Email
 *
 * Converts Gutenberg core/verse blocks to email-compatible HTML.
 * Used for poetry and other content where line breaks are significant.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class VerseBlock extends BaseBlock
{
    /**
     * Render the verse block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        // Base styles - preserves whitespace, uses serif font
        $styles = "font-family: Georgia, 'Times New Roman', serif; white-space: pre-wrap; margin: 20px 0; padding: 20px; background: #f9f9f9; line-height: 1.8;";

        // Add color styles
        $colorResult = $this->getColorStyles();
        $styles .= $colorResult['styles'];

        // Add typography styles
        $styles .= $this->getTypographyStyles();

        // Add font size preset
        $styles .= $this->getFontSizePresetStyle();

        // Add alignment
        $styles .= $this->getAlignmentStyles();

        // Add border styles
        $styles .= $this->getBorderStyles();

        // Add spacing styles
        $styles .= $this->getSpacingStyles('padding');
        $styles .= $this->getSpacingStyles('margin');

        // Extract content from pre tags if present
        $content = $this->extractVerseContent();

        return $this->wrapInTable("<pre style=\"{$styles}\">{$content}</pre>", 'fluent-verse');
    }

    /**
     * Extract verse content from innerHTML
     *
     * @return string
     */
    protected function extractVerseContent(): string
    {
        // Try to extract from pre tag
        if (preg_match('/<pre[^>]*>(.*?)<\/pre>/s', $this->innerHTML, $matches)) {
            return $matches[1];
        }

        return $this->innerHTML;
    }
}