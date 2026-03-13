<?php

namespace FluentCart\App\Services\Email\Blocks;

use FluentCart\Framework\Support\Arr;

/**
 * Base class for Email Block Renderers
 *
 * Provides common style handling for Gutenberg blocks converted to email-compatible HTML.
 * All block-specific renderers should extend this class.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
abstract class BaseBlock
{
    /**
     * Block attributes from Gutenberg
     *
     * @var array
     */
    protected $attrs = [];

    /**
     * Style attributes (shortcut to $attrs['style'])
     *
     * @var array
     */
    protected $style = [];

    /**
     * Inner HTML content of the block
     *
     * @var string
     */
    protected $innerHTML = '';

    /**
     * Inner blocks (for nested blocks)
     *
     * @var array
     */
    protected $innerBlocks = [];

    /**
     * Reference to the parent parser for helper methods
     *
     * @var \FluentCart\App\Services\Email\FluentBlockParser
     */
    protected $parser;

    /**
     * Constructor
     *
     * @param array $attrs Block attributes
     * @param string $innerHTML Inner HTML content
     * @param array $innerBlocks Inner blocks for nested content
     * @param \FluentCart\App\Services\Email\FluentBlockParser|null $parser Parent parser instance
     */
    public function __construct(array $attrs = [], string $innerHTML = '', array $innerBlocks = [], $parser = null)
    {
        $this->attrs = $attrs;
        $this->style = $attrs['style'] ?? [];
        $this->innerHTML = $innerHTML;
        $this->innerBlocks = $innerBlocks;
        $this->parser = $parser;
    }

    /**
     * Render the block to email-compatible HTML
     *
     * @return string
     */
    abstract public function render(): string;

    /**
     * Evaluate the block's built-in visibility condition.
     *
     * Returns true if the block should render, false if hidden.
     * When no condition is configured, returns true (always render).
     *
     * Requires $this->parserData to be set for shortcode resolution.
     *
     * @return bool
     */
    protected function evaluateBlockCondition()
    {
        if (empty($this->attrs['conditionEnabled'])) {
            return true;
        }

        $resolved = static::resolveConditionParams(
            Arr::get($this->attrs, 'conditionPreset', ''),
            Arr::get($this->attrs, 'conditionShortcode', ''),
            Arr::get($this->attrs, 'conditionType', 'not_empty'),
            Arr::get($this->attrs, 'conditionCompareValue', '')
        );

        if (!$resolved) {
            return true;
        }

        $data = property_exists($this, 'parserData') ? $this->parserData : [];

        return static::evaluateResolved($resolved, $data, Arr::get($this->attrs, 'conditionPreset', ''), $this->attrs);
    }

    /**
     * Resolve condition parameters from a preset ID or custom fields.
     *
     * Returns null if no valid condition could be resolved (block should render).
     *
     * @param string $presetId
     * @param string $shortcode
     * @param string $condition
     * @param string $compareValue
     * @return array{shortcode: string, condition: string, compareValue: string}|null
     */
    public static function resolveConditionParams($presetId, $shortcode, $condition, $compareValue)
    {
        if ($presetId && $presetId !== '__custom') {
            return \FluentCart\App\Services\Email\ConditionPresets::resolve($presetId);
        }

        if (empty($shortcode)) {
            return null;
        }

        return [
            'shortcode'    => $shortcode,
            'condition'    => $condition ?: 'not_empty',
            'compareValue' => $compareValue ?: '',
        ];
    }

    /**
     * Evaluate a shortcode condition.
     *
     * @param string $shortcode
     * @param string $condition
     * @param string $compareValue
     * @param array  $data
     * @return bool
     */
    public static function evaluateCondition($shortcode, $condition, $compareValue, array $data = [])
    {
        $resolved = \FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder::make($shortcode, $data);
        $resolved = trim($resolved);

        $unresolved = ($resolved === $shortcode);

        switch ($condition) {
            case 'empty':
                return $unresolved || empty($resolved);
            case 'equal':
                return !$unresolved && $resolved === $compareValue;
            case 'not_equal':
                return $unresolved || $resolved !== $compareValue;
            case 'greater_than':
                return !$unresolved && is_numeric($resolved) && (float) $resolved > (float) $compareValue;
            case 'smaller_than':
                return !$unresolved && is_numeric($resolved) && (float) $resolved < (float) $compareValue;
            case 'not_empty':
            default:
                return !$unresolved && !empty($resolved);
        }
    }

    /**
     * Evaluate a resolved condition array (from ConditionPresets::resolve or resolveConditionParams).
     *
     * Handles three types:
     *  - 'callback': calls the preset's callback function
     *  - 'filter':   delegates to apply_filters('fluent_cart/evaluate_condition_preset')
     *  - 'shortcode': evaluates via shortcode comparison (default)
     *
     * @param array $resolved
     * @param array $data
     * @return bool
     */
    public static function evaluateResolved(array $resolved, array $data = [], $presetId = '', array $blockAttrs = [])
    {
        $context = [
            'preset_id'  => $presetId,
            'preset'     => $presetId ? \FluentCart\App\Services\Email\ConditionPresets::find($presetId) : null,
            'resolved'   => $resolved,
            'data' => $data,
            'block_attrs' => $blockAttrs,
        ];

        if (!empty($resolved['callback']) && is_callable($resolved['callback'])) {
            return (bool) call_user_func($resolved['callback'], $context);
        }

        if (empty($resolved['shortcode'])) {
            return (bool) apply_filters('fluent_cart/evaluate_condition_preset', false, $context);
        }

        return static::evaluateCondition($resolved['shortcode'], $resolved['condition'], $resolved['compareValue'], $data);
    }

    /**
     * Get typography CSS styles from block attributes
     *
     * Handles: fontSize, fontFamily, fontWeight, lineHeight,
     * textTransform, letterSpacing, textDecoration, fontStyle
     *
     * @param array|null $typography Typography array (defaults to $this->style['typography'])
     * @return string CSS styles string
     */
    protected function getTypographyStyles(?array $typography = null): string
    {
        $typography = $typography ?? ($this->style['typography'] ?? []);
        $styles = '';

        if (empty($typography)) {
            return $styles;
        }

        $properties = [
            'fontSize'       => 'font-size',
            'fontFamily'     => 'font-family',
            'fontWeight'     => 'font-weight',
            'lineHeight'     => 'line-height',
            'textTransform'  => 'text-transform',
            'letterSpacing'  => 'letter-spacing',
            'textDecoration' => 'text-decoration',
            'fontStyle'      => 'font-style',
            'textShadow'     => 'text-shadow',
        ];

        foreach ($properties as $attr => $css) {
            if (isset($typography[$attr])) {
                $value = $typography[$attr];
                // Handle font size that might be a slug
                if ($attr === 'fontSize' && !preg_match('/^\d/', $value)) {
                    $value = $this->getFontSizeFromSlug($value);
                }
                $styles .= " {$css}: {$value};";
            }
        }

        return $styles;
    }

    /**
     * Get color CSS styles from block attributes
     *
     * Handles: textColor, backgroundColor, gradient
     *
     * @param string $targetElement 'element' for the main element, 'wrapper' for the wrapper
     * @return array ['textColor' => string|null, 'styles' => string]
     */
    protected function getColorStyles(string $targetElement = 'element'): array
    {
        $styles = '';
        $textColor = null;

        // Handle text color from attribute
        if (isset($this->attrs['textColor'])) {
            $textColor = $this->getColorFromSlug($this->attrs['textColor']);
            $styles .= " color: {$textColor};";
        }

        // Handle text color from style
        if (!empty($this->style['color']['text'])) {
            $textColor = $this->getColorFromSlug($this->style['color']['text']);
            $styles .= " color: {$textColor};";
        }

        // Handle background color from attribute
        if (isset($this->attrs['backgroundColor'])) {
            $styles .= " background-color: {$this->getColorFromSlug($this->attrs['backgroundColor'])};";
        }

        // Handle background color from style
        if (!empty($this->style['color']['background'])) {
            $styles .= " background-color: {$this->getColorFromSlug($this->style['color']['background'])};";
        }

        // Handle gradient background
        if (!empty($this->style['color']['gradient'])) {
            $styles .= " background: {$this->style['color']['gradient']};";
        }

        return [
            'textColor' => $textColor,
            'styles'    => $styles,
        ];
    }

    /**
     * Get border CSS styles from block attributes
     *
     * Handles: width, style, color, radius, individual sides (top, right, bottom, left)
     *
     * @return string CSS styles string
     */
    protected function getBorderStyles(): string
    {
        $styles = '';
        $border = $this->style['border'] ?? [];

        if (empty($border) && !isset($this->attrs['borderColor'])) {
            return $styles;
        }

        // Shorthand border (width/style/color)
        $hasShorthand = isset($border['width']) || isset($border['color']) || isset($this->attrs['borderColor']);
        if ($hasShorthand) {
            $styles .= ' border-width: ' . ($border['width'] ?? '1px') . ';';
            $styles .= ' border-style: ' . ($border['style'] ?? 'solid') . ';';

            if (isset($border['color'])) {
                $styles .= " border-color: {$this->getColorFromSlug($border['color'])};";
            }
            if (isset($this->attrs['borderColor'])) {
                $styles .= " border-color: {$this->getColorFromSlug($this->attrs['borderColor'])};";
            }
        } elseif (isset($border['style'])) {
            $styles .= " border-style: {$border['style']};";
        }

        // Border radius
        if (isset($border['radius'])) {
            $styles .= $this->getBorderRadiusStyles($border['radius']);
        }

        // Individual borders (top, right, bottom, left)
        $sides = ['top', 'right', 'bottom', 'left'];
        foreach ($sides as $side) {
            if (isset($border[$side])) {
                $sideStyles = $border[$side];
                $width = $sideStyles['width'] ?? '1px';
                $borderStyle = $sideStyles['style'] ?? 'solid';
                $color = isset($sideStyles['color']) ? $this->getColorFromSlug($sideStyles['color']) : '#000';
                $styles .= " border-{$side}: {$width} {$borderStyle} {$color};";
            }
        }

        return $styles;
    }

    /**
     * Get border radius CSS styles
     *
     * @param string|array $radius Border radius value
     * @return string CSS styles string
     */
    protected function getBorderRadiusStyles($radius): string
    {
        $styles = '';

        if (is_string($radius)) {
            $styles .= " border-radius: {$radius};";
        } elseif (is_array($radius)) {
            $corners = [
                'topLeft'     => 'border-top-left-radius',
                'topRight'    => 'border-top-right-radius',
                'bottomLeft'  => 'border-bottom-left-radius',
                'bottomRight' => 'border-bottom-right-radius',
            ];

            foreach ($corners as $corner => $css) {
                if (isset($radius[$corner])) {
                    $styles .= " {$css}: {$radius[$corner]};";
                }
            }
        }

        return $styles;
    }

    /**
     * Get spacing CSS styles (padding or margin)
     *
     * @param string $type 'padding' or 'margin'
     * @return string CSS styles string
     */
    protected function getSpacingStyles(string $type = 'padding'): string
    {
        $styles = '';
        $spacing = $this->style['spacing'][$type] ?? null;

        if (empty($spacing)) {
            return $styles;
        }

        if (is_string($spacing)) {
            $styles .= " {$type}: {$this->resolveSpacingValue($spacing)};";
        } elseif (is_array($spacing)) {
            $sides = ['top', 'right', 'bottom', 'left'];
            foreach ($sides as $side) {
                if (isset($spacing[$side])) {
                    $styles .= " {$type}-{$side}: {$this->resolveSpacingValue($spacing[$side])};";
                }
            }
        }

        return $styles;
    }

    /**
     * Resolve a spacing value that might be a CSS variable reference
     *
     * Handles formats:
     * - "var:preset|spacing|20" (Gutenberg shorthand)
     * - "var(--wp--preset--spacing--20)" (CSS custom property)
     * - "18px", "1.5em" etc. (literal values, returned as-is)
     *
     * @param string $value The spacing value
     * @return string Resolved pixel value
     */
    protected function resolveSpacingValue(string $value): string
    {
        // Handle Gutenberg shorthand: var:preset|spacing|slug
        if (strpos($value, 'var:preset|spacing|') === 0) {
            $slug = substr($value, strlen('var:preset|spacing|'));
            return $this->getSpacingFromSlug($slug);
        }

        // Handle CSS custom property: var(--wp--preset--spacing--slug)
        if (preg_match('/^var\(--wp--preset--spacing--(.+)\)$/', $value, $m)) {
            return $this->getSpacingFromSlug($m[1]);
        }

        return $value;
    }

    /**
     * Get pixel value from spacing preset slug
     *
     * @param string $slug Spacing preset slug
     * @return string Pixel value
     */
    protected function getSpacingFromSlug(string $slug): string
    {
        // Try WordPress theme settings first
        if (function_exists('wp_get_global_settings')) {
            $settings = wp_get_global_settings();
            $presets = $settings['spacing']['spacingSizes']['default'] ?? ($settings['spacing']['spacingSizes'] ?? []);
            foreach ($presets as $preset) {
                if (isset($preset['slug']) && $preset['slug'] === $slug && !empty($preset['size'])) {
                    return $preset['size'];
                }
            }
        }

        // Common WordPress default spacing presets
        $defaults = [
            '20' => '20px', '30' => '30px', '40' => '40px',
            '50' => '50px', '60' => '60px', '70' => '70px', '80' => '80px',
            'fluent-20' => '20px', 'fluent-30' => '30px', 'fluent-40' => '40px',
            'fluent-50' => '50px', 'fluent-60' => '60px', 'fluent-70' => '70px', 'fluent-80' => '80px',
        ];

        return $defaults[$slug] ?? '20px';
    }

    /**
     * Get alignment CSS styles
     *
     * @param string|null $align Alignment value (left, center, right)
     * @return string CSS styles string
     */
    protected function getAlignmentStyles(?string $align = null): string
    {
        $align = $align ?? ($this->attrs['align'] ?? null);

        if (!$align) {
            return '';
        }

        return " text-align: {$align};";
    }

    /**
     * Apply link color styles to anchor tags in content
     *
     * @param string $content HTML content with anchor tags
     * @param string|null $linkColor Color to apply (defaults to element link color or text color)
     * @param string|null $textColor Fallback text color
     * @return string Modified HTML content
     */
    protected function applyLinkColorStyles(string $content, ?string $linkColor = null, ?string $textColor = null): string
    {
        // Try to get link color from style
        if (!$linkColor && isset($this->style['elements']['link']['color']['text'])) {
            $linkColor = $this->getColorFromSlug($this->style['elements']['link']['color']['text']);
        }

        // Fallback to text color
        if (!$linkColor && $textColor) {
            $linkColor = $textColor;
        }

        if (!$linkColor) {
            return $content;
        }

        return preg_replace_callback(
            '/<a([^>]*)>/i',
            function ($matches) use ($linkColor) {
                $existingAttrs = $matches[1];

                if (preg_match('/style=["\']([^"\']*)["\']/', $existingAttrs, $styleMatch)) {
                    $existingStyle = $styleMatch[1];
                    $newStyle = $existingStyle . " color: {$linkColor}; text-decoration: underline;";
                    return '<a' . preg_replace('/style=["\'][^"\']*["\']/', 'style="' . $newStyle . '"', $existingAttrs) . '>';
                }

                return '<a' . $existingAttrs . ' style="color: ' . $linkColor . '; text-decoration: underline;">';
            },
            $content
        );
    }

    /**
     * Wrap content in email-safe table structure
     *
     * @param string $content HTML content to wrap
     * @param string $className Optional class name for the table
     * @param string $tdStyles Optional inline styles for the td element
     * @return string Email-safe table HTML
     */
    protected function wrapInTable(string $content, string $className = '', string $tdStyles = 'padding: 0;'): string
    {
        if (empty(trim($content))) {
            return '';
        }

        $classAttr = $className ? " class=\"{$className}\"" : '';

        return "<table role=\"presentation\"{$classAttr} width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\">
    <tr>
        <td style=\"{$tdStyles}\">
            {$content}
        </td>
    </tr>
</table>";
    }

    /**
     * Get color value from slug or CSS variable
     *
     * @param string $slug Color slug or value
     * @return string Resolved color value
     */
    protected function getColorFromSlug(string $slug): string
    {
        if ($this->parser) {
            return $this->parser->getColorFromSlug($slug);
        }

        // Fallback: return as-is if it looks like a color value
        if (preg_match('/^#[a-f0-9]{3,8}$/i', $slug) || preg_match('/^rgba?\(/i', $slug)) {
            return $slug;
        }

        // Basic color map fallback
        $colors = [
            'black'  => '#000000',
            'white'  => '#ffffff',
            'red'    => '#e74c3c',
            'blue'   => '#3498db',
            'green'  => '#2ecc71',
            'yellow' => '#f1c40f',
        ];

        return $colors[$slug] ?? $slug;
    }

    /**
     * Get font size value from slug
     *
     * @param string $slug Font size slug or value
     * @return string Resolved font size value
     */
    protected function getFontSizeFromSlug(string $slug): string
    {
        if ($this->parser) {
            return $this->parser->getFontSizeFromSlug($slug);
        }

        // Fallback font sizes
        $sizes = [
            'small'  => '14px',
            'medium' => '18px',
            'large'  => '24px',
            'larger' => '32px',
        ];

        return $sizes[$slug] ?? '16px';
    }

    /**
     * Extract inner content from HTML tags
     *
     * @param string $content HTML content
     * @param string $tagPattern Regex pattern for the tag (e.g., 'h[1-6]', 'p')
     * @return string Extracted inner content or original content
     */
    protected function extractInnerContent(string $content, string $tagPattern): string
    {
        // Strip ALL opening and closing tags matching the pattern.
        // Gutenberg innerHTML may contain nested/duplicate tags (e.g. <p><p>...</p></p>)
        // whose inline styles conflict with block attributes. Since block renderers
        // wrap content in their own styled tag, we remove all matching wrappers here.
        $content = preg_replace("/<{$tagPattern}\b[^>]*>/i", '', $content);
        $content = preg_replace("/<\/{$tagPattern}\s*>/i", '', $content);

        return trim($content);
    }

    /**
     * Get attribute value from innerHTML if not in attrs
     *
     * @param string $attrName Attribute name to extract
     * @param mixed $default Default value if not found
     * @return mixed Attribute value
     */
    protected function getAttrFromHtml(string $attrName, $default = '')
    {
        if (isset($this->attrs[$attrName])) {
            return $this->attrs[$attrName];
        }

        if (!empty($this->innerHTML)) {
            if (preg_match("/{$attrName}=[\"']([^\"']*)[\"']/", $this->innerHTML, $matches)) {
                return $matches[1];
            }
        }

        return $default;
    }

    /**
     * Build combined styles string from multiple style sources
     *
     * @param string $baseStyles Base/default styles
     * @param bool $includeTypography Include typography styles
     * @param bool $includeColors Include color styles
     * @param bool $includeBorder Include border styles
     * @param bool $includePadding Include padding styles
     * @param bool $includeMargin Include margin styles
     * @return array ['styles' => string, 'textColor' => string|null]
     */
    protected function buildStyles(
        string $baseStyles = '',
        bool $includeTypography = true,
        bool $includeColors = true,
        bool $includeBorder = true,
        bool $includePadding = true,
        bool $includeMargin = true
    ): array {
        $styles = $baseStyles;
        $textColor = null;

        if ($includeTypography) {
            $styles .= $this->getTypographyStyles();
        }

        if ($includeColors) {
            $colorResult = $this->getColorStyles();
            $styles .= $colorResult['styles'];
            $textColor = $colorResult['textColor'];
        }

        if ($includeBorder) {
            $styles .= $this->getBorderStyles();
        }

        if ($includePadding) {
            $styles .= $this->getSpacingStyles('padding');
        }

        if ($includeMargin) {
            $styles .= $this->getSpacingStyles('margin');
        }

        return [
            'styles'    => $styles,
            'textColor' => $textColor,
        ];
    }

    /**
     * Handle font size from preset attribute
     *
     * @return string CSS font-size style or empty string
     */
    protected function getFontSizePresetStyle(): string
    {
        if (isset($this->attrs['fontSize'])) {
            $fontSize = $this->getFontSizeFromSlug($this->attrs['fontSize']);
            return " font-size: {$fontSize};";
        }

        return '';
    }
}
