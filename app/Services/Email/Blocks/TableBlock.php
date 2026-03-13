<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Table Block Renderer for Email
 *
 * Converts Gutenberg core/table blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class TableBlock extends BaseBlock
{
    /**
     * Render the table block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        // Build table styles
        $tableStyles = $this->buildTableStyles();

        // Build cell styles
        $cellStyles = $this->buildCellStyles();
        $thStyles = $this->buildHeaderCellStyles();

        // Extract and process table content
        $tableContent = $this->processTableContent($cellStyles, $thStyles);

        if (empty($tableContent)) {
            return $this->wrapInTable($this->innerHTML, 'fluent-table');
        }

        $html = "<table style=\"{$tableStyles}\">{$tableContent}</table>";

        // Add caption if present
        $caption = $this->getCaption();
        if (!empty($caption)) {
            $html .= "<p style=\"margin: 8px 0 0 0; font-size: 13px; color: #666; font-style: italic; text-align: center;\">{$caption}</p>";
        }

        return $this->wrapInTable($html, 'fluent-table');
    }

    /**
     * Get table caption from attrs or innerHTML
     *
     * @return string Caption text
     */
    protected function getCaption(): string
    {
        // Check attrs first
        if (!empty($this->attrs['caption'])) {
            return wp_strip_all_tags($this->attrs['caption']);
        }

        // Try to extract from innerHTML figcaption
        if (!empty($this->innerHTML) && preg_match('/<figcaption[^>]*>(.*?)<\/figcaption>/s', $this->innerHTML, $matches)) {
            return wp_strip_all_tags($matches[1]);
        }

        return '';
    }

    /**
     * Build table element styles
     *
     * @return string CSS styles for table
     */
    protected function buildTableStyles(): string
    {
        $styles = "width: 100%; border-collapse: collapse; margin: 20px 0;";

        // Add color styles
        $colorResult = $this->getColorStyles();
        $styles .= $colorResult['styles'];

        // Add typography styles
        $styles .= $this->getTypographyStyles();

        // Handle font size from direct attribute
        if (isset($this->attrs['fontSize'])) {
            $styles .= " font-size: {$this->getFontSizeFromSlug($this->attrs['fontSize'])};";
        }
        if (isset($this->style['fontSize'])) {
            $styles .= " font-size: {$this->getFontSizeFromSlug($this->style['fontSize'])};";
        }

        // Handle alignment
        $styles .= $this->buildTableAlignmentStyles();

        // Add spacing styles
        $styles .= $this->getSpacingStyles('padding');
        $styles .= $this->getSpacingStyles('margin');

        // Handle fixed layout
        if (!empty($this->attrs['hasFixedLayout'])) {
            $styles .= " table-layout: fixed;";
        }

        return $styles;
    }

    /**
     * Build table alignment styles
     *
     * @return string CSS alignment styles
     */
    protected function buildTableAlignmentStyles(): string
    {
        $styles = '';
        $alignment = $this->attrs['align'] ?? null;

        if (!$alignment) {
            return $styles;
        }

        if (in_array($alignment, ['left', 'center', 'right'])) {
            $marginLeft = $alignment === 'center' ? 'auto' : '0';
            $marginRight = $alignment === 'center' ? 'auto' : '0';
            $styles .= " margin-left: {$marginLeft}; margin-right: {$marginRight};";
        }

        if ($alignment === 'wide') {
            $styles .= " width: 100%; max-width: var(--wp--style--global--wide-size, 1280px);";
        }

        if ($alignment === 'full') {
            $styles .= " width: 100%;";
        }

        return $styles;
    }

    /**
     * Build cell (td) styles
     *
     * @return string CSS styles for td elements
     */
    protected function buildCellStyles(): string
    {
        $styles = "padding: 12px;";

        // Handle border styles
        $styles .= $this->getCellBorderStyles();

        // Handle cell text alignment
        $bodyAlign = $this->style['elements']['td']['typography']['textAlign'] ?? 'center';
        $styles .= " text-align: {$bodyAlign};";

        return $styles;
    }

    /**
     * Build header cell (th) styles
     *
     * @return string CSS styles for th elements
     */
    protected function buildHeaderCellStyles(): string
    {
        $styles = "font-weight: bold;";

        // Handle header text alignment
        $headerAlign = $this->style['elements']['th']['typography']['textAlign']
            ?? (!empty($this->attrs['align']) ? 'center' : 'left');
        $styles .= " text-align: {$headerAlign};";

        return $styles;
    }

    /**
     * Get cell border styles
     *
     * @return string CSS border styles for cells
     */
    protected function getCellBorderStyles(): string
    {
        $border = $this->style['border'] ?? [];

        if (!empty($border)) {
            $borderWidth = $border['width'] ?? '1px';
            $borderStyle = $border['style'] ?? 'solid';

            // Check attrs borderColor slug, then style.border.color, then fallback
            if (isset($this->attrs['borderColor'])) {
                $borderColor = $this->getColorFromSlug($this->attrs['borderColor']);
            } elseif (isset($border['color'])) {
                $borderColor = $this->getColorFromSlug($border['color']);
            } else {
                $borderColor = '#ddd';
            }

            return " border: {$borderWidth} {$borderStyle} {$borderColor};";
        }

        return " border: 1px solid #ddd;";
    }

    /**
     * Process table content and apply cell styles
     *
     * @param string $cellStyles Styles for td elements
     * @param string $thStyles Additional styles for th elements
     * @return string Processed table content
     */
    protected function processTableContent(string $cellStyles, string $thStyles): string
    {
        if (!preg_match('/<table[^>]*>(.*?)<\/table>/s', $this->innerHTML, $matches)) {
            return '';
        }

        $tableContent = $matches[1];

        // Add styles to td elements
        $tableContent = preg_replace(
            '/<td([^>]*)>/',
            '<td$1 style="' . $cellStyles . '">',
            $tableContent
        );

        // Add styles to th elements
        $tableContent = preg_replace(
            '/<th([^>]*)>/',
            '<th$1 style="' . $cellStyles . ' ' . $thStyles . '">',
            $tableContent
        );

        return $tableContent;
    }
}