<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Spacer Block Renderer for Email
 *
 * Converts Gutenberg core/spacer blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class SpacerBlock extends BaseBlock
{
    /**
     * Render the spacer block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $height = $this->attrs['height'] ?? 50;

        // Handle if height is a string with unit
        if (is_string($height) && !preg_match('/^\d+$/', $height)) {
            // Already has unit
            $heightStyle = $height;
        } else {
            // Add px unit
            $heightStyle = "{$height}px";
        }

        return $this->wrapInTable("<div style=\"height: {$heightStyle};\"></div>", 'fluent-spacer');
    }
}