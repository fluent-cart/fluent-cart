<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Cover Block Renderer for Email
 *
 * Converts Gutenberg core/cover blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class CoverBlock extends BaseBlock
{
    /**
     * Render the cover block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $url = $this->getBackgroundUrl();
        $dimRatio = $this->attrs['dimRatio'] ?? 50;
        $contentPosition = $this->attrs['contentPosition'] ?? 'center center';
        $minHeight = $this->attrs['minHeight'] ?? '';
        $minHeightUnit = $this->attrs['minHeightUnit'] ?? 'px';

        $opacity = $dimRatio / 100;

        // Resolve overlay color
        $overlayColor = $this->getOverlayColor();
        $overlayRgba = $this->hexToRgba($overlayColor, $opacity);

        // Parse content position
        list($vAlignStyle, $textAlign) = $this->parseContentPosition($contentPosition);

        // Determine minimum height
        $minHeightValue = $minHeight ? $minHeight . $minHeightUnit : '300px';

        // Render inner content
        $innerContent = $this->renderInnerContent();

        if ($url) {
            return $this->renderWithBackground($url, $overlayRgba, $vAlignStyle, $textAlign, $minHeightValue, $innerContent);
        }

        return $this->wrapInTable($innerContent, 'fluent-cover');
    }

    /**
     * Get background URL from attrs or innerHTML
     *
     * @return string
     */
    protected function getBackgroundUrl(): string
    {
        if (!empty($this->attrs['url'])) {
            return $this->attrs['url'];
        }

        if (!empty($this->innerHTML) && preg_match('/src=["\']([^"\']+)["\']/', $this->innerHTML, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Parse content position into vertical align and text align
     *
     * @param string $contentPosition Position string (e.g., "top center")
     * @return array [vAlignStyle, textAlign]
     */
    protected function parseContentPosition(string $contentPosition): array
    {
        $verticalAlign = 'center';
        $textAlign = 'center';

        if (!empty($contentPosition)) {
            $positions = explode(' ', $contentPosition);
            if (count($positions) >= 2) {
                $verticalAlign = $positions[0];
                $textAlign = $positions[1];
            } elseif (count($positions) === 1) {
                $verticalAlign = $positions[0];
            }
        }

        // Map to CSS values
        $vAlignMap = ['top' => 'top', 'center' => 'middle', 'bottom' => 'bottom'];
        $vAlignStyle = $vAlignMap[$verticalAlign] ?? 'middle';

        return [$vAlignStyle, $textAlign];
    }

    /**
     * Render inner content from blocks
     *
     * @return string
     */
    protected function renderInnerContent(): string
    {
        if (empty($this->innerBlocks) || !$this->parser) {
            return '';
        }

        $content = '';
        foreach ($this->innerBlocks as $block) {
            $content .= $this->parser->renderNestedBlock($block);
        }

        return $content;
    }

    /**
     * Render cover with background image
     *
     * @param string $url Background URL
     * @param string $overlayRgba Overlay rgba color string
     * @param string $vAlignStyle Vertical alignment
     * @param string $textAlign Text alignment
     * @param string $minHeightValue Minimum height
     * @param string $innerContent Inner content HTML
     * @return string
     */
    protected function renderWithBackground(
        string $url,
        string $overlayRgba,
        string $vAlignStyle,
        string $textAlign,
        string $minHeightValue,
        string $innerContent
    ): string {
        // Base styles with defaults
        $tableMargin = 'margin: 20px 0;';
        $tdPadding = 'padding: 40px 20px;';

        // Override with custom spacing when set
        $customMargin = $this->getSpacingStyles('margin');
        if (!empty($customMargin)) {
            $tableMargin = trim($customMargin) . ';';
        }
        $customPadding = $this->getSpacingStyles('padding');
        if (!empty($customPadding)) {
            $tdPadding = trim($customPadding) . ';';
        }

        $tableStyle = "{$tableMargin} background-image: url('{$url}'); background-size: cover; background-position: center; min-height: {$minHeightValue};";
        $tdStyle = "{$tdPadding} vertical-align: {$vAlignStyle}; text-align: {$textAlign}; background-color: {$overlayRgba}; color: #ffffff; min-height: {$minHeightValue};";

        return '<table role="presentation" class="fluent-cover" width="100%" cellspacing="0" cellpadding="0" border="0" style="' . $tableStyle . '">'
            . '<tr>'
            . '<td style="' . $tdStyle . '">'
            . $innerContent
            . '</td>'
            . '</tr>'
            . '</table>';
    }

    /**
     * Get overlay color from various attribute sources
     *
     * @return string Hex color
     */
    protected function getOverlayColor(): string
    {
        // 1. customOverlayColor (direct hex)
        if (!empty($this->attrs['customOverlayColor'])) {
            return $this->attrs['customOverlayColor'];
        }

        // 2. overlayColor slug
        if (!empty($this->attrs['overlayColor'])) {
            return $this->getColorFromSlug($this->attrs['overlayColor']);
        }

        // 3. style.color.background
        if (!empty($this->style['color']['background'])) {
            return $this->getColorFromSlug($this->style['color']['background']);
        }

        // 4. Default black
        return '#000000';
    }

    /**
     * Convert hex color + opacity to rgba string
     *
     * @param string $hex Hex color (e.g., '#ff0000' or '#f00')
     * @param float $opacity Opacity 0-1
     * @return string rgba() color string
     */
    protected function hexToRgba(string $hex, float $opacity): string
    {
        $hex = ltrim($hex, '#');

        // Expand shorthand (e.g., "f00" → "ff0000")
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        // If it's not a valid hex, fallback to black
        if (strlen($hex) < 6) {
            return "rgba(0,0,0,{$opacity})";
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return "rgba({$r},{$g},{$b},{$opacity})";
    }
}