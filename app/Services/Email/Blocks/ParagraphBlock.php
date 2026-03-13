<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Paragraph Block Renderer for Email
 *
 * Converts Gutenberg core/paragraph blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class ParagraphBlock extends BaseBlock
{
    /**
     * Render the paragraph block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $content = trim($this->innerHTML);

        // Extract content if wrapped in <p> tags
        $innerContent = $this->extractInnerContent($content, 'p');

        // Build paragraph element styles
        $paragraphStyles = "display: block; font-size: inherit; line-height: inherit;";

        // Check if block has background or border (needs default padding like Gutenberg's .has-background)
        $hasBackground = isset($this->attrs['backgroundColor'])
            || !empty($this->style['color']['background'])
            || !empty($this->style['color']['gradient']);
        $hasBorder = !empty($this->style['border']) || isset($this->attrs['borderColor']);
        $hasExplicitPadding = !empty($this->style['spacing']['padding']);

        // Build wrapper styles
        if (($hasBackground || $hasBorder) && !$hasExplicitPadding) {
            $wrapperStyles = "padding: 1.25em 2.375em;";
        } else {
            $wrapperStyles = "";
        }

        // Get text color for element
        $textColor = $this->getTextColor();
        if ($textColor) {
            $paragraphStyles .= " color: {$textColor};";
        }

        // Add typography styles to paragraph
        $paragraphStyles .= $this->getTypographyStyles();

        // Add font size preset
        $paragraphStyles .= $this->getFontSizePresetStyle();

        // Add alignment
        $paragraphStyles .= $this->getAlignmentStyles();

        // Add margin to paragraph
        $paragraphStyles .= $this->getSpacingStyles('margin');

        // Add wrapper styles (background, border, padding)
        $wrapperStyles .= $this->getWrapperColorStyles();
        $wrapperStyles .= $this->getBorderStyles();
        $wrapperStyles .= $this->getSpacingStyles('padding');

        // Apply link color styles
        $innerContent = $this->applyLinkColorStyles($innerContent, null, $textColor);

        return "<table role=\"presentation\" class=\"fluent-paragraph\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" style=\"border-spacing:0; border-collapse:collapse;\">
    <tr>
        <td style=\"{$wrapperStyles}\">
            <p style=\"{$paragraphStyles}\">{$innerContent}</p>
        </td>
    </tr>
</table>";
    }

    /**
     * Get text color from attributes
     *
     * @return string|null
     */
    protected function getTextColor(): ?string
    {
        if (isset($this->attrs['textColor'])) {
            return $this->getColorFromSlug($this->attrs['textColor']);
        }

        if (!empty($this->style['color']['text'])) {
            return $this->getColorFromSlug($this->style['color']['text']);
        }

        return null;
    }

    /**
     * Get wrapper color styles (background, gradient)
     *
     * @return string
     */
    protected function getWrapperColorStyles(): string
    {
        $styles = '';

        if (isset($this->attrs['backgroundColor'])) {
            $styles .= " background-color: {$this->getColorFromSlug($this->attrs['backgroundColor'])};";
        }

        if (!empty($this->style['color']['background'])) {
            $styles .= " background-color: {$this->getColorFromSlug($this->style['color']['background'])};";
        }

        if (!empty($this->style['color']['gradient'])) {
            $styles .= " background: {$this->style['color']['gradient']};";
        }

        return $styles;
    }
}
