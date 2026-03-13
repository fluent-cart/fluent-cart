<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Code Block Renderer for Email
 *
 * Converts Gutenberg core/code blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class CodeBlock extends BaseBlock
{
    /**
     * Render the code block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        // Base styles - dark theme for code
        $styles = "font-family: 'Courier New', Consolas, Monaco, monospace; background: #282c34; color: #abb2bf; padding: 20px; overflow-x: auto; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; font-size: 14px; line-height: 1.5;";

        // Add color styles (may override defaults)
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

        // Extract content - handle nested pre>code structure
        $content = $this->extractCodeContent();

        return $this->wrapInTable("<pre style=\"{$styles}\"><code>{$content}</code></pre>", 'fluent-code');
    }

    /**
     * Extract code content from innerHTML
     *
     * @return string
     */
    protected function extractCodeContent(): string
    {
        $content = $this->innerHTML;

        // Try to extract from code tag first
        if (preg_match('/<code[^>]*>(.*?)<\/code>/s', $content, $matches)) {
            return $matches[1];
        }

        // Try to extract from pre tag
        if (preg_match('/<pre[^>]*>(.*?)<\/pre>/s', $content, $matches)) {
            return $matches[1];
        }

        return $content;
    }
}