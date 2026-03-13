<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Button Block Renderer for Email
 *
 * Converts Gutenberg core/button blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class ButtonBlock extends BaseBlock
{
    /**
     * Default button colors
     */
    protected $defaultBgColor = '#0073aa';
    protected $defaultTextColor = '#ffffff';

    /**
     * Render the button block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $url = $this->extractUrl();
        $text = $this->extractText();

        $className = $this->attrs['className'] ?? '';
        $isOutline = strpos($className, 'is-style-outline') !== false;

        // Build button styles
        $styles = $this->buildButtonStyles($isOutline);

        // Extract link target and rel attributes
        $linkTarget = $this->extractLinkTarget();
        $linkRel = $this->extractRel();

        $targetAttr = !empty($linkTarget) ? " target=\"{$linkTarget}\"" : '';
        $relAttr = !empty($linkRel) ? " rel=\"{$linkRel}\"" : '';

        return "<a href=\"{$url}\"{$targetAttr}{$relAttr} style=\"{$styles}\">{$text}</a>";
    }

    /**
     * Extract URL from content or attrs
     *
     * @return string
     */
    protected function extractUrl(): string
    {
        $url = '#';

        if (!empty($this->innerHTML)) {
            if (preg_match('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>/s', $this->innerHTML, $matches)) {
                $url = $matches[1];
            }
        }

        if ($url === '#' && !empty($this->attrs['url'])) {
            $url = $this->attrs['url'];
        }

        return $url;
    }

    /**
     * Extract button text from content or attrs
     *
     * @return string
     */
    protected function extractText(): string
    {
        $text = 'Button';

        if (!empty($this->innerHTML)) {
            if (preg_match('/<a[^>]*>(.*?)<\/a>/s', $this->innerHTML, $matches)) {
                $text = trim(preg_replace('/<[^>]*>/', '', $matches[1]));
            }
        }

        if (($text === 'Button' || empty($text)) && !empty($this->attrs['text'])) {
            $text = $this->attrs['text'];
        }

        return $text ?: 'Button';
    }

    /**
     * Build button styles
     *
     * @param bool $isOutline Whether it's an outline button
     * @return string CSS styles
     */
    protected function buildButtonStyles(bool $isOutline): string
    {
        $styles = "display: inline-block; text-align: center; padding: 12px 24px; text-decoration: none; font-weight: bold;";

        // Get colors
        $bgColor = $this->getBackgroundColor();
        $textColor = $this->getTextColorValue();

        // Apply outline or filled styles
        if ($isOutline) {
            $styles .= " background-color: transparent; color: {$textColor};";
            $styles .= " border: 2px solid {$textColor};";
        } else {
            $hasGradient = !empty($this->style['color']['gradient']);
            if ($hasGradient) {
                $styles .= " background: {$this->style['color']['gradient']}; color: {$textColor};";
            } else {
                $styles .= " background-color: {$bgColor}; color: {$textColor};";
            }
        }

        // Add typography
        $styles .= $this->getTypographyStyles();
        $styles .= $this->getFontSizePresetStyle();

        // Add border (if not outline)
        if (!$isOutline) {
            $styles .= $this->getButtonBorderStyles();
        } else {
            // Only add border radius for outline
            $styles .= $this->getBorderRadiusOnly();
        }

        // Add spacing
        $styles .= $this->getSpacingStyles('padding');
        $styles .= $this->getSpacingStyles('margin');

        // Add shadow
        $styles .= $this->getShadowStyles();

        // Add width
        if (isset($this->attrs['width'])) {
            $styles .= " width: {$this->attrs['width']}%;";
        }

        return $styles;
    }

    /**
     * Get background color
     *
     * @return string
     */
    protected function getBackgroundColor(): string
    {
        if (isset($this->attrs['backgroundColor'])) {
            return $this->getColorFromSlug($this->attrs['backgroundColor']);
        }

        if (!empty($this->style['color']['background'])) {
            return $this->getColorFromSlug($this->style['color']['background']);
        }

        return $this->defaultBgColor;
    }

    /**
     * Get text color value
     *
     * @return string
     */
    protected function getTextColorValue(): string
    {
        if (isset($this->attrs['textColor'])) {
            return $this->getColorFromSlug($this->attrs['textColor']);
        }

        if (!empty($this->style['color']['text'])) {
            return $this->getColorFromSlug($this->style['color']['text']);
        }

        return $this->defaultTextColor;
    }

    /**
     * Get button border styles (excluding outline handling)
     *
     * @return string
     */
    protected function getButtonBorderStyles(): string
    {
        $styles = '';
        $border = $this->style['border'] ?? [];

        if (empty($border)) {
            // Default border radius
            return " border-radius: 9999px;";
        }

        if (isset($border['width'])) {
            $styles .= " border-width: {$border['width']};";
        }

        if (isset($border['style'])) {
            $styles .= " border-style: {$border['style']};";
        }

        if (isset($border['color'])) {
            $styles .= " border-color: {$this->getColorFromSlug($border['color'])};";
        }

        if (isset($this->attrs['borderColor'])) {
            $styles .= " border-color: {$this->getColorFromSlug($this->attrs['borderColor'])};";
        }

        if (isset($border['radius'])) {
            $styles .= $this->getBorderRadiusStyles($border['radius']);
        } else {
            $styles .= " border-radius: 9999px;";
        }

        return $styles;
    }

    /**
     * Get only border radius styles
     *
     * @return string
     */
    protected function getBorderRadiusOnly(): string
    {
        $border = $this->style['border'] ?? [];

        if (isset($border['radius'])) {
            return $this->getBorderRadiusStyles($border['radius']);
        }

        return " border-radius: 9999px;";
    }

    /**
     * Get shadow styles
     *
     * @return string
     */
    protected function getShadowStyles(): string
    {
        if (!isset($this->style['shadow'])) {
            return '';
        }

        $shadow = $this->style['shadow'];

        if ($this->parser && method_exists($this->parser, 'getShadowFromSlug')) {
            $shadowValue = $this->parser->getShadowFromSlug($shadow);
            if ($shadowValue !== 'none') {
                return " box-shadow: {$shadowValue};";
            }
        }

        return '';
    }

    /**
     * Extract link target from attrs or innerHTML
     *
     * @return string Target value (e.g., '_blank')
     */
    protected function extractLinkTarget(): string
    {
        if (!empty($this->attrs['linkTarget'])) {
            return $this->attrs['linkTarget'];
        }

        if (!empty($this->innerHTML) && preg_match('/target=["\']([^"\']*)["\']/', $this->innerHTML, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Extract rel attribute from attrs or innerHTML
     *
     * @return string Rel value
     */
    protected function extractRel(): string
    {
        if (!empty($this->attrs['rel'])) {
            return $this->attrs['rel'];
        }

        if (!empty($this->innerHTML) && preg_match('/rel=["\']([^"\']*)["\']/', $this->innerHTML, $matches)) {
            return $matches[1];
        }

        return '';
    }
}
