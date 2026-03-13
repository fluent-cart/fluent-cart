<?php

namespace FluentCart\App\Services\Email;

use FluentCart\App\App;
use FluentCart\App\Services\Email\Blocks\ButtonBlock;
use FluentCart\App\Services\Email\Blocks\ButtonsBlock;
use FluentCart\App\Services\Email\Blocks\CodeBlock;
use FluentCart\App\Services\Email\Blocks\ColumnBlock;
use FluentCart\App\Services\Email\Blocks\ColumnsBlock;
use FluentCart\App\Services\Email\Blocks\CoverBlock;
use FluentCart\App\Services\Email\Blocks\GroupBlock;
use FluentCart\App\Services\Email\Blocks\HeadingBlock;
use FluentCart\App\Services\Email\Blocks\ImageBlock;
use FluentCart\App\Services\Email\Blocks\ListBlock;
use FluentCart\App\Services\Email\Blocks\ListItemBlock;
use FluentCart\App\Services\Email\Blocks\MediaTextBlock;
use FluentCart\App\Services\Email\Blocks\ParagraphBlock;
use FluentCart\App\Services\Email\Blocks\PreformattedBlock;
use FluentCart\App\Services\Email\Blocks\PullquoteBlock;
use FluentCart\App\Services\Email\Blocks\QuoteBlock;
use FluentCart\App\Services\Email\Blocks\SeparatorBlock;
use FluentCart\App\Services\Email\Blocks\SocialLinksBlock;
use FluentCart\App\Services\Email\Blocks\SpacerBlock;
use FluentCart\App\Services\Email\Blocks\TableBlock;
use FluentCart\App\Services\Email\Blocks\VerseBlock;
use FluentCart\App\Services\Email\Blocks\EmailRowBlock;
use FluentCart\App\Services\Email\Blocks\OrderItemsLoopBlock;
use FluentCart\App\Services\Email\Blocks\LicenseDetailsLoopBlock;
use FluentCart\App\Services\Email\Blocks\DownloadDetailsLoopBlock;
use FluentCart\App\Services\Email\Blocks\SubscriptionDetailsLoopBlock;
use FluentCart\App\Services\Email\Blocks\ShortcodeConditionBlock;
use FluentCart\App\Services\Email\Blocks\ConditionContentBlock;
use FluentCart\App\Services\Email\Blocks\ConditionFallbackBlock;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\FluentCartBlockEditorHandler;
use FluentCart\App\Services\TemplateService;
use FluentCart\Framework\Support\Arr;

/**
 * Gutenberg Block Parser for Email
 * Converts Gutenberg blocks to email-compatible HTML
 */
class FluentBlockParser
{

    private $data = [];

    public function __construct($data = [])
    {
        $this->data = $data;
    }

    private function isEmailEditorBlock($blockName)
    {
        foreach (FluentCartBlockEditorHandler::getEmailEditorBlocks() as $blockClass) {
            if ($blockName === 'fluent-cart/' . $blockClass::getEditorName()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse Gutenberg blocks and convert to email HTML
     *
     * @param string $content The post content with Gutenberg blocks
     * @return string Email-compatible HTML
     */
    public function parse($content)
    {
        $blocks = parse_blocks($content);

        $css = $this->getCommonStyles();

        $content = $this->renderBlocks($blocks, false, true);

        $content = $this->replaceCssVars($content);

        return $css . $content;
    }

    /**
     * Public proxy for renderBlocks — allows block renderers to
     * render nested inner blocks without using reflection.
     *
     * @param array $blocks
     * @return string
     */
    public function renderNestedBlocks(array $blocks): string
    {
        return $this->renderBlocks($blocks, true);
    }

    /**
     * Public proxy for renderBlock — allows block renderers to
     * render a single nested block without using reflection.
     *
     * @param array $block
     * @return string
     */
    public function renderNestedBlock(array $block): string
    {
        return $this->renderBlock($block);
    }

    /**
     * Render blocks to email HTML
     */
    private function renderBlocks($blocks, $nested = false, $isRoot = false)
    {
        $html = '';

        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                // Classic content or unrecognized block
                if (!empty($block['innerHTML'])) {
                    $html .= $this->wrapInTable($block['innerHTML']);
                }
                continue;
            }

            $html .= $this->renderBlock($block, $isRoot);
        }

        return $html;
    }

    /**
     * Render individual block
     */
    private function renderBlock($block, $isRoot = false)
    {
        $blockName = $block['blockName'];
        $attrs = $block['attrs'] ?? [];
        $innerHTML = $block['innerHTML'] ?? '';
        $innerBlocks = $block['innerBlocks'] ?? [];

        // For blocks with innerContent array, reconstruct innerHTML
        if (empty($innerHTML) && !empty($block['innerContent'])) {
            $innerHTML = implode('', array_filter($block['innerContent'], 'is_string'));
        }

        // Handle different block types
        switch ($blockName) {
            case 'core/paragraph':
                return (new ParagraphBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/heading':
                return (new HeadingBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/image':
                return (new ImageBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/list':
                return (new ListBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/list-item':
                return (new ListItemBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/quote':
                return (new QuoteBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/button':
                return (new ButtonBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/buttons':
                return (new ButtonsBlock($attrs, $innerHTML, $innerBlocks, $this, $block))->render();

            case 'core/columns':
                return (new ColumnsBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/column':
                return (new ColumnBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/separator':
                return (new SeparatorBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/spacer':
                return (new SpacerBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/group':
                return (new GroupBlock($attrs, $innerHTML, $innerBlocks, $this))->setIsRoot($isRoot)->render();

            case 'core/cover':
                return (new CoverBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/media-text':
                return (new MediaTextBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/table':
                return (new TableBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/social-links':
                return (new SocialLinksBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/social-link':
                return ''; // Handled by parent social-links

            case 'core/freeform':
            case 'core/html':
                // Classic editor content - render as-is with email-safe wrapper
                return $this->wrapInTable($innerHTML);

            case 'core/preformatted':
                return (new PreformattedBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/code':
                return (new CodeBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/pullquote':
                return (new PullquoteBlock($attrs, $innerHTML, $innerBlocks, $this))->render();

            case 'core/verse':
                return (new VerseBlock($attrs, $innerHTML, $innerBlocks, $this))->render();
            case 'fluent-cart/order-wrapper':
                return $this->renderOrderWrapper($innerBlocks, $attrs, $innerHTML);
            case 'fluent-cart/order-items':
                return $this->renderOrderItemsTable($innerHTML, $attrs);
            case 'fluent-cart/email-order-items':
                return (new OrderItemsLoopBlock($attrs, $innerHTML, $innerBlocks, $this))
                    ->setParserData($this->data)->render();
            case 'fluent-cart/email-subscription-details':
                return (new SubscriptionDetailsLoopBlock($attrs, $innerHTML, $innerBlocks, $this))
                    ->setParserData($this->data)->render();
            case 'fluent-cart/subscription-details':
                return $this->renderOrderSubscriptionsDetails($innerHTML, $attrs);
            case 'fluent-cart/email-license-details':
                return (new LicenseDetailsLoopBlock($attrs, $innerHTML, $innerBlocks, $this))
                    ->setParserData($this->data)->render();
            case 'fluent-cart/email-download-details':
                return (new DownloadDetailsLoopBlock($attrs, $innerHTML, $innerBlocks, $this))
                    ->setParserData($this->data)->render();
            case 'fluent-cart/license-details':
                return $this->renderOrderLicenseDetails($innerHTML, $attrs);
            case 'fluent-cart/download-details':
                return $this->renderOrderDownloadsDetails($innerHTML, $attrs);
            case 'fluent-cart/order-addresses':
                return $this->renderOrderAddressesDetails($innerHTML, $attrs);
            case 'fluent-cart/email-row-header':
                return (new EmailRowBlock($attrs, $innerHTML, $innerBlocks, $this))
                    ->setRowType('header')->render();
            case 'fluent-cart/email-row-body':
                return (new EmailRowBlock($attrs, $innerHTML, $innerBlocks, $this))
                    ->setRowType('body')->render();
            case 'fluent-cart/email-row-footer':
                return (new EmailRowBlock($attrs, $innerHTML, $innerBlocks, $this))
                    ->setRowType('footer')->render();
            case 'fluent-cart/email-order-addresses':
                return (new \FluentCart\App\Services\Email\Blocks\OrderAddressesBlock($attrs, $innerHTML, $innerBlocks, $this))
                    ->setParserData($this->data)->render();
            case 'fluent-cart/email-address-container':
                return (new \FluentCart\App\Services\Email\Blocks\AddressContainerBlock($attrs, $innerHTML, $innerBlocks, $this))
                    ->setParserData($this->data)->render();
            case 'fluent-cart/email-billing-address':
            case 'fluent-cart/email-shipping-address':
                return $this->renderBlocks($innerBlocks, true);
            case 'fluent-cart/email-header':
            case 'fluent-cart/email-order-summary':
            case 'fluent-cart/email-order-totals':
                return $this->renderEmailWrapperBlock($innerBlocks, $attrs);
            case 'fluent-cart/shortcode-condition':
                return (new ShortcodeConditionBlock($attrs, $innerHTML, $innerBlocks, $this))
                    ->setParserData($this->data)->render();
            case 'fluent-cart/condition-content':
                return (new ConditionContentBlock($attrs, $innerHTML, $innerBlocks, $this))->render();
            case 'fluent-cart/condition-fallback':
                return (new ConditionFallbackBlock($attrs, $innerHTML, $innerBlocks, $this))->render();
            case 'fluent-cart/celebration':
                return $this->renderCelebration($attrs);
            default:
                if ($this->isEmailEditorBlock($blockName)) {
                    return render_block($block);
                }

                // Allow third-party blocks to render via action hook
                ob_start();
                do_action('fluent_cart/block_editor/render_block', [
                    'block'=> $block, 
                    'block_name'=>$blockName, 
                    'attributes'=>$attrs
                ]);
                $thirdPartyContent = ob_get_clean();
                if (!empty($thirdPartyContent)) {
                    return $thirdPartyContent;
                }

                // Fallback for unrecognized blocks
                if (!empty($innerHTML)) {
                    return $this->wrapInTable($innerHTML);
                } elseif (!empty($innerBlocks)) {
                    return $this->renderBlocks($innerBlocks, true);
                }
                return '';
        }
    }

    public function renderOrderWrapper($innerBlocks, $attrs, $innerHTML)
    {
        $style = $attrs['style'] ?? [];
        $layout = $attrs['layout'] ?? [];
        $backgroundColor = '';

        if (!empty($style['color']['background'])) {
            $backgroundColor = "background-color: {$style['color']['background']};";
        }

        $groupStyles = "padding: 20px; {$backgroundColor}";

        // Check if it's a flex layout
        $isFlex = isset($layout['type']) && $layout['type'] === 'flex';

        if ($isFlex) {
            $groupStyles .= " display: flex; flex-wrap: wrap; gap: 10px;";
            $justifyContent = $layout['justifyContent'] ?? 'flex-start';
            $groupStyles .= " justify-content: {$justifyContent};";
        }

        $content = '';
        if (!empty($innerBlocks)) {
            $content = $this->renderBlocks($innerBlocks, true);
        } elseif (!empty($innerHTML)) {
            $content = $innerHTML;
        }

        if (empty(trim($content))) {
            return '';
        }

        return "<table class=\"fct_order_wrapper\" role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\">
    <tr>
        <td style=\"padding: 0;\">
            {$content}
        </td>
    </tr>
</table>";
    }

    public function renderOrderItemsTable($innerHTML, $attrs)
    {
        return '{{order.items_table}}';
    }


    public function renderOrderSubscriptionsDetails($innerHTML, $attrs)
    {
        return '{{order.subscriptions_details}}';
    }

    public function renderOrderLicenseDetails($innerHTML, $attrs)
    {
        return '{{order.license_details}}';
    }

    public function renderOrderDownloadsDetails($innerHTML, $attrs)
    {
        return '{{order.download_details}}';
    }

    public function renderOrderAddressesDetails($innerHTML, $attrs)
    {
        return '{{order.address_details}}';
    }

    public function renderEmailHeader($innerHTML, $attrs)
    {
        return App::make('view')->make('emails.parts.order_header', $this->data);
    }

    /**
     * Render an email wrapper block (email-header, email-order-summary, etc.)
     * with its own style attributes (background, border, padding) applied.
     */
    private function renderEmailWrapperBlock($innerBlocks, $attrs)
    {
        $content = $this->renderBlocks($innerBlocks, true);

        if (empty(trim($content))) {
            return '';
        }

        // Build inline styles from the wrapper block's own attributes
        $style = Arr::get($attrs, 'style', []);
        $tdStyles = '';

        // Background color (preset or inline)
        if (!empty($attrs['backgroundColor'])) {
            $tdStyles .= 'background-color: ' . $this->getColorFromSlug($attrs['backgroundColor']) . ';';
        } elseif (!empty($style['color']['background'])) {
            $tdStyles .= 'background-color: ' . $this->getColorFromSlug($style['color']['background']) . ';';
        }

        // Gradient
        if (!empty($style['color']['gradient'])) {
            $tdStyles .= ' background: ' . $style['color']['gradient'] . ';';
        }

        // Detect if wrapper has visual styling (background/border/gradient)
        $hasBackground = !empty($attrs['backgroundColor'])
            || !empty($style['color']['background'])
            || !empty($style['color']['gradient']);
        $hasBorder = !empty($style['border']) || !empty($attrs['borderColor']);

        // Padding
        $padding = Arr::get($style, 'spacing.padding', []);
        if (!empty($padding)) {
            if (is_string($padding)) {
                $tdStyles .= " padding: {$this->resolveSpacingValue($padding)};";
            } elseif (is_array($padding)) {
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    if (isset($padding[$side])) {
                        $tdStyles .= " padding-{$side}: {$this->resolveSpacingValue($padding[$side])};";
                    }
                }
            }
        } elseif ($hasBackground || $hasBorder) {
            // Add default padding so background/border is visible around inner content
            $tdStyles .= ' padding: 8px;';
        }

        // Margin
        $margin = Arr::get($style, 'spacing.margin', []);
        if (!empty($margin)) {
            if (is_string($margin)) {
                $tdStyles .= " margin: {$this->resolveSpacingValue($margin)};";
            } elseif (is_array($margin)) {
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    if (isset($margin[$side])) {
                        $tdStyles .= " margin-{$side}: {$this->resolveSpacingValue($margin[$side])};";
                    }
                }
            }
        }

        // Border
        $border = Arr::get($style, 'border', []);
        if (!empty($border) || !empty($attrs['borderColor'])) {
            $hasShorthand = isset($border['width']) || isset($border['color']) || !empty($attrs['borderColor']);
            if ($hasShorthand) {
                $tdStyles .= ' border-width: ' . ($border['width'] ?? '1px') . ';';
                $tdStyles .= ' border-style: ' . ($border['style'] ?? 'solid') . ';';
                if (isset($border['color'])) {
                    $tdStyles .= ' border-color: ' . $this->getColorFromSlug($border['color']) . ';';
                }
                if (isset($attrs['borderColor'])) {
                    $tdStyles .= ' border-color: ' . $this->getColorFromSlug($attrs['borderColor']) . ';';
                }
            } elseif (isset($border['style'])) {
                $tdStyles .= " border-style: {$border['style']};";
            }

            // Border radius
            if (isset($border['radius'])) {
                $radius = $border['radius'];
                if (is_string($radius)) {
                    $tdStyles .= " border-radius: {$radius};";
                } elseif (is_array($radius)) {
                    $map = ['topLeft' => 'top-left', 'topRight' => 'top-right', 'bottomLeft' => 'bottom-left', 'bottomRight' => 'bottom-right'];
                    foreach ($map as $key => $css) {
                        if (isset($radius[$key])) {
                            $tdStyles .= " border-{$css}-radius: {$radius[$key]};";
                        }
                    }
                }
            }

            // Per-side borders
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                if (isset($border[$side])) {
                    $s = $border[$side];
                    $w = $s['width'] ?? '1px';
                    $bs = $s['style'] ?? 'solid';
                    $c = isset($s['color']) ? $this->getColorFromSlug($s['color']) : '#000';
                    $tdStyles .= " border-{$side}: {$w} {$bs} {$c};";
                }
            }
        }

        // If no styles were applied, return content directly
        if (empty(trim($tdStyles))) {
            return $content;
        }

        return "<div style=\"{$tdStyles}\">{$content}</div>";
    }

    /**
     * Render celebration block directly for email (avoids render_block CSS bloat).
     */
    private function renderCelebration($attrs)
    {
        $celebrationType = Arr::get($attrs, 'celebration_type', 'order');
        if (!in_array($celebrationType, ['order', 'renewal'], true)) {
            $celebrationType = 'order';
        }

        $text = TemplateService::getCelebration($celebrationType);

        if (empty($text)) {
            return '';
        }

        return App::make('view')->make('emails.parts.celebration', [
            'text' => $text
        ]);
    }

    /**
     * Render paragraph block
     */
    private function renderParagraph($block, $content, $attrs)
    {
        $content = trim($content);

        // Skip empty paragraphs
        if (empty($content) || $content === '<p></p>') {
            //  return '';
        }

        // Extract content if it's wrapped in <p> tags
        if (preg_match('/<p[^>]*>(.*?)<\/p>/s', $content, $matches)) {
            $innerContent = $matches[1];
        } else {
            $innerContent = $content;
        }

        if (!$innerContent) {
            // return '';
        }

        // Build styles from attrs
        $style = $attrs['style'] ?? [];
        $paragraphStyles = "display: block; margin: 0; font-size: inherit; line-height: inherit;";
        $wrapperStyles = "padding: 0;";

        // Handle text color
        $textColor = null;
        if (isset($attrs['textColor'])) {
            $textColor = $this->getColorFromSlug($attrs['textColor']);
            $paragraphStyles .= " color: {$textColor};";
        }
        if (!empty($style['color']['text'])) {
            $textColor = $this->getColorFromSlug($style['color']['text']);
            $paragraphStyles .= " color: {$textColor};";
        }

        // Handle background color
        if (isset($attrs['backgroundColor'])) {
            $wrapperStyles .= " background-color: {$this->getColorFromSlug($attrs['backgroundColor'])};";
        }
        if (!empty($style['color']['background'])) {
            $wrapperStyles .= " background-color: {$this->getColorFromSlug($style['color']['background'])};";
        }

        // Handle gradient background
        if (!empty($style['color']['gradient'])) {
            $wrapperStyles .= " background: {$style['color']['gradient']};";
        }

        // Handle typography
        if (isset($style['typography'])) {
            $typography = $style['typography'];

            if (isset($typography['fontSize'])) {
                $paragraphStyles .= " font-size: {$typography['fontSize']};";
            }
            if (isset($typography['fontFamily'])) {
                $paragraphStyles .= " font-family: {$typography['fontFamily']};";
            }
            if (isset($typography['fontWeight'])) {
                $paragraphStyles .= " font-weight: {$typography['fontWeight']};";
            }
            if (isset($typography['lineHeight'])) {
                $paragraphStyles .= " line-height: {$typography['lineHeight']};";
            }
            if (isset($typography['textTransform'])) {
                $paragraphStyles .= " text-transform: {$typography['textTransform']};";
            }
            if (isset($typography['letterSpacing'])) {
                $paragraphStyles .= " letter-spacing: {$typography['letterSpacing']};";
            }
            if (isset($typography['textDecoration'])) {
                $paragraphStyles .= " text-decoration: {$typography['textDecoration']};";
            }
            if (isset($typography['fontStyle'])) {
                $paragraphStyles .= " font-style: {$typography['fontStyle']};";
            }
        }

        // Handle font size preset
        if (isset($attrs['fontSize'])) {
            $fontSize = $this->getFontSizeFromSlug($attrs['fontSize']);
            $paragraphStyles .= " font-size: {$fontSize};";
        }

        // Handle text alignment
        if (isset($attrs['align'])) {
            $paragraphStyles .= " text-align: {$attrs['align']};";
        }

        // Handle spacing (padding)
        if (isset($style['spacing']['padding'])) {
            $padding = $style['spacing']['padding'];
            if (is_string($padding)) {
                $wrapperStyles .= " padding: {$padding};";
            } elseif (is_array($padding)) {
                $paddingParts = [];
                if (isset($padding['top'])) $paddingParts[] = "padding-top: {$padding['top']};";
                if (isset($padding['right'])) $paddingParts[] = "padding-right: {$padding['right']};";
                if (isset($padding['bottom'])) $paddingParts[] = "padding-bottom: {$padding['bottom']};";
                if (isset($padding['left'])) $paddingParts[] = "padding-left: {$padding['left']};";
                $wrapperStyles .= " " . implode(" ", $paddingParts);
            }
        }

        // Handle spacing (margin)
        if (isset($style['spacing']['margin'])) {
            $margin = $style['spacing']['margin'];
            if (is_string($margin)) {
                $paragraphStyles .= " margin: {$margin};";
            } elseif (is_array($margin)) {
                if (isset($margin['top'])) $paragraphStyles .= " margin-top: {$margin['top']};";
                if (isset($margin['right'])) $paragraphStyles .= " margin-right: {$margin['right']};";
                if (isset($margin['bottom'])) $paragraphStyles .= " margin-bottom: {$margin['bottom']};";
                if (isset($margin['left'])) $paragraphStyles .= " margin-left: {$margin['left']};";
            }
        }

        // Handle border
        if (isset($style['border'])) {
            $border = $style['border'];

            if (isset($border['width'])) {
                $wrapperStyles .= " border-width: {$border['width']};";
            }
            if (isset($border['style'])) {
                $wrapperStyles .= " border-style: {$border['style']};";
            }
            if (isset($border['color'])) {
                $wrapperStyles .= " border-color: {$this->getColorFromSlug($border['color'])};";
            }
            if (isset($border['radius'])) {
                $borderRadius = $border['radius'];
                if (is_string($borderRadius)) {
                    $wrapperStyles .= " border-radius: {$borderRadius};";
                } elseif (is_array($borderRadius)) {
                    if (isset($borderRadius['topLeft'])) $wrapperStyles .= " border-top-left-radius: {$borderRadius['topLeft']};";
                    if (isset($borderRadius['topRight'])) $wrapperStyles .= " border-top-right-radius: {$borderRadius['topRight']};";
                    if (isset($borderRadius['bottomLeft'])) $wrapperStyles .= " border-bottom-left-radius: {$borderRadius['bottomLeft']};";
                    if (isset($borderRadius['bottomRight'])) $wrapperStyles .= " border-bottom-right-radius: {$borderRadius['bottomRight']};";
                }
            }

            // Individual borders
            if (isset($border['top'])) {
                $wrapperStyles .= " border-top: {$border['top']['width']} {$border['top']['style']} {$this->getColorFromSlug($border['top']['color'])};";
            }
            if (isset($border['right'])) {
                $wrapperStyles .= " border-right: {$border['right']['width']} {$border['right']['style']} {$this->getColorFromSlug($border['right']['color'])};";
            }
            if (isset($border['bottom'])) {
                $wrapperStyles .= " border-bottom: {$border['bottom']['width']} {$border['bottom']['style']} {$this->getColorFromSlug($border['bottom']['color'])};";
            }
            if (isset($border['left'])) {
                $wrapperStyles .= " border-left: {$border['left']['width']} {$border['left']['style']} {$this->getColorFromSlug($border['left']['color'])};";
            }
        }

        // Handle borderColor attribute
        if (isset($attrs['borderColor'])) {
            $wrapperStyles .= " border-color: {$this->getColorFromSlug($attrs['borderColor'])};";
        }

        // Handle link colors
        $linkColor = null;
        if (isset($style['elements']['link']['color']['text'])) {
            $linkColorValue = $style['elements']['link']['color']['text'];
            $linkColor = $this->getColorFromSlug($linkColorValue);
        }

        // If no specific link color, use text color
        if (!$linkColor && $textColor) {
            $linkColor = $textColor;
        }

        // Apply link styles to anchor tags
        if ($linkColor) {
            $innerContent = preg_replace_callback(
                    '/<a([^>]*)>/i',
                    function($matches) use ($linkColor) {
                        $existingAttrs = $matches[1];
                        if (preg_match('/style=["\']([^"\']*)["\']/', $existingAttrs, $styleMatch)) {
                            $existingStyle = $styleMatch[1];
                            $newStyle = $existingStyle . " color: {$linkColor};";
                            return '<a' . preg_replace('/style=["\'][^"\']*["\']/', 'style="' . $newStyle . '"', $existingAttrs) . '>';
                        } else {
                            return '<a' . $existingAttrs . ' style="color: ' . $linkColor . ';">';
                        }
                    },
                    $innerContent
            );
        }

        $classAttr = 'fluent-paragraph';

        return "<table role=\"presentation\" class=\"{$classAttr}\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\">
    <tr>
        <td style=\"{$wrapperStyles}\">
            <p style=\"{$paragraphStyles}\">{$innerContent}</p>
        </td>
    </tr>
</table>";
    }

    /**
     * Render heading block
     */
    private function renderHeading($content, $attrs)
    {
        $level = $attrs['level'] ?? 2;
        $align = $attrs['align'] ?? 'left';
        $style = $attrs['style'] ?? [];

        // Default font sizes
        $defaultFontSize = [
            1 => '32px',
            2 => '28px',
            3 => '24px',
            4 => '20px',
            5 => '18px',
            6 => '16px'
        ][$level] ?? '24px';

        // Base styles
        $styles = "margin: 0 0 16px 0; padding: 0; font-weight: bold;";
        $styles .= " font-size: {$defaultFontSize}; line-height: 1.3;";
        $styles .= " text-align: {$align};";

        // Handle text color
        $textColor = null;
        if (isset($attrs['textColor'])) {
            $textColor = $this->getColorFromSlug($attrs['textColor']);
            $styles .= " color: {$textColor};";
        }
        if (!empty($style['color']['text'])) {
            $textColor = $this->getColorFromSlug($style['color']['text']);
            $styles .= " color: {$textColor};";
        }

        // Handle background color
        if (isset($attrs['backgroundColor'])) {
            $styles .= " background-color: {$this->getColorFromSlug($attrs['backgroundColor'])};";
        }
        if (!empty($style['color']['background'])) {
            $styles .= " background-color: {$this->getColorFromSlug($style['color']['background'])};";
        }

        // Handle gradient background
        if (!empty($style['color']['gradient'])) {
            $styles .= " background: {$style['color']['gradient']};";
            $styles .= " -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;";
        }

        // Handle typography
        if (isset($style['typography'])) {
            $typography = $style['typography'];

            if (isset($typography['fontSize'])) {
                $styles .= " font-size: {$typography['fontSize']};";
            }
            if (isset($typography['fontFamily'])) {
                $styles .= " font-family: {$typography['fontFamily']};";
            }
            if (isset($typography['fontWeight'])) {
                $styles .= " font-weight: {$typography['fontWeight']};";
            }
            if (isset($typography['lineHeight'])) {
                $styles .= " line-height: {$typography['lineHeight']};";
            }
            if (isset($typography['textTransform'])) {
                $styles .= " text-transform: {$typography['textTransform']};";
            }
            if (isset($typography['letterSpacing'])) {
                $styles .= " letter-spacing: {$typography['letterSpacing']};";
            }
            if (isset($typography['textDecoration'])) {
                $styles .= " text-decoration: {$typography['textDecoration']};";
            }
            if (isset($typography['fontStyle'])) {
                $styles .= " font-style: {$typography['fontStyle']};";
            }
        }

        // Handle font size preset (using theme font sizes)
        if (isset($attrs['fontSize'])) {
            $fontSize = $this->getFontSizeFromSlug($attrs['fontSize']);
            $styles .= " font-size: {$fontSize};";
        }

        // Handle spacing (padding)
        if (isset($style['spacing']['padding'])) {
            $padding = $style['spacing']['padding'];
            if (is_string($padding)) {
                $styles .= " padding: {$padding};";
            } elseif (is_array($padding)) {
                if (isset($padding['top'])) $styles .= " padding-top: {$padding['top']};";
                if (isset($padding['right'])) $styles .= " padding-right: {$padding['right']};";
                if (isset($padding['bottom'])) $styles .= " padding-bottom: {$padding['bottom']};";
                if (isset($padding['left'])) $styles .= " padding-left: {$padding['left']};";
            }
        }

        // Handle spacing (margin)
        if (isset($style['spacing']['margin'])) {
            $margin = $style['spacing']['margin'];
            if (is_string($margin)) {
                $styles .= " margin: {$margin};";
            } elseif (is_array($margin)) {
                if (isset($margin['top'])) $styles .= " margin-top: {$margin['top']};";
                if (isset($margin['right'])) $styles .= " margin-right: {$margin['right']};";
                if (isset($margin['bottom'])) $styles .= " margin-bottom: {$margin['bottom']};";
                if (isset($margin['left'])) $styles .= " margin-left: {$margin['left']};";
            }
        }

        // Handle border
        if (isset($style['border'])) {
            $border = $style['border'];

            // Border width
            if (isset($border['width'])) {
                $styles .= " border-width: {$border['width']};";
            }

            // Border style
            if (isset($border['style'])) {
                $styles .= " border-style: {$border['style']};";
            }

            // Border color
            if (isset($border['color'])) {
                $styles .= " border-color: {$this->getColorFromSlug($border['color'])};";
            }

            // Border radius
            if (isset($border['radius'])) {
                $borderRadius = $border['radius'];
                if (is_string($borderRadius)) {
                    $styles .= " border-radius: {$borderRadius};";
                } elseif (is_array($borderRadius)) {
                    if (isset($borderRadius['topLeft'])) $styles .= " border-top-left-radius: {$borderRadius['topLeft']};";
                    if (isset($borderRadius['topRight'])) $styles .= " border-top-right-radius: {$borderRadius['topRight']};";
                    if (isset($borderRadius['bottomLeft'])) $styles .= " border-bottom-left-radius: {$borderRadius['bottomLeft']};";
                    if (isset($borderRadius['bottomRight'])) $styles .= " border-bottom-right-radius: {$borderRadius['bottomRight']};";
                }
            }

            // Individual borders
            if (isset($border['top'])) {
                $styles .= " border-top: {$border['top']['width']} {$border['top']['style']} {$this->getColorFromSlug($border['top']['color'])};";
            }
            if (isset($border['right'])) {
                $styles .= " border-right: {$border['right']['width']} {$border['right']['style']} {$this->getColorFromSlug($border['right']['color'])};";
            }
            if (isset($border['bottom'])) {
                $styles .= " border-bottom: {$border['bottom']['width']} {$border['bottom']['style']} {$this->getColorFromSlug($border['bottom']['color'])};";
            }
            if (isset($border['left'])) {
                $styles .= " border-left: {$border['left']['width']} {$border['left']['style']} {$this->getColorFromSlug($border['left']['color'])};";
            }
        }

        // Handle borderColor attribute
        if (isset($attrs['borderColor'])) {
            $styles .= " border-color: {$this->getColorFromSlug($attrs['borderColor'])};";
        }

        // Handle text shadow
        if (isset($style['typography']['textShadow'])) {
            $styles .= " text-shadow: {$style['typography']['textShadow']};";
        }

        // Handle custom CSS
        if (isset($style['css'])) {
            $styles .= " {$style['css']};";
        }

        // Extract content if it's wrapped in heading tags
        if (preg_match('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', $content, $matches)) {
            $innerContent = $matches[1];
        } else {
            $innerContent = $content;
        }

        // Handle anchor tags - apply link color styles
        $linkColor = null;
        if (isset($style['elements']['link']['color']['text'])) {
            $linkColor = $this->getColorFromSlug($style['elements']['link']['color']['text']);
        }

        // If no specific link color is set, use the heading text color or a default
        if (!$linkColor && $textColor) {
            $linkColor = $textColor;
        }

        if ($linkColor) {
            // Add inline styles to all anchor tags
            $innerContent = preg_replace_callback(
                    '/<a([^>]*)>/i',
                    function($matches) use ($linkColor) {
                        $existingAttrs = $matches[1];
                        // Check if style attribute already exists
                        if (preg_match('/style=["\']([^"\']*)["\']/', $existingAttrs, $styleMatch)) {
                            // Append to existing style
                            $existingStyle = $styleMatch[1];
                            $newStyle = $existingStyle . " color: {$linkColor}; text-decoration: underline;";
                            return '<a' . preg_replace('/style=["\'][^"\']*["\']/', 'style="' . $newStyle . '"', $existingAttrs) . '>';
                        } else {
                            // Add new style attribute
                            return '<a' . $existingAttrs . ' style="color: ' . $linkColor . '; text-decoration: underline;">';
                        }
                    },
                    $innerContent
            );
        }

        return $this->wrapInTable("<h{$level} style=\"{$styles}\">{$innerContent}</h{$level}>");
    }

    /**
     * Render image block
     */
    private function renderImage($attrs, $innerHTML = '')
    {
        $url = $attrs['url'] ?? '';
        $alt = $attrs['alt'] ?? '';
        $width = $attrs['width'] ?? '';
        $height = $attrs['height'] ?? '';
        $align = $attrs['align'] ?? 'center';
        $id = $attrs['id'] ?? '';
        $sizeSlug = $attrs['sizeSlug'] ?? '';

        // If URL is not in attrs, try to extract from innerHTML
        if (empty($url) && !empty($innerHTML)) {
            if (preg_match('/src=["\']([^"\']+)["\']/', $innerHTML, $matches)) {
                $url = $matches[1];
            }
        }

        // Extract alt if not in attrs
        if (empty($alt) && !empty($innerHTML)) {
            if (preg_match('/alt=["\']([^"\']*)["\']/', $innerHTML, $matches)) {
                $alt = $matches[1];
            }
        }

        // Extract caption if present
        $caption = '';
        if (!empty($innerHTML) && preg_match('/<figcaption[^>]*>(.*?)<\/figcaption>/s', $innerHTML, $captionMatch)) {
            $caption = wp_strip_all_tags($captionMatch[1]);
        }

        if (empty($url)) {
            return '';
        }

        // Build image styles - preserve aspect ratio
        $imgStyles = "display: block; max-width: 100%; height: auto; border: 0;";

        // Don't set fixed width/height to preserve aspect ratio
        // Let the image scale naturally

        $alignStyle = $align === 'center' ? 'margin: 0 auto;' : '';
        $textAlign = $align === 'center' ? 'center' : ($align === 'right' ? 'right' : 'left');

        $img = "<img src=\"{$url}\" alt=\"{$alt}\" style=\"{$imgStyles}\" />";

        $html = "<div style=\"text-align: {$textAlign}; {$alignStyle} margin-bottom: 16px;\">{$img}";

        // Add caption if present
        if (!empty($caption)) {
            $html .= "<p style=\"margin: 8px 0 0 0; font-size: 14px; color: #666; font-style: italic; text-align: {$textAlign};\">{$caption}</p>";
        }

        $html .= "</div>";

        return $this->wrapInTable($html);
    }

    /**
     * Render list block
     */
    private function renderList($content, $innerBlocks, $attrs)
    {
        $ordered = $attrs['ordered'] ?? false;
        $tag = $ordered ? 'ol' : 'ul';

        $styles = "margin: 0 0 16px 0; padding-left: 30px; line-height: 1.6;";

        // If we have innerBlocks, render them
        if (!empty($innerBlocks)) {
            $listItems = '';
            foreach ($innerBlocks as $block) {
                if ($block['blockName'] === 'core/list-item') {
                    $listItems .= $this->renderListItem($block['innerHTML'], $block['attrs'] ?? []);
                }
            }
            return $this->wrapInTable("<{$tag} style=\"{$styles}\">{$listItems}</{$tag}>");
        }

        // Otherwise, use innerHTML
        if (preg_match('/<(ul|ol)[^>]*>(.*?)<\/(ul|ol)>/s', $content, $matches)) {
            $innerContent = $matches[2];
        } else {
            $innerContent = $content;
        }

        return $this->wrapInTable("<{$tag} style=\"{$styles}\">{$innerContent}</{$tag}>");
    }

    /**
     * Render list item
     */
    private function renderListItem($content, $attrs)
    {
        $styles = "margin-bottom: 8px;";

        // Extract content if wrapped in <li> tags
        if (preg_match('/<li[^>]*>(.*?)<\/li>/s', $content, $matches)) {
            $innerContent = $matches[1];
        } else {
            $innerContent = $content;
        }

        return "<li style=\"{$styles}\">{$innerContent}</li>";
    }

    /**
     * Render quote block
     */
    private function renderQuote($content, $attrs)
    {
        $styles = "margin: 20px 0; padding: 15px 20px; border-left: 4px solid #ccc;";
        $styles .= " background-color: #f9f9f9; font-style: italic;";

        return $this->wrapInTable("<blockquote style=\"{$styles}\">{$content}</blockquote>");
    }

    /**
     * Render buttons container
     */
    private function renderButtons($block, $innerBlocks, $attrs)
    {

        // dd($attrs);

        $content = Arr::get($block, 'innerContent.0', '');

        // get style attribute value from p
        $styleAttr = '';
        if (preg_match('/<div[^>]*style=["\']([^"\']*)["\'][^>]*>/s', $content, $styleMatch)) {
            $styleAttr = $styleMatch[1];
        }

        $classAttr = '';
        if (preg_match('/<div[^>]*class=["\']([^"\']*)["\'][^>]*>/s', $content, $classMatch)) {
            $classAttr = $classMatch[1];
        }

        $justifyContent = Arr::get($attrs, 'layout.justifyContent');

        if ($justifyContent === 'center') {
            $classAttr .= ' has-text-align-center';
        } else if ($justifyContent === 'right') {
            $classAttr .= ' has-text-align-right';
        }


        $buttonsHtml = '';
        foreach ($innerBlocks as $button) {
            if ($button['blockName'] === 'core/button') {
                $buttonsHtml .= $this->renderButton($button['innerHTML'], $button['attrs'] ?? []);
            }
        }


        return "<table role=\"presentation\" class=\"{$classAttr} fluent_buttons\" style=\"{$styleAttr}\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\">
    <tr>
        <td style=\"padding: 0;\">
            {$buttonsHtml}
        </td>
    </tr>
</table>";

        return $this->wrapInTable("<div style=\"text-align: {$textAlign}; margin: 0 0;\">{$buttonsHtml}</div>");
    }

    /**
     * Render button block
     */
    private function renderButton($content, $attrs)
    {
        // Extract URL and text from content
        $url = '#';
        $text = 'Button';

        if (!empty($content)) {
            // Try to extract from anchor tag
            if (preg_match('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/s', $content, $matches)) {
                $url = $matches[1];
                $rawText = $matches[2];
                // Remove all HTML tags but keep the text
                $text = trim(preg_replace('/<[^>]*>/', '', $rawText));
            } elseif (preg_match('/<a[^>]*>(.*?)<\/a>/s', $content, $matches)) {
                $rawText = $matches[1];
                // Remove all HTML tags but keep the text
                $text = trim(preg_replace('/<[^>]*>/', '', $rawText));
                // URL stays as '#' (default)
            }
        }

        // Fallback to attrs if extraction failed
        if ($url === '#' && !empty($attrs['url'])) {
            $url = $attrs['url'];
        }
        if ($text === 'Button' && !empty($attrs['text'])) {
            $text = $attrs['text'];
        }

        // If text is still empty, use default
        if (empty($text)) {
            $text = 'Button';
        }


        $style = $attrs['style'] ?? [];
        $className = $attrs['className'] ?? '';

        // Default button styles
        $buttonStyles = "display: inline-block; text-align:center; padding: 12px 24px; text-decoration: none; font-weight: bold;";

        // Default colors
        $bgColor = '#0073aa';
        $txtColor = '#ffffff';

        // Handle background color
        if (isset($attrs['backgroundColor'])) {
            $bgColor = $this->getColorFromSlug($attrs['backgroundColor']);
        }
        if (!empty($style['color']['background'])) {
            $bgColor = $this->getColorFromSlug($style['color']['background']);
        }

        // Handle text color
        if (isset($attrs['textColor'])) {
            $txtColor = $this->getColorFromSlug($attrs['textColor']);
        }
        if (!empty($style['color']['text'])) {
            $txtColor = $this->getColorFromSlug($style['color']['text']);
        }

        // Handle gradient background
        $hasGradient = false;
        if (!empty($style['color']['gradient'])) {
            $hasGradient = true;
            $bgColor = $style['color']['gradient'];
        }


        // Check if it's an outline button
        $isOutline = strpos($className, 'is-style-outline') !== false;

        // Apply base background and text color
        if ($isOutline) {
            $buttonStyles .= " background-color: transparent; color: {$txtColor};";
            $buttonStyles .= " border: 2px solid {$txtColor};";
        } else {
            if ($hasGradient) {
                $buttonStyles .= " background: {$bgColor}; color: {$txtColor};";
            } else {
                $buttonStyles .= " background-color: {$bgColor}; color: {$txtColor};";
            }
        }

        // Handle typography
        if (isset($style['typography'])) {
            $typography = $style['typography'];

            if (isset($typography['fontSize'])) {
                $buttonStyles .= " font-size: {$typography['fontSize']};";
            }
            if (isset($typography['fontFamily'])) {
                $buttonStyles .= " font-family: {$typography['fontFamily']};";
            }
            if (isset($typography['fontWeight'])) {
                $buttonStyles .= " font-weight: {$typography['fontWeight']};";
            }
            if (isset($typography['lineHeight'])) {
                $buttonStyles .= " line-height: {$typography['lineHeight']};";
            }
            if (isset($typography['textTransform'])) {
                $buttonStyles .= " text-transform: {$typography['textTransform']};";
            }
            if (isset($typography['letterSpacing'])) {
                $buttonStyles .= " letter-spacing: {$typography['letterSpacing']};";
            }
            if (isset($typography['textDecoration'])) {
                $buttonStyles .= " text-decoration: {$typography['textDecoration']};";
            }
        }

        // Handle font size preset
        if (isset($attrs['fontSize'])) {
            $fontSize = $this->getFontSizeFromSlug($attrs['fontSize']);
            $buttonStyles .= " font-size: {$fontSize};";
        }

        // Handle border
        if (isset($style['border'])) {
            $border = $style['border'];

            if (isset($border['width']) && !$isOutline) {
                $buttonStyles .= " border-width: {$border['width']};";
            }
            if (isset($border['style']) && !$isOutline) {
                $buttonStyles .= " border-style: {$border['style']};";
            }
            if (isset($border['color']) && !$isOutline) {
                $buttonStyles .= " border-color: {$this->getColorFromSlug($border['color'])};";
            }

            // Border radius
            if (isset($border['radius'])) {
                $borderRadius = $border['radius'];
                if (is_string($borderRadius)) {
                    $buttonStyles .= " border-radius: {$borderRadius};";
                } elseif (is_array($borderRadius)) {
                    if (isset($borderRadius['topLeft'])) $buttonStyles .= " border-top-left-radius: {$borderRadius['topLeft']};";
                    if (isset($borderRadius['topRight'])) $buttonStyles .= " border-top-right-radius: {$borderRadius['topRight']};";
                    if (isset($borderRadius['bottomLeft'])) $buttonStyles .= " border-bottom-left-radius: {$borderRadius['bottomLeft']};";
                    if (isset($borderRadius['bottomRight'])) $buttonStyles .= " border-bottom-right-radius: {$borderRadius['bottomRight']};";
                }
            }
        } else {
            // Default border radius if not specified
            $buttonStyles .= " border-radius: 9999px;";
        }

        // Handle borderColor attribute
        if (isset($attrs['borderColor']) && !$isOutline) {
            $buttonStyles .= " border-color: {$this->getColorFromSlug($attrs['borderColor'])};";
        }

        // Handle spacing (padding)
        if (isset($style['spacing']['padding'])) {
            $padding = $style['spacing']['padding'];
            if (is_string($padding)) {
                $buttonStyles .= " padding: {$padding};";
            } elseif (is_array($padding)) {
                if (isset($padding['top'])) $buttonStyles .= " padding-top: {$padding['top']};";
                if (isset($padding['right'])) $buttonStyles .= " padding-right: {$padding['right']};";
                if (isset($padding['bottom'])) $buttonStyles .= " padding-bottom: {$padding['bottom']};";
                if (isset($padding['left'])) $buttonStyles .= " padding-left: {$padding['left']};";
            }
        }

        // Handle spacing (margin) - though margins on inline elements can be tricky in email
        if (isset($style['spacing']['margin'])) {
            $margin = $style['spacing']['margin'];
            if (is_string($margin)) {
                $buttonStyles .= " margin: {$margin};";
            } elseif (is_array($margin)) {
                if (isset($margin['top'])) $buttonStyles .= " margin-top: {$margin['top']};";
                if (isset($margin['right'])) $buttonStyles .= " margin-right: {$margin['right']};";
                if (isset($margin['bottom'])) $buttonStyles .= " margin-bottom: {$margin['bottom']};";
                if (isset($margin['left'])) $buttonStyles .= " margin-left: {$margin['left']};";
            }
        }

        // Handle box shadow - using the new helper method
        if (isset($style['shadow'])) {
            $shadowValue = $this->getShadowFromSlug($style['shadow']);
            if ($shadowValue !== 'none') {
                $buttonStyles .= " box-shadow: {$shadowValue};";
            }
        }

        // Handle width
        if (isset($attrs['width'])) {
            $buttonStyles .= " width: {$attrs['width']}%;";
        }

        // Wrap button in a table for better email client compatibility
        return "<a href=\"{$url}\" style=\"{$buttonStyles}\">{$text}</a>";
    }

    /**
     * Render columns block
     */
    private function renderColumns($innerBlocks, $attrs)
    {
        if (empty($innerBlocks)) {
            return '';
        }

        $columnCount = count($innerBlocks);
        $columnWidth = floor(100 / $columnCount);

        $columnsHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>';

        foreach ($innerBlocks as $column) {
            $columnsHtml .= '<td width="' . $columnWidth . '%" style="vertical-align: top; padding: 0 10px;">';
            $columnsHtml .= $this->renderBlock($column);
            $columnsHtml .= '</td>';
        }

        $columnsHtml .= '</tr></table>';

        return $columnsHtml;
    }

    /**
     * Render column block
     */
    private function renderColumn($innerBlocks, $attrs, $innerHTML)
    {
        $html = '';

        if (!empty($innerBlocks)) {
            $html = $this->renderBlocks($innerBlocks, true);
        } elseif (!empty($innerHTML)) {
            $html = $innerHTML;
        }

        return $html;
    }

    /**
     * Render cover block
     */
    private function renderCover($innerBlocks, $attrs, $innerHTML)
    {
        $url = $attrs['url'] ?? '';
        $dimRatio = $attrs['dimRatio'] ?? 50;
        $overlayColor = $attrs['overlayColor'] ?? '';
        $style = $attrs['style'] ?? [];
        $contentPosition = $attrs['contentPosition'] ?? 'center center';
        $minHeight = $attrs['minHeight'] ?? '';
        $minHeightUnit = $attrs['minHeightUnit'] ?? 'px';

        // Extract image URL from innerHTML if not in attrs
        if (empty($url) && !empty($innerHTML)) {
            if (preg_match('/src=["\']([^"\']+)["\']/', $innerHTML, $matches)) {
                $url = $matches[1];
            }
        }

        $opacity = $dimRatio / 100;

        // Parse content position (e.g., "top center", "center center", "bottom left")
        $verticalAlign = 'center';
        $textAlign = 'center';

        if (!empty($contentPosition)) {
            $positions = explode(' ', $contentPosition);
            if (count($positions) >= 2) {
                $verticalAlign = $positions[0]; // top, center, bottom
                $textAlign = $positions[1]; // left, center, right
            } elseif (count($positions) === 1) {
                $verticalAlign = $positions[0];
            }
        }

        // Map vertical alignment to table cell vertical-align
        $vAlignStyle = $verticalAlign === 'top' ? 'top' : ($verticalAlign === 'bottom' ? 'bottom' : 'middle');

        // Determine minimum height
        $minHeightValue = $minHeight ? $minHeight . $minHeightUnit : '300px';

        // Render inner content
        $innerContent = '';
        if (!empty($innerBlocks)) {
            foreach ($innerBlocks as $block) {
                $innerContent .= $this->renderBlock($block);
            }
        }

        // For email, create a table-based layout with background image
        if ($url) {
            $html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 20px 0; background-image: url(\'' . $url . '\'); background-size: cover; background-position: center; min-height: ' . $minHeightValue . ';">';
            $html .= '<tr>';
            $html .= '<td style="padding: 40px 20px; vertical-align: ' . $vAlignStyle . '; text-align: ' . $textAlign . '; background-color: rgba(0,0,0,' . $opacity . '); color: #ffffff; min-height: ' . $minHeightValue . ';">';
            $html .= $innerContent;
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';
            return $html;
        }

        return $this->wrapInTable($innerContent);
    }

    /**
     * Render separator block
     */
    private function renderSeparator($attrs)
    {
        $styles = "border: none; border-top: 1px solid #ccc; margin: 30px 0;";

        return $this->wrapInTable("<hr style=\"{$styles}\" />");
    }

    /**
     * Render spacer block
     */
    private function renderSpacer($attrs)
    {
        $height = $attrs['height'] ?? 50;

        return $this->wrapInTable("<div style=\"height: {$height}px;\"></div>");
    }

    /**
     * Render group block
     */
    private function renderGroup($innerBlocks, $attrs, $innerHTML, $isRoot = false)
    {
        $style = $attrs['style'] ?? [];
        $layout = $attrs['layout'] ?? [];
        $tdStyles = '';

        if (!empty($style['color']['background'])) {
            $tdStyles .= "background-color: {$style['color']['background']};";
        }

        if (!empty($style['border']['radius'])) {
            $tdStyles .= "border-radius: {$style['border']['radius']};";
        }

        if (!empty($style['spacing']['padding'])) {
            $padding = $style['spacing']['padding'];
            if (!empty($padding['top'])) {
                $tdStyles .= "padding-top: {$padding['top']};";
            }
            if (!empty($padding['bottom'])) {
                $tdStyles .= "padding-bottom: {$padding['bottom']};";
            }
            if (!empty($padding['left'])) {
                $tdStyles .= "padding-left: {$padding['left']};";
            }
            if (!empty($padding['right'])) {
                $tdStyles .= "padding-right: {$padding['right']};";
            }
        } else {
            $tdStyles .= "padding: 20px;";
        }

        // Check if it's a flex layout
        $isFlex = isset($layout['type']) && $layout['type'] === 'flex';

        if ($isFlex) {
            $tdStyles .= " display: flex; flex-wrap: wrap; gap: 10px;";
            $justifyContent = $layout['justifyContent'] ?? 'flex-start';
            $tdStyles .= " justify-content: {$justifyContent};";
        }

        $content = '';
        if (!empty($innerBlocks)) {
            $content = $this->renderBlocks($innerBlocks, true);
        } elseif (!empty($innerHTML)) {
            $content = $innerHTML;
        }


        if (empty(trim($content))) {
            return '';
        }

        $tableClass = 'fct_inner_group';

        if($isRoot) {
            $tableClass = 'fct_root_group';
        }

        return "<table class=\"{$tableClass}\" role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\">
    <tr>
        <td style=\"{$tdStyles}\">
            {$content}
        </td>
    </tr>
</table>";
    }

    /**
     * Render table block
     */
    private function renderTable($content, $attrs) {

        // Base styles
        $tableStyles = "width: 100%; border-collapse: collapse; margin: 20px 0;";
        $cellStyles = "padding: 12px;";
        $thStyles = "font-weight: bold;";

        // Handle border styles
        if (isset($attrs['style']['border'])) {
            $border = $attrs['style']['border'];
            $borderWidth = $border['width'] ?? '1px';
            $borderStyle = $border['style'] ?? 'solid';
            $borderColor = isset($attrs['borderColor']) ? $this->getColorFromSlug($attrs['borderColor']) : '#ddd';

            $cellStyles .= " border: {$borderWidth} {$borderStyle} {$borderColor};";
        } else {
            $cellStyles .= " border: 1px solid #ddd;";
        }

        // Handle background color
        if (isset($attrs['backgroundColor'])) {
            $tableStyles .= " background-color: {$this->getColorFromSlug($attrs['backgroundColor'])};";
        }
        if (isset($attrs['style']['color']['background'])) {
            $tableStyles .= " background-color: {$this->getColorFromSlug($attrs['style']['color']['background'])};";
        }

        // Handle text color
        if (isset($attrs['textColor'])) {
            $tableStyles .= " color: {$this->getColorFromSlug($attrs['textColor'])};";
        }
        if (isset($attrs['style']['color']['text'])) {
            $tableStyles .= " color: {$this->getColorFromSlug($attrs['style']['color']['text'])};";
        }

        // Handle typography
        if (isset($attrs['style']['typography'])) {
            $typography = $attrs['style']['typography'];

            if (isset($typography['fontSize'])) {
                $tableStyles .= " font-size: {$this->getFontSizeFromSlug($typography['fontSize'])};";
            }
            if (isset($typography['fontFamily'])) {
                $tableStyles .= " font-family: {$typography['fontFamily']};";
            }
            if (isset($typography['fontWeight'])) {
                $tableStyles .= " font-weight: {$typography['fontWeight']};";
            }
            if (isset($typography['lineHeight'])) {
                $tableStyles .= " line-height: {$typography['lineHeight']};";
            }
            if (isset($typography['textTransform'])) {
                $tableStyles .= " text-transform: {$typography['textTransform']};";
            }
            if (isset($typography['letterSpacing'])) {
                $tableStyles .= " letter-spacing: {$typography['letterSpacing']};";
            }
        }

        // Handle font size (direct attribute)
        if (isset($attrs['fontSize'])) {
            $tableStyles .= " font-size: {$this->getFontSizeFromSlug($attrs['fontSize'])}";
        }
        if (isset($attrs['style']['fontSize'])) {
            $tableStyles .= " font-size: {$this->getFontSizeFromSlug($attrs['style']['fontSize'])};";
        }

        // Handle alignment
        if (isset($attrs['align'])) {
            $alignment = $attrs['align'];
            if (in_array($alignment, ['left', 'center', 'right'])) {
                $tableStyles .= " margin-left: " . ($alignment === 'center' ? 'auto' : '0') . ";";
                $tableStyles .= " margin-right: " . ($alignment === 'center' ? 'auto' : '0') . ";";
            }
            if ($alignment === 'wide') {
                $tableStyles .= " width: 100%; max-width: var(--wp--style--global--wide-size, 1280px);";
            }
            if ($alignment === 'full') {
                $tableStyles .= " width: 100%;";
            }
        }

        // Handle spacing (padding)
        if (isset($attrs['style']['spacing']['padding'])) {
            $padding = $attrs['style']['spacing']['padding'];
            if (is_string($padding)) {
                $tableStyles .= " padding: {$padding};";
            } elseif (is_array($padding)) {
                if (isset($padding['top'])) $tableStyles .= " padding-top: {$padding['top']};";
                if (isset($padding['right'])) $tableStyles .= " padding-right: {$padding['right']};";
                if (isset($padding['bottom'])) $tableStyles .= " padding-bottom: {$padding['bottom']};";
                if (isset($padding['left'])) $tableStyles .= " padding-left: {$padding['left']};";
            }
        }

        // Handle spacing (margin)
        if (isset($attrs['style']['spacing']['margin'])) {
            $margin = $attrs['style']['spacing']['margin'];
            if (is_string($margin)) {
                $tableStyles .= " margin: {$margin};";
            } elseif (is_array($margin)) {
                if (isset($margin['top'])) $tableStyles .= " margin-top: {$margin['top']};";
                if (isset($margin['right'])) $tableStyles .= " margin-right: {$margin['right']};";
                if (isset($margin['bottom'])) $tableStyles .= " margin-bottom: {$margin['bottom']};";
                if (isset($margin['left'])) $tableStyles .= " margin-left: {$margin['left']};";
            }
        }

        // Handle hasFixedLayout
        if (isset($attrs['hasFixedLayout']) && $attrs['hasFixedLayout']) {
            $tableStyles .= " table-layout: fixed;";
        }

        // Handle cell alignment for header and body
        $headerAlign = isset($attrs['style']['elements']['th']['typography']['textAlign'])
                ? $attrs['style']['elements']['th']['typography']['textAlign']
                : (isset($attrs['align']) ? 'center' : 'left');

        $bodyAlign = isset($attrs['style']['elements']['td']['typography']['textAlign'])
                ? $attrs['style']['elements']['td']['typography']['textAlign']
                : 'center';

        $cellStyles .= " text-align: {$bodyAlign};";
        $thStyles .= " text-align: {$headerAlign};";

        // Extract table content
        if (preg_match('/<table[^>]*>(.*?)<\/table>/s', $content, $matches)) {
            $tableContent = $matches[1];

            // Add styles to table cells
            $tableContent = preg_replace('/<td([^>]*)>/', '<td$1 style="' . $cellStyles . '">', $tableContent);
            $tableContent = preg_replace('/<th([^>]*)>/', '<th$1 style="' . $cellStyles . ' ' . $thStyles . '">', $tableContent);

            return $this->wrapInTable("<table style=\"{$tableStyles}\">{$tableContent}</table>");
        }

        return $this->wrapInTable($content);
    }

    /**
     * Render social links block
     */
    private function renderSocialLinks($innerBlocks, $attrs, $innerHTML)
    {
        $socialHtml = '<div style="margin: 20px 0; text-align: center;">';

        $socialLinks = [];

        // First, try to get from innerBlocks (preferred method)
        if (!empty($innerBlocks)) {
            foreach ($innerBlocks as $block) {
                if ($block['blockName'] === 'core/social-link') {
                    $blockAttrs = $block['attrs'] ?? [];
                    $blockInner = $block['innerHTML'] ?? '';

                    // Reconstruct innerHTML from innerContent if available
                    if (empty($blockInner) && !empty($block['innerContent'])) {
                        $blockInner = implode('', array_filter($block['innerContent'], 'is_string'));
                    }

                    $url = $blockAttrs['url'] ?? '';
                    $service = $blockAttrs['service'] ?? 'link';
                    $label = $blockAttrs['label'] ?? '';

                    // If URL is empty, try to extract from innerHTML
                    if (empty($url) && !empty($blockInner)) {
                        if (preg_match('/<a[^>]*href=["\']([^"\']*)["\']/', $blockInner, $urlMatch)) {
                            $url = $urlMatch[1];
                        }
                    }

                    // Extract label from aria-label or innerHTML
                    if (empty($label) && !empty($blockInner)) {
                        if (preg_match('/aria-label=["\']([^"\']*)["\']/', $blockInner, $labelMatch)) {
                            $label = $labelMatch[1];
                        }
                    }

                    // Determine service from URL if not set
                    if ($service === 'link' && !empty($url)) {
                        if (strpos($url, 'wordpress.org') !== false || strpos($url, 'wordpress.com') !== false) {
                            $service = 'wordpress';
                        } elseif (strpos($url, 'facebook.com') !== false) {
                            $service = 'facebook';
                        } elseif (strpos($url, 'github.com') !== false) {
                            $service = 'github';
                        } elseif (strpos($url, 'twitter.com') !== false || strpos($url, 'x.com') !== false) {
                            $service = 'twitter';
                        } elseif (strpos($url, 'linkedin.com') !== false) {
                            $service = 'linkedin';
                        } elseif (strpos($url, 'instagram.com') !== false) {
                            $service = 'instagram';
                        } elseif (strpos($url, 'amazon.com') !== false) {
                            $service = 'amazon';
                        }
                    }

                    if (!empty($url)) {
                        $socialLinks[] = [
                            'url'     => $url,
                            'service' => $service,
                            'label'   => $label
                        ];
                    }
                }
            }
        }

        // Fallback: Parse social links from innerHTML
        if (empty($socialLinks) && !empty($innerHTML)) {
            preg_match_all('/<li[^>]*class="[^"]*wp-social-link[^"]*"[^>]*>.*?<a[^>]*href=["\']([^"\']*)["\'][^>]*(?:aria-label=["\']([^"\']*)["\'])?[^>]*>.*?<\/a>.*?<\/li>/s', $innerHTML, $matches);

            if (!empty($matches[1])) {
                foreach ($matches[0] as $index => $match) {
                    $url = $matches[1][$index];
                    $label = $matches[2][$index] ?? '';

                    // Determine service from class or URL
                    $service = 'link';
                    if (strpos($match, 'wp-social-link-wordpress') !== false || strpos($url, 'wordpress.org') !== false || strpos($url, 'wordpress.com') !== false) {
                        $service = 'wordpress';
                    } elseif (strpos($match, 'wp-social-link-facebook') !== false || strpos($url, 'facebook.com') !== false) {
                        $service = 'facebook';
                    } elseif (strpos($match, 'wp-social-link-github') !== false || strpos($url, 'github.com') !== false) {
                        $service = 'github';
                    } elseif (strpos($match, 'wp-social-link-twitter') !== false || strpos($url, 'twitter.com') !== false || strpos($url, 'x.com') !== false) {
                        $service = 'twitter';
                    } elseif (strpos($match, 'wp-social-link-linkedin') !== false || strpos($url, 'linkedin.com') !== false) {
                        $service = 'linkedin';
                    } elseif (strpos($match, 'wp-social-link-instagram') !== false || strpos($url, 'instagram.com') !== false) {
                        $service = 'instagram';
                    } elseif (strpos($match, 'wp-social-link-amazon') !== false || strpos($url, 'amazon.com') !== false) {
                        $service = 'amazon';
                    }

                    $socialLinks[] = [
                        'url'     => $url,
                        'service' => $service,
                        'label'   => $label
                    ];
                }
            }
        }

        // Render social links using image icons or better text fallbacks
        if (!empty($socialLinks)) {
            foreach ($socialLinks as $link) {
                $iconHtml = $this->getSocialIconHtml($link['service'], $link['label']);

                $socialHtml .= '<a href="' . htmlspecialchars($link['url']) . '" style="display: inline-block; margin: 0 5px; text-decoration: none;">';
                $socialHtml .= $iconHtml;
                $socialHtml .= '</a>';
            }
        }

        $socialHtml .= '</div>';

        return $this->wrapInTable($socialHtml);
    }

    /**
     * Get social media icon HTML (with better styling)
     */
    private function getSocialIconHtml($service, $label = '')
    {
        // Get background color for each service
        $colors = [
            'facebook'  => '#1877f2',
            'twitter'   => '#1da1f2',
            'linkedin'  => '#0077b5',
            'instagram' => '#e4405f',
            'github'    => '#181717',
            'wordpress' => '#21759b',
            'amazon'    => '#ff9900',
            'link'      => '#0073aa'
        ];

        $bgColor = $colors[$service] ?? '#0073aa';
        $alt = $label ?: ucfirst($service);

        // Use service-specific icon rendering
        $iconContent = $this->getSocialIconSVG($service);

        return '<span style="display: inline-block; width: 44px; height: 44px; background-color: ' . $bgColor . '; border-radius: 50%; text-align: center; padding: 10px; box-sizing: border-box;" title="' . htmlspecialchars($alt) . '">' . $iconContent . '</span>';
    }

    /**
     * Get social media icon SVG or text representation
     */
    private function getSocialIconSVG($service)
    {
        // Simple SVG icons as inline data
        $icons = [
            'facebook'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
            'twitter'   => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
            'linkedin'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
            'instagram' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
            'github'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>',
            'wordpress' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M21.469 6.825c.84 1.537 1.318 3.3 1.318 5.175 0 3.979-2.156 7.456-5.363 9.325l3.295-9.527c.615-1.54.82-2.771.82-3.864 0-.405-.026-.78-.07-1.11zm-7.981.105c.647-.03 1.232-.105 1.232-.105.582-.075.514-.93-.067-.899 0 0-1.755.135-2.88.135-1.064 0-2.85-.15-2.85-.15-.585-.03-.661.855-.075.885 0 0 .54.061 1.125.09l1.68 4.605-2.37 7.08L5.354 6.9c.649-.03 1.234-.1 1.234-.1.585-.075.516-.93-.065-.896 0 0-1.746.138-2.874.138-.2 0-.438-.008-.69-.015C4.911 3.15 8.235 1.215 12 1.215c2.809 0 5.365 1.072 7.286 2.833-.046-.003-.091-.009-.141-.009-1.06 0-1.812.923-1.812 1.914 0 .89.513 1.643 1.06 2.531.411.72.89 1.643.89 2.977 0 .915-.354 1.994-.821 3.479l-1.075 3.585-3.9-11.61.001.014zM12 22.784c-1.059 0-2.081-.153-3.048-.437l3.237-9.406 3.315 9.087c.024.053.05.101.078.149-1.12.393-2.325.607-3.582.607zM1.211 12c0-1.564.336-3.05.935-4.39L7.29 21.709C3.694 19.96 1.212 16.271 1.212 12zm10.785-10.784C6.596 1.215 1.214 6.597 1.214 12s5.382 10.785 10.784 10.785S22.784 17.403 22.784 12 17.402 1.215 11.996 1.215z"/></svg>',
            'amazon'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M14.465 11.813c-1.75-1.297-4.056-2.017-6.121-2.017-2.766 0-5.264 1.032-7.153 2.742-.207.183-.371.322-.559.322-.188 0-.316-.128-.316-.316 0-.184.18-.404.484-.672C2.821 9.84 5.831 8.621 9.071 8.621c2.766 0 5.262 1.031 7.151 2.742.211.183.375.322.563.322.184 0 .312-.128.312-.316 0-.184-.176-.404-.484-.672zm3.438 1.227c-.133.027-.27.04-.402.04-.297 0-.559-.09-.805-.274-1.367-.992-3.164-1.547-4.93-1.547-2.465 0-4.742 1.051-6.562 2.871-.188.183-.316.304-.508.304-.188 0-.316-.12-.316-.308 0-.184.176-.402.488-.672 1.98-1.96 4.621-3.039 7.465-3.039 2.027 0 3.926.582 5.504 1.684.238.164.375.402.375.672 0 .238-.188.5-.309.524v-.003zm4.016-3.863c-.863 0-1.637.504-1.875 1.297-.231.77.13 1.617.895 1.895.691.25 1.457-.145 1.703-.836.227-.652-.012-1.391-.637-1.746-.18-.094-.387-.137-.586-.16-.164-.012-.329-.012-.5.039.004.004.004-.012.004-.016v-.012c.238-.348.54-.602.984-.707.43-.097.851-.016 1.262.16.199.082.379.211.578.32-.012.012-.027.027-.043.043-.125.133-.266.273-.398.402-.145.148-.273.297-.437.399-.035.023-.074.046-.113.05-.039.008-.102-.008-.125-.035-.098-.098-.207-.195-.309-.293-.543-.52-1.254-.742-2.055-.742-.012-.012-.012 0-.012.012zM24 17.887c-2.059 1.52-4.121 3.031-6.18 4.551-.078.059-.164.113-.258.16-.586.305-1.199.031-1.199-.559v-8.664c0-.129.047-.266.129-.383.078-.102.172-.195.277-.258 2.078-1.523 4.156-3.043 6.234-4.566.074-.054.156-.109.242-.152.375-.195.797-.094 1.016.258.094.145.117.297.117.457v8.765c0 .129-.039.262-.129.39h-.004z"/></svg>',
            'link'      => '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>'
        ];

        return $icons[$service] ?? $icons['link'];
    }

    /**
     * Parse CSS variable format and extract the slug
     * Handles formats like:
     * - var:preset|color|accent-4
     * - var(--wp--preset--color--accent-4)
     * - accent-4 (plain slug)
     */
    private function parseColorSlug($value)
    {
        if (empty($value)) {
            return '';
        }

        // If it's already a hex color, return as is
        if (preg_match('/^#[a-f0-9]{3,8}$/i', $value)) {
            return $value;
        }

        // If it's an rgb/rgba color, return as is
        if (preg_match('/^rgba?\(/i', $value)) {
            return $value;
        }

        // Handle var:preset|color|slug-name format
        if (strpos($value, 'var:preset|color|') === 0) {
            return substr($value, strlen('var:preset|color|'));
        }

        // Handle var(--wp--preset--color--slug-name) format
        if (preg_match('/^var\(--wp--preset--color--([^)]+)\)$/', $value, $matches)) {
            return $matches[1];
        }

        // Return as is (plain slug or other format)
        return $value;
    }

    /**
     *  Get color from WordPress color slug
     * */
    public function getColorFromSlug($slug)
    {
        // Parse the slug to handle CSS variable formats
        $parsedSlug = $this->parseColorSlug($slug);

        // If it's already a valid color value (hex, rgb), return it
        if (preg_match('/^#[a-f0-9]{3,8}$/i', $parsedSlug) || preg_match('/^rgba?\(/i', $parsedSlug)) {
            return $parsedSlug;
        }

        // First, try to get from FluentCart Helper (which reads theme.json and editor-color-palette)
        static $colorMap = null;

        if ($colorMap === null) {
            $colorMap = [];

            $themeColors = $this->getThemeColorPalette();
            if (!empty($themeColors)) {
                foreach ($themeColors as $colorData) {
                    if (isset($colorData['slug']) && isset($colorData['color'])) {
                        $colorMap[$colorData['slug']] = $colorData['color'];
                    }
                }
            }

            // Also get theme preferences
            $themePref = $this->getThemePrefScheme();
            if (!empty($themePref['colors'])) {
                foreach ($themePref['colors'] as $colorData) {
                    if (isset($colorData['slug']) && isset($colorData['color'])) {
                        $colorMap[$colorData['slug']] = $colorData['color'];
                    }
                }
            }
        }

        // Check our color map first
        if (isset($colorMap[$parsedSlug])) {
            return $colorMap[$parsedSlug];
        }

        // Try to get theme color from WordPress theme.json or global settings
        if (function_exists('wp_get_global_settings')) {
            $settings = wp_get_global_settings();
            if (!empty($settings['color']['palette']['theme'])) {
                foreach ($settings['color']['palette']['theme'] as $color) {
                    if (isset($color['slug']) && $color['slug'] === $parsedSlug && !empty($color['color'])) {
                        return $color['color'];
                    }
                }
            }
        }

        // Try WP_Theme_JSON for block themes
        if (class_exists('WP_Theme_JSON_Resolver')) {
            $theme_json = \WP_Theme_JSON_Resolver::get_merged_data();
            if ($theme_json) {
                $settings = $theme_json->get_settings();
                if (!empty($settings['color']['palette'])) {
                    foreach ($settings['color']['palette'] as $palette) {
                        if (isset($palette['slug']) && $palette['slug'] === $parsedSlug && !empty($palette['color'])) {
                            return $palette['color'];
                        }
                    }
                }
            }
        }

        // Fallback: Common WordPress and popular theme colors
        $colors = [
            // Theme palette colors (adjust these based on your active theme)
            // Twenty Twenty-Three defaults
            'theme-palette-color-1' => '#000000', // Base/Black
            'theme-palette-color-2' => '#6f42c1', // Purple
            'theme-palette-color-3' => '#007cba', // Blue
            'theme-palette-color-4' => '#16a085', // Teal
            'theme-palette-color-5' => '#e74c3c', // Red
            'theme-palette-color-6' => '#f39c12', // Orange
            'theme-palette-color-7' => '#ffffff', // White
            'theme-palette-color-8' => '#f5f5f5', // Light Gray
            'theme-palette-color-9' => '#cccccc', // Gray

            // Standard WordPress colors
            'black'                 => '#000000',
            'white'                 => '#ffffff',
            'primary'               => '#0073aa',
            'secondary'             => '#23282d',
            'tertiary'              => '#F0F0F1',

            // Common named colors
            'red'                   => '#e74c3c',
            'blue'                  => '#3498db',
            'green'                 => '#2ecc71',
            'yellow'                => '#f1c40f',
            'orange'                => '#e67e22',
            'purple'                => '#9b59b6',
            'cyan'                  => '#1abc9c',
            'vivid-red'             => '#cf2e2e',
            'vivid-orange'          => '#ff6900',
            'vivid-cyan-blue'       => '#0693e3',
            'vivid-green-cyan'      => '#00d084',
            'vivid-purple'          => '#9b51e0',
            'luminous-vivid-amber'  => '#fcb900',
            'luminous-vivid-orange' => '#ff6900',
            'light-green-cyan'      => '#7bdcb5',
            'pale-pink'             => '#f78da7',
            'pale-cyan-blue'        => '#8ed1fc',
        ];

        return $colors[$parsedSlug] ?? '#0073aa';
    }

    public function getThemeColorPalette()
    {
        $color_palette = current((array)get_theme_support('editor-color-palette'));
        $theme_json_path = get_theme_file_path('theme.json');

        if (file_exists($theme_json_path)) {
            $theme_json = json_decode(file_get_contents($theme_json_path), true);

            if (isset($theme_json['settings']['color']['palette'])) {
                $color_palette = $theme_json['settings']['color']['palette'];
            }
        }
        if (!$color_palette) {
            $color_palette = [];
        }

        return (array)$color_palette;
    }

    public function getThemePrefScheme()
    {
        static $pref;
        if (!$pref) {

            $color_palette = BlockEditorHelper::getDefaultPreset('color');

            $font_sizes = BlockEditorHelper::getDefaultPreset('font-size');

            /**
             * Filter the theme preferences for FluentCart.
             *
             * This filter allows modification of the theme preferences, including colors and font sizes.
             *
             * @param array {
             *     The theme preferences.
             *
             * @type array $colors The color palette.
             * @type array $font_sizes The font sizes.
             * }
             * @since 2.6.51
             *
             */
            $pref = apply_filters('fluent_cart/theme_pref', [
                'colors'     => (array)$color_palette,
                'font_sizes' => (array)$font_sizes
            ]);
        }

        return $pref;

    }

    public function getFontSizeFromSlug($slug) {
        $font_sizes = [
            'small'   => '14px',
            'medium'  => '18px',
            'large'   => '24px',
            'larger'  => '32px',
            'xxlarge' => '42px',
        ];

        // Get font sizes from theme defined
        $theme_font_sizes = $this->getThemeFontSizes();
        if (!empty($theme_font_sizes)) {
            foreach ($theme_font_sizes as $size) {
                if (isset($size['slug']) && $size['slug'] === $slug && !empty($size['size'])) {
                    return $size['size'];
                }
            }
        }

        return $font_sizes[$slug] ?? '16px';
    }


    public static function getThemeFontSizes()
    {
        $font_sizes = current((array)get_theme_support('editor-font-sizes'));
        $theme_json_path = get_theme_file_path('theme.json');

        if (file_exists($theme_json_path)) {
            $theme_json = json_decode(file_get_contents($theme_json_path), true);

            if (isset($theme_json['settings']['typography']['fontSizes'])) {
                $font_sizes = $theme_json['settings']['typography']['fontSizes'];
            }
        }

        return $font_sizes;
    }

    /**
     * Debug helper: Log all theme colors (for development only)
     * Uncomment the call in getColorFromSlug() to use
     */
    private function debugThemeColors()
    {
        static $logged = false;
        if ($logged) return;

        // Check wp_get_global_settings
        if (function_exists('wp_get_global_settings')) {
            $settings = wp_get_global_settings();
        }

        // Check WP_Theme_JSON_Resolver
        if (class_exists('WP_Theme_JSON_Resolver')) {
            $theme_json = \WP_Theme_JSON_Resolver::get_merged_data();
            if ($theme_json) {
                $settings = $theme_json->get_settings();
            }
        }

        $logged = true;
    }

    /**
     * Wrap content in email-safe table structure
     */
    private function wrapInTable($content)
    {
        if (empty(trim($content))) {
            return '';
        }

        return "<table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\">
    <tr>
        <td style=\"padding: 0;\">
            {$content}
        </td>
    </tr>
</table>";
    }

    /**
     * Generate complete email HTML with wrapper
     */
    public function generateEmailHtml($content, $title = '')
    {
        $parsedContent = $this->parse($content);

        return "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">
    <title>{$title}</title>
    <style type=\"text/css\">
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: #333333;
            background-color: #f4f4f4;
        }
        table {
            border-collapse: collapse;
        }
        img {
            border: 0;
            outline: none;
            text-decoration: none;
            -ms-interpolation-mode: bicubic;
        }
        a {
            color: #0073aa;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                width: 100% !important;
            }
        }
    </style>
</head>
<body style=\"margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f4;\">
    <table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\">
        <tr>
            <td align=\"center\" style=\"padding: 20px 0;\">
                <table role=\"presentation\" class=\"email-container\" width=\"600\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" style=\"max-width: 600px; background-color: #ffffff;\">
                    <tr>
                        <td style=\"padding: 40px 30px;\">
                            {$parsedContent}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
    }


    public function getCommonStyles()
    {

        $color_palette = [
            [
                "name"  => __("Black", "fluent-cart"),
                "slug"  => "black",
                "color" => "#000000"
            ],
            [
                "name"  => __("Cyan bluish gray", "fluent-cart"),
                "slug"  => "cyan-bluish-gray",
                "color" => "#abb8c3"
            ],
            [
                "name"  => __("White", "fluent-cart"),
                "slug"  => "white",
                "color" => "#ffffff"
            ],
            [
                "name"  => __("Pale pink", "fluent-cart"),
                "slug"  => "pale-pink",
                "color" => "#f78da7"
            ],
            [
                "name"  => __("Luminous vivid orange", "fluent-cart"),
                "slug"  => "luminous-vivid-orange",
                "color" => "#ff6900"
            ],
            [
                "name"  => __("Luminous vivid amber", "fluent-cart"),
                "slug"  => "luminous-vivid-amber",
                "color" => "#fcb900"
            ],
            [
                "name"  => __("Light green cyan", "fluent-cart"),
                "slug"  => "light-green-cyan",
                "color" => "#7bdcb5"
            ],
            [
                "name"  => __("Vivid green cyan", "fluent-cart"),
                "slug"  => "vivid-green-cyan",
                "color" => "#00d084"
            ],
            [
                "name"  => __("Pale cyan blue", "fluent-cart"),
                "slug"  => "pale-cyan-blue",
                "color" => "#8ed1fc"
            ],
            [
                "name"  => __("Vivid cyan blue", "fluent-cart"),
                "slug"  => "vivid-cyan-blue",
                "color" => "#0693e3"
            ],
            [
                "name"  => __("Vivid purple", "fluent-cart"),
                "slug"  => "vivid-purple",
                "color" => "#9b51e0"
            ]
        ];

        ob_start();
        ?>
        <style>
            .has-fluent-x-small-font-size {
                font-size: 14px;
            }

            .has-fluent-normal-font-size {
                font-size: 16px;
            }

            .has-fluent-medium-font-size {
                font-size: 18px;
            }

            .has-fluent-large-font-size {
                font-size: 20px;
            }

            .has-fluent-x-large-font-size {
                font-size: 26px;
            }

            .has-border-color {
                border-style: solid;
            }

            .has-text-align-center {
                text-align: center;
            }

            .has-text-align-right {
                text-align: right;
            }

            .fluent-paragraph {
                line-height: 1.4;
            }

            .wp-block-button__link {
                display: inline-block;
                padding: 12px 24px;
                text-decoration: none;
                font-weight: bold;
                border-radius: 9999px;
                text-align: center;
                color: #ffffff;
                background-color: #0073aa;
            }

            <?php foreach ($color_palette as $item): ?>
            .has-<?php echo esc_attr($item['slug']); ?>-color {
                color: <?php echo esc_html($item['color']); ?>;
            }

            .has-<?php echo esc_attr($item['slug']); ?>-background-color {
                background-color: <?php echo esc_html($item['color']); ?>;
            }

            .has-<?php echo esc_attr($item['slug']); ?>-border-color {
                border-color: <?php echo esc_html($item['color']); ?>;
            }

            <?php endforeach; ?>
        </style>
        <?php

        return ob_get_clean();
    }

    public function replaceCssVars($content = '')
    {
        $replaces = [
            'var(--wp--preset--spacing--fluent-20)' => '20px',
            'var(--wp--preset--spacing--fluent-30)' => '30px',
            'var(--wp--preset--spacing--fluent-40)' => '40px',
            'var(--wp--preset--spacing--fluent-50)' => '50px',
            'var(--wp--preset--spacing--fluent-60)' => '60px',
            'var(--wp--preset--spacing--fluent-70)' => '70px',
            'var(--wp--preset--spacing--fluent-80)' => '80px'
        ];

        return str_replace(array_keys($replaces), array_values($replaces), $content);
    }

    /**
     * Resolve a spacing value that might be a CSS variable reference
     *
     * @param string $value The spacing value
     * @return string Resolved pixel value
     */
    public function resolveSpacingValue($value)
    {
        if (strpos($value, 'var:preset|spacing|') === 0) {
            $slug = substr($value, strlen('var:preset|spacing|'));
            return $this->getSpacingFromSlug($slug);
        }

        if (preg_match('/^var\(--wp--preset--spacing--(.+)\)$/', $value, $m)) {
            return $this->getSpacingFromSlug($m[1]);
        }

        return $value;
    }

    private function getSpacingFromSlug($slug)
    {
        if (function_exists('wp_get_global_settings')) {
            $settings = wp_get_global_settings();
            $presets = $settings['spacing']['spacingSizes']['default'] ?? ($settings['spacing']['spacingSizes'] ?? []);
            foreach ($presets as $preset) {
                if (isset($preset['slug']) && $preset['slug'] === $slug && !empty($preset['size'])) {
                    return $preset['size'];
                }
            }
        }

        $defaults = [
            '20' => '20px', '30' => '30px', '40' => '40px',
            '50' => '50px', '60' => '60px', '70' => '70px', '80' => '80px',
            'fluent-20' => '20px', 'fluent-30' => '30px', 'fluent-40' => '40px',
            'fluent-50' => '50px', 'fluent-60' => '60px', 'fluent-70' => '70px', 'fluent-80' => '80px',
        ];

        return isset($defaults[$slug]) ? $defaults[$slug] : '20px';
    }

    /**
     * Get shadow value from slug or preset
     */
    private function getShadowFromSlug($slug)
    {
        // Parse preset format: var:preset|shadow|slug-name
        if (strpos($slug, 'var:preset|shadow|') === 0) {
            $slug = substr($slug, strlen('var:preset|shadow|'));
        }

        // If it's already a valid box-shadow value, return it
        if (preg_match('/^\d+px\s+\d+px/', $slug)) {
            return $slug;
        }

        // Try to get from WordPress theme settings
        if (function_exists('wp_get_global_settings')) {
            $settings = wp_get_global_settings();
            if (!empty($settings['shadow']['presets'])) {
                foreach ($settings['shadow']['presets'] as $shadow) {
                    if (isset($shadow['slug']) && $shadow['slug'] === $slug && !empty($shadow['shadow'])) {
                        return $shadow['shadow'];
                    }
                }
            }
        }

        // Try WP_Theme_JSON for block themes
        if (class_exists('WP_Theme_JSON_Resolver')) {
            $theme_json = \WP_Theme_JSON_Resolver::get_merged_data();
            if ($theme_json) {
                $settings = $theme_json->get_settings();
                if (!empty($settings['shadow']['presets'])) {
                    foreach ($settings['shadow']['presets'] as $shadow) {
                        if (isset($shadow['slug']) && $shadow['slug'] === $slug && !empty($shadow['shadow'])) {
                            return $shadow['shadow'];
                        }
                    }
                }
            }
        }

        // Fallback shadow presets (common WordPress shadows)
        $shadows = [
                'natural'   => '6px 6px 9px rgba(0, 0, 0, 0.2)',
                'deep'      => '12px 12px 50px rgba(0, 0, 0, 0.4)',
                'sharp'     => '6px 6px 0px rgba(0, 0, 0, 0.2)',
                'outlined'  => '6px 6px 0px -3px rgba(255, 255, 255, 1), 6px 6px rgba(0, 0, 0, 1)',
                'crisp'     => '6px 6px 0px rgba(0, 0, 0, 1)',
        ];

        return $shadows[$slug] ?? 'none';
    }


}

// Usage Example:
/*
$parser = new GutenbergEmailParser();

// Option 1: Parse blocks only (returns HTML fragment)
$post_content = get_post_field('post_content', $post_id);
$emailHtml = $parser->parse($post_content);

// Option 2: Generate complete email HTML with wrapper
$completeEmail = $parser->generateEmailHtml($post_content, 'Email Title');

// Send email
wp_mail($to, $subject, $completeEmail, ['Content-Type: text/html; charset=UTF-8']);
*/
