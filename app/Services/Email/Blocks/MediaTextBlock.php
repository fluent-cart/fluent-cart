<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Media & Text Block Renderer for Email
 *
 * Converts Gutenberg core/media-text blocks to email-compatible HTML.
 * Creates a side-by-side layout with image/media on one side and content on the other.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class MediaTextBlock extends BaseBlock
{
    /**
     * Render the media-text block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $mediaUrl = $this->getMediaUrl();
        $mediaAlt = $this->attrs['mediaAlt'] ?? '';
        $mediaPosition = $this->attrs['mediaPosition'] ?? 'left';
        $mediaWidth = $this->attrs['mediaWidth'] ?? 50;
        $contentWidth = 100 - $mediaWidth;
        $imageFill = $this->attrs['imageFill'] ?? false;
        $verticalAlignment = $this->attrs['verticalAlignment'] ?? 'center';

        // Build container styles
        $containerStyles = $this->buildContainerStyles();

        // Build media cell styles
        $mediaCellStyles = $this->buildMediaCellStyles($verticalAlignment);

        // Build content cell styles
        $contentCellStyles = $this->buildContentCellStyles($verticalAlignment);

        // Build image styles
        $imageStyles = $this->buildImageStyles($imageFill);

        // Build the image HTML
        $mediaHtml = $this->buildMediaHtml($mediaUrl, $mediaAlt, $imageStyles, $imageFill);

        // Render inner blocks (the text content)
        $contentHtml = $this->renderInnerContent();

        // Build the two-column table layout
        if ($mediaPosition === 'right') {
            $html = $this->buildTableLayout(
                $contentHtml,
                $mediaHtml,
                $contentWidth,
                $mediaWidth,
                $contentCellStyles,
                $mediaCellStyles,
                $containerStyles
            );
        } else {
            // Default: media on left
            $html = $this->buildTableLayout(
                $mediaHtml,
                $contentHtml,
                $mediaWidth,
                $contentWidth,
                $mediaCellStyles,
                $contentCellStyles,
                $containerStyles
            );
        }

        return $html;
    }

    /**
     * Get media URL from attrs or innerHTML
     *
     * @return string Media URL
     */
    protected function getMediaUrl(): string
    {
        if (!empty($this->attrs['mediaUrl'])) {
            return $this->attrs['mediaUrl'];
        }

        // Try to extract from innerHTML
        if (!empty($this->innerHTML) && preg_match('/src=["\']([^"\']+)["\']/', $this->innerHTML, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Build container/wrapper styles
     *
     * @return string CSS styles for container
     */
    protected function buildContainerStyles(): string
    {
        $styles = "margin: 20px 0;";

        // Add color styles
        $colorResult = $this->getColorStyles();
        $styles .= $colorResult['styles'];

        // Add spacing styles
        $styles .= $this->getSpacingStyles('margin');

        return $styles;
    }

    /**
     * Build media cell styles
     *
     * @param string $verticalAlignment Vertical alignment value
     * @return string CSS styles for media cell
     */
    protected function buildMediaCellStyles(string $verticalAlignment): string
    {
        $vAlign = $this->mapVerticalAlignment($verticalAlignment);

        return "vertical-align: {$vAlign}; padding: 0;";
    }

    /**
     * Build content cell styles
     *
     * @param string $verticalAlignment Vertical alignment value
     * @return string CSS styles for content cell
     */
    protected function buildContentCellStyles(string $verticalAlignment): string
    {
        $vAlign = $this->mapVerticalAlignment($verticalAlignment);
        $styles = "vertical-align: {$vAlign}; padding: 0 20px;";

        // Add typography styles
        $styles .= $this->getTypographyStyles();

        return $styles;
    }

    /**
     * Map Gutenberg vertical alignment to CSS
     *
     * @param string $alignment Gutenberg alignment value
     * @return string CSS vertical-align value
     */
    protected function mapVerticalAlignment(string $alignment): string
    {
        $map = [
            'top'    => 'top',
            'center' => 'middle',
            'bottom' => 'bottom',
        ];

        return $map[$alignment] ?? 'middle';
    }

    /**
     * Build image element styles
     *
     * @param bool $imageFill Whether image should fill container
     * @return string CSS styles for image
     */
    protected function buildImageStyles(bool $imageFill): string
    {
        $styles = "display: block; border: 0;";

        if ($imageFill) {
            $styles .= " width: 100%; height: 100%; object-fit: cover;";
        } else {
            $styles .= " max-width: 100%; height: auto;";
        }

        // Add border styles if defined
        $styles .= $this->getBorderStyles();

        return $styles;
    }

    /**
     * Build the media HTML (image or placeholder)
     *
     * @param string $url Media URL
     * @param string $alt Alt text
     * @param string $styles Image styles
     * @param bool $imageFill Whether image fills container
     * @return string Media HTML
     */
    protected function buildMediaHtml(string $url, string $alt, string $styles, bool $imageFill): string
    {
        if (empty($url)) {
            return '';
        }

        $mediaType = $this->attrs['mediaType'] ?? 'image';

        // For videos, show a placeholder image or link
        if ($mediaType === 'video') {
            // Videos don't work in email, show a play button overlay or link
            $posterUrl = $this->attrs['mediaPoster'] ?? '';
            if ($posterUrl) {
                return "<a href=\"{$url}\" style=\"display: block;\"><img src=\"{$posterUrl}\" alt=\"{$alt}\" style=\"{$styles}\" /></a>";
            }
            return "<a href=\"{$url}\" style=\"display: inline-block; padding: 20px; background: #f0f0f0; text-decoration: none; color: #333;\">Watch Video</a>";
        }

        // Handle focal point for imageFill
        if ($imageFill && !empty($this->attrs['focalPoint'])) {
            $focalPoint = $this->attrs['focalPoint'];
            $x = isset($focalPoint['x']) ? ($focalPoint['x'] * 100) . '%' : '50%';
            $y = isset($focalPoint['y']) ? ($focalPoint['y'] * 100) . '%' : '50%';
            $styles .= " object-position: {$x} {$y};";
        }

        return "<img src=\"{$url}\" alt=\"{$alt}\" style=\"{$styles}\" />";
    }

    /**
     * Render inner blocks content
     *
     * @return string Rendered inner content HTML
     */
    protected function renderInnerContent(): string
    {
        if (!empty($this->innerBlocks) && $this->parser) {
            return $this->parser->renderNestedBlocks($this->innerBlocks);
        }

        // Fallback to innerHTML if no inner blocks
        if (!empty($this->innerHTML)) {
            // Try to extract content that's not the image
            $content = preg_replace('/<figure[^>]*>.*?<\/figure>/s', '', $this->innerHTML);
            $content = preg_replace('/<img[^>]*>/s', '', $content);
            return trim($content);
        }

        return '';
    }

    /**
     * Build the two-column table layout
     *
     * @param string $leftContent Left column content
     * @param string $rightContent Right column content
     * @param int $leftWidth Left column width percentage
     * @param int $rightWidth Right column width percentage
     * @param string $leftStyles Left cell styles
     * @param string $rightStyles Right cell styles
     * @param string $containerStyles Container styles
     * @return string Table HTML
     */
    protected function buildTableLayout(
        string $leftContent,
        string $rightContent,
        int $leftWidth,
        int $rightWidth,
        string $leftStyles,
        string $rightStyles,
        string $containerStyles
    ): string {
        return "<table role=\"presentation\" class=\"fct_media_text\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" style=\"{$containerStyles}\">
    <tr>
        <td width=\"{$leftWidth}%\" style=\"{$leftStyles}\">
            {$leftContent}
        </td>
        <td width=\"{$rightWidth}%\" style=\"{$rightStyles}\">
            {$rightContent}
        </td>
    </tr>
</table>";
    }
}