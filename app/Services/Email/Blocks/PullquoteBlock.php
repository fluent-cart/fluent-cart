<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Pullquote Block Renderer for Email
 *
 * Converts Gutenberg core/pullquote blocks to email-compatible HTML.
 * Pullquotes are more prominent than regular quotes, typically used to highlight key text.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class PullquoteBlock extends BaseBlock
{
    /**
     * Render the pullquote block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        // Base styles - prominent centered quote with borders
        $styles = "margin: 30px 0; padding: 30px; border-top: 4px solid #000; border-bottom: 4px solid #000; text-align: center; font-size: 20px; font-style: italic;";

        // Add color styles
        $colorResult = $this->getColorStyles();
        $styles .= $colorResult['styles'];

        // Handle border color from attributes
        if (isset($this->attrs['borderColor'])) {
            $borderColor = $this->getColorFromSlug($this->attrs['borderColor']);
            $styles .= " border-color: {$borderColor};";
        }

        // Add typography styles
        $styles .= $this->getTypographyStyles();

        // Add font size preset
        $styles .= $this->getFontSizePresetStyle();

        // Add custom border styles (may override defaults)
        $styles .= $this->getBorderStyles();

        // Add spacing styles
        $styles .= $this->getSpacingStyles('padding');
        $styles .= $this->getSpacingStyles('margin');

        // Extract content and citation
        $quoteContent = $this->extractQuoteContent();
        $citation = $this->extractCitation();

        $html = "<blockquote style=\"{$styles}\">{$quoteContent}";

        if (!empty($citation)) {
            $html .= "<cite style=\"display: block; margin-top: 15px; font-size: 14px; font-style: normal; color: #666;\">{$citation}</cite>";
        }

        $html .= "</blockquote>";

        return $this->wrapInTable($html, 'fluent-pullquote');
    }

    /**
     * Extract quote content from innerHTML
     *
     * @return string
     */
    protected function extractQuoteContent(): string
    {
        // Try to extract p tag content within blockquote
        if (preg_match('/<blockquote[^>]*>.*?<p[^>]*>(.*?)<\/p>/s', $this->innerHTML, $matches)) {
            return $matches[1];
        }

        // Try to extract blockquote content
        if (preg_match('/<blockquote[^>]*>(.*?)<\/blockquote>/s', $this->innerHTML, $matches)) {
            // Remove citation if present
            $content = preg_replace('/<cite[^>]*>.*?<\/cite>/s', '', $matches[1]);
            return trim(strip_tags($content, '<strong><em><a><br>'));
        }

        return $this->innerHTML;
    }

    /**
     * Extract citation from innerHTML
     *
     * @return string
     */
    protected function extractCitation(): string
    {
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