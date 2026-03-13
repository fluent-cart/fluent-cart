<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Image Block Renderer for Email
 *
 * Converts Gutenberg core/image blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class ImageBlock extends BaseBlock
{
    /**
     * Render the image block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $url = $this->getImageUrl();
        $alt = $this->getImageAlt();
        $align = $this->attrs['align'] ?? 'center';
        $caption = $this->getCaption();

        if (empty($url)) {
            return '';
        }

        // Build image styles
        $imgStyles = $this->buildImageStyles();

        // Build container styles
        $containerStyles = $this->buildContainerStyles($align);

        // Build dimensional attributes for email clients
        $dimensionAttrs = $this->buildDimensionAttributes();

        // Build the image tag
        $img = "<img src=\"{$url}\" alt=\"{$alt}\"{$dimensionAttrs} style=\"{$imgStyles}\" />";

        // Wrap image in link if applicable
        $linkData = $this->getLinkData($url);
        if (!empty($linkData['href'])) {
            $linkAttrs = 'href="' . esc_attr($linkData['href']) . '"';
            if (!empty($linkData['target'])) {
                $linkAttrs .= ' target="' . esc_attr($linkData['target']) . '"';
            }
            if (!empty($linkData['rel'])) {
                $linkAttrs .= ' rel="' . esc_attr($linkData['rel']) . '"';
            }
            $img = "<a {$linkAttrs} style=\"display: inline-block;\">{$img}</a>";
        }

        // Build the HTML structure
        $html = "<div style=\"{$containerStyles}\">{$img}";

        // Add caption if present
        if (!empty($caption)) {
            $captionStyles = $this->buildCaptionStyles($align);
            $html .= "<p style=\"{$captionStyles}\">{$caption}</p>";
        }

        $html .= "</div>";

        return $this->wrapInTable($html, 'fluent-image');
    }

    /**
     * Get image URL from attrs or innerHTML
     *
     * @return string Image URL
     */
    protected function getImageUrl(): string
    {
        if (!empty($this->attrs['url'])) {
            return $this->attrs['url'];
        }

        // Try to extract from innerHTML
        if (!empty($this->innerHTML) && preg_match('/src=["\']([^"\']+)["\']/', $this->innerHTML, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Get image alt text from attrs or innerHTML
     *
     * @return string Alt text
     */
    protected function getImageAlt(): string
    {
        if (!empty($this->attrs['alt'])) {
            return $this->attrs['alt'];
        }

        // Try to extract from innerHTML
        if (!empty($this->innerHTML) && preg_match('/alt=["\']([^"\']*)["\']/', $this->innerHTML, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Get image caption from innerHTML
     *
     * @return string Caption text
     */
    protected function getCaption(): string
    {
        if (!empty($this->innerHTML) && preg_match('/<figcaption[^>]*>(.*?)<\/figcaption>/s', $this->innerHTML, $matches)) {
            return wp_strip_all_tags($matches[1]);
        }

        return '';
    }

    /**
     * Build image element styles
     *
     * @return string CSS styles for img tag
     */
    protected function buildImageStyles(): string
    {
        $styles = "display: block; border: 0;";

        // Apply explicit width/height if set, otherwise use responsive defaults
        $width = $this->attrs['width'] ?? null;
        $height = $this->attrs['height'] ?? null;

        if ($width) {
            $styles .= " width: {$width};";
        } else {
            $styles .= " max-width: 100%;";
        }

        if ($height) {
            $styles .= " height: {$height};";
        } else {
            $styles .= " height: auto;";
        }

        // Add border styles if defined
        $styles .= $this->getBorderStyles();

        return $styles;
    }

    /**
     * Build dimension HTML attributes for email clients
     *
     * @return string HTML width/height attributes
     */
    protected function buildDimensionAttributes(): string
    {
        $attrs = '';
        $width = $this->attrs['width'] ?? null;
        $height = $this->attrs['height'] ?? null;

        // Only add HTML attributes for pixel values (email clients need them)
        if ($width && preg_match('/^(\d+)(px)?$/', $width, $m)) {
            $attrs .= ' width="' . $m[1] . '"';
        }
        if ($height && preg_match('/^(\d+)(px)?$/', $height, $m)) {
            $attrs .= ' height="' . $m[1] . '"';
        }

        return $attrs;
    }

    /**
     * Build container div styles
     *
     * @param string $align Alignment value
     * @return string CSS styles for container
     */
    protected function buildContainerStyles(string $align): string
    {
        $textAlign = $align === 'center' ? 'center' : ($align === 'right' ? 'right' : 'left');
        $marginAuto = $align === 'center' ? 'margin: 0 auto;' : '';

        $styles = "text-align: {$textAlign}; {$marginAuto} margin-bottom: 16px;";

        // Add spacing styles
        $styles .= $this->getSpacingStyles('padding');
        $styles .= $this->getSpacingStyles('margin');

        return $styles;
    }

    /**
     * Build caption styles
     *
     * @param string $align Alignment value
     * @return string CSS styles for caption
     */
    protected function buildCaptionStyles(string $align): string
    {
        $textAlign = $align === 'center' ? 'center' : ($align === 'right' ? 'right' : 'left');

        return "margin: 8px 0 0 0; font-size: 14px; color: #666; font-style: italic; text-align: {$textAlign};";
    }

    /**
     * Get link data from innerHTML or attrs
     *
     * @param string $imageUrl The image URL (used for linkDestination=media)
     * @return array ['href' => string, 'target' => string, 'rel' => string]
     */
    protected function getLinkData(string $imageUrl): array
    {
        $data = ['href' => '', 'target' => '', 'rel' => ''];

        // Try to extract from innerHTML <a> tag
        if (!empty($this->innerHTML) && preg_match('/<a\s([^>]*)>/s', $this->innerHTML, $aMatch)) {
            $aAttrs = $aMatch[1];

            if (preg_match('/href=["\']([^"\']*)["\']/', $aAttrs, $m)) {
                $data['href'] = $m[1];
            }
            if (preg_match('/target=["\']([^"\']*)["\']/', $aAttrs, $m)) {
                $data['target'] = $m[1];
            }
            if (preg_match('/rel=["\']([^"\']*)["\']/', $aAttrs, $m)) {
                $data['rel'] = $m[1];
            }
        }

        // Handle linkDestination attr
        $linkDest = $this->attrs['linkDestination'] ?? '';
        if (empty($data['href']) && $linkDest === 'media') {
            $data['href'] = $imageUrl;
        }

        // Override href from attrs if present
        if (!empty($this->attrs['href'])) {
            $data['href'] = $this->attrs['href'];
        }

        return $data;
    }
}
