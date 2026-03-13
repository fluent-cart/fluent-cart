<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Heading Block Renderer for Email
 *
 * Converts Gutenberg core/heading blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class HeadingBlock extends BaseBlock
{
    /**
     * Default font sizes for heading levels
     *
     * @var array
     */
    protected $defaultFontSizes = [
        1 => '32px',
        2 => '28px',
        3 => '24px',
        4 => '20px',
        5 => '18px',
        6 => '16px',
    ];

    /**
     * Render the heading block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $level = $this->attrs['level'] ?? 2;
        $align = $this->attrs['textAlign'] ?? ($this->attrs['align'] ?? 'left');

        // Get default font size for this heading level
        $defaultFontSize = $this->defaultFontSizes[$level] ?? '24px';

        // Build base styles
        $baseStyles = "margin: 0 0 16px 0; padding: 0; font-weight: bold;";
        $baseStyles .= " font-size: {$defaultFontSize}; line-height: 1.3;";
        $baseStyles .= " text-align: {$align};";

        // Build all styles using parent methods
        $styleResult = $this->buildStyles($baseStyles);
        $styles = $styleResult['styles'];
        $textColor = $styleResult['textColor'];

        // Handle font size preset (overrides default)
        $styles .= $this->getFontSizePresetStyle();

        // Handle gradient text effect
        if (!empty($this->style['color']['gradient'])) {
            $styles .= " -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;";
        }

        // Handle custom CSS
        if (isset($this->style['css'])) {
            $styles .= " {$this->style['css']};";
        }

        // Extract content from heading tags
        $innerContent = $this->extractInnerContent($this->innerHTML, 'h[1-6]');

        // Apply link color styles
        $innerContent = $this->applyLinkColorStyles($innerContent, null, $textColor);

        return $this->wrapInTable("<h{$level} style=\"{$styles}\">{$innerContent}</h{$level}>", 'fluent-heading');
    }
}