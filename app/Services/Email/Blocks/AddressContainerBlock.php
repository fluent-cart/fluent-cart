<?php

namespace FluentCart\App\Services\Email\Blocks;

use FluentCart\Framework\Support\Arr;

/**
 * Email Address Container Block Renderer
 *
 * Handles layout logic (split/full/auto) for billing and shipping
 * address children in email-safe table markup.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class AddressContainerBlock extends BaseBlock
{
    /**
     * @var array Parser data containing order info
     */
    protected $parserData = [];

    /**
     * @param array $data
     * @return self
     */
    public function setParserData(array &$data): self
    {
        $this->parserData = &$data;
        return $this;
    }

    public function render(): string
    {
        $layout = Arr::get($this->attrs, 'layout', 'auto');

        $billingHtml = '';
        $shippingHtml = '';

        foreach ($this->innerBlocks as $child) {
            $childName = Arr::get($child, 'blockName', '');
            $childInnerBlocks = Arr::get($child, 'innerBlocks', []);

            if ($childName === 'fluent-cart/email-billing-address') {
                $billingHtml = $this->renderChildBlocks($childInnerBlocks);
            } elseif ($childName === 'fluent-cart/email-shipping-address') {
                $shippingHtml = $this->renderChildBlocks($childInnerBlocks);
            }
        }

        if (empty(trim($billingHtml)) && empty(trim($shippingHtml))) {
            return '';
        }

        // Check if parent has hideShippingOnDigital and order is digital-only
        $hideShipping = $this->shouldHideShipping();
        $hasShipping = !$hideShipping && !empty(trim($shippingHtml));

        // Determine effective layout
        if ($layout === 'auto') {
            $effectiveLayout = $hasShipping ? 'split' : 'full';
        } elseif ($layout === 'split') {
            $effectiveLayout = $hasShipping ? 'split' : 'full';
        } else {
            $effectiveLayout = 'full';
        }

        if ($effectiveLayout === 'split') {
            return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
                . '<tr>'
                . '<td width="50%" valign="top" style="padding-right: 8px;">' . $billingHtml . '</td>'
                . '<td width="50%" valign="top" style="padding-left: 8px;">' . $shippingHtml . '</td>'
                . '</tr>'
                . '</table>';
        }

        // Full/stacked layout
        $content = $billingHtml;
        if ($hasShipping) {
            $content .= $shippingHtml;
        }

        return $content;
    }

    /**
     * Render inner blocks of a child address block through the parser.
     *
     * @param array $innerBlocks
     * @return string
     */
    private function renderChildBlocks(array $innerBlocks): string
    {
        if (empty($innerBlocks) || !$this->parser) {
            return '';
        }

        return $this->parser->renderNestedBlocks($innerBlocks);
    }

    /**
     * Check if shipping should be hidden (digital-only order with flag set).
     *
     * The hideShippingOnDigital flag lives on the parent email-order-addresses
     * block, but the parser data carries the order so we can check here.
     *
     * @return bool
     */
    private function shouldHideShipping(): bool
    {
        // This flag is passed via parser data from the parent block
        $hideOnDigital = !empty($this->parserData['_hideShippingOnDigital']);

        if (!$hideOnDigital) {
            return false;
        }

        $order = isset($this->parserData['order']) ? $this->parserData['order'] : null;

        if (!$order) {
            return false;
        }

        if (is_object($order) && isset($order->shipping_status)) {
            return $order->shipping_status === 'unshippable';
        }

        if (is_array($order)) {
            return Arr::get($order, 'shipping_status') === 'unshippable';
        }

        return false;
    }
}
