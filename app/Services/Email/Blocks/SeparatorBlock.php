<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Separator Block Renderer for Email
 *
 * Converts Gutenberg core/separator blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class SeparatorBlock extends BaseBlock
{
    /**
     * Render the separator block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $className = $this->attrs['className'] ?? '';

        // Resolve color
        $color = '#ccc';
        if (isset($this->attrs['backgroundColor'])) {
            $color = $this->getColorFromSlug($this->attrs['backgroundColor']);
        }
        if (!empty($this->style['color']['background'])) {
            $color = $this->getColorFromSlug($this->style['color']['background']);
        }

        $spacingStyles = $this->getSpacingStyles('margin');

        // Dots variant
        if (strpos($className, 'is-style-dots') !== false) {
            $dotsStyle = "margin: 30px auto; text-align: center; font-size: 24px; letter-spacing: 1em; color: {$color}; line-height: 1; border: none;";
            $dotsStyle .= $spacingStyles;
            return $this->wrapInTable("<p style=\"{$dotsStyle}\">&#183;&#183;&#183;</p>", 'fluent-separator');
        }

        // Wide variant: full width
        if (strpos($className, 'is-style-wide') !== false) {
            $styles = "border: none; border-top: 1px solid {$color}; margin: 30px 0; width: 100%;";
            $styles .= $spacingStyles;
            return $this->wrapInTable("<hr style=\"{$styles}\" />", 'fluent-separator');
        }

        // Default variant: narrow centered
        $styles = "border: none; border-top: 1px solid {$color}; margin: 30px auto; max-width: 100px;";
        $styles .= $spacingStyles;

        return $this->wrapInTable("<hr style=\"{$styles}\" />", 'fluent-separator');
    }
}