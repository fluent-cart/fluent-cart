<?php

namespace FluentCart\App\Services\Email\Blocks;

use FluentCart\Framework\Support\Arr;

/**
 * Email Order Addresses Block Renderer
 *
 * Wrapper that checks if address data exists before rendering.
 * Contains separators and an address-container child that handles layout.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class OrderAddressesBlock extends BaseBlock
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
        if (!$this->evaluateBlockCondition()) {
            return '';
        }

        // Don't render the block at all if order has no address data
        if (!$this->hasAddressData()) {
            return '';
        }

        // Fallback to shortcode when no inner blocks are configured
        if (empty($this->innerBlocks)) {
            return '{{order.address_details}}';
        }

        // Pass hideShippingOnDigital flag to children via parser data
        $hideShippingOnDigital = Arr::get($this->attrs, 'hideShippingOnDigital', false);
        if ($hideShippingOnDigital) {
            $this->parserData['_hideShippingOnDigital'] = true;
        }

        // Render all inner blocks (separators + address container)
        $content = $this->parser->renderNestedBlocks($this->innerBlocks);

        // Clean up temporary flag
        unset($this->parserData['_hideShippingOnDigital']);

        if (empty(trim($content))) {
            return '';
        }

        // Apply wrapper styles if any
        $wrapperStyles = $this->buildWrapperStyles();

        if (empty($wrapperStyles)) {
            return $content;
        }

        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
            . '<tr><td style="' . esc_attr($wrapperStyles) . '">'
            . $content
            . '</td></tr></table>';
    }

    /**
     * Build inline styles for the outer wrapper from block attributes.
     *
     * @return string
     */
    private function buildWrapperStyles(): string
    {
        $result = $this->buildStyles(
            '',
            true,  // typography
            true,  // colors
            true,  // border
            true,  // padding
            true   // margin
        );

        return trim($result['styles']);
    }

    /**
     * Check if the order has any address data (billing or shipping).
     *
     * @return bool
     */
    private function hasAddressData(): bool
    {
        $order = isset($this->parserData['order']) ? $this->parserData['order'] : null;

        if (!$order) {
            return false;
        }

        if (is_object($order)) {
            $billing = isset($order->billing_address) ? $order->billing_address : null;
            $shipping = isset($order->shipping_address) ? $order->shipping_address : null;
            return !empty($billing) || !empty($shipping);
        }

        if (is_array($order)) {
            return !empty($order['billing_address']) || !empty($order['shipping_address']);
        }

        return false;
    }
}
