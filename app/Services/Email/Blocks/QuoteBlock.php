<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Quote Block Renderer for Email
 *
 * Converts Gutenberg core/quote blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class QuoteBlock extends BaseBlock
{
    /**
     * Render the quote block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        // Base styles for blockquote
        $styles = "margin: 20px 0; padding: 15px 20px; border-left: 4px solid #ccc;";
        $styles .= " background-color: #f9f9f9; font-style: italic;";

        // Add color styles
        $colorResult = $this->getColorStyles();
        $styles .= $colorResult['styles'];

        // Add typography styles
        $styles .= $this->getTypographyStyles();

        // Add font size preset
        $styles .= $this->getFontSizePresetStyle();

        // Add border styles (may override default border-left)
        $styles .= $this->getBorderStyles();

        // Add spacing
        $styles .= $this->getSpacingStyles('padding');
        $styles .= $this->getSpacingStyles('margin');

        // Extract content — strip outer <blockquote> to avoid double nesting
        $content = $this->extractQuoteContent();
        $citation = $this->extractCitation();

        // Apply link colors
        $content = $this->applyLinkColorStyles($content, null, $colorResult['textColor']);

        $html = "<blockquote style=\"{$styles}\">{$content}";

        if (!empty($citation)) {
            $html .= "<cite style=\"display: block; margin-top: 15px; font-size: 14px; font-style: normal; color: #666;\">{$citation}</cite>";
        }

        $html .= "</blockquote>";

        return $this->wrapInTable($html, 'fluent-quote');
    }

    /**
     * Extract quote content from innerHTML, stripping outer blockquote wrapper
     *
     * @return string
     */
    protected function extractQuoteContent(): string
    {
        $content = $this->innerHTML;

        // Strip outer <blockquote> to avoid nesting blockquote inside blockquote
        if (preg_match('/<blockquote[^>]*>(.*)<\/blockquote>/s', $content, $matches)) {
            $content = $matches[1];
        }

        // Remove citation — we render it separately
        $content = preg_replace('/<cite[^>]*>.*?<\/cite>/s', '', $content);

        return trim($content);
    }

    /**
     * Extract citation from innerHTML or attrs
     *
     * @return string
     */
    protected function extractCitation(): string
    {
        // Check innerHTML for <cite> tag
        if (preg_match('/<cite[^>]*>(.*?)<\/cite>/s', $this->innerHTML, $matches)) {
            return trim(strip_tags($matches[1], '<strong><em><a>'));
        }

        // Check attrs for citation
        if (!empty($this->attrs['citation'])) {
            return $this->attrs['citation'];
        }

        return '';
    }
}