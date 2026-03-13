<?php

namespace FluentCart\App\Services\Email\Blocks;

use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;

/**
 * License Details Loop Block Renderer for Email
 *
 * Iterates over order licenses, renders InnerBlocks per license,
 * and replaces {{license.*}} shortcodes with actual values.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class LicenseDetailsLoopBlock extends OrderItemsLoopBlock
{
    public function render(): string
    {
        if (!$this->evaluateBlockCondition()) {
            return '';
        }

        if (empty($this->innerBlocks)) {
            return '{{order.license_details}}';
        }

        $order = isset($this->parserData['order']) ? $this->parserData['order'] : null;

        if (!$order) {
            return '';
        }

        $licenses = $order->getLicenses();

        if (!$licenses || $licenses->isEmpty()) {
            return '';
        }

        $licenseCount = $licenses->count();
        $result = '';

        // Separator settings
        $showSeparator = Arr::get($this->attrs, 'showSeparator', true);
        $separatorColor = Arr::get($this->attrs, 'separatorColor', '#e5e7eb');

        // Separate header/footer blocks (render once) from body blocks (loop per item)
        $headerBlocks = [];
        $footerBlocks = [];
        $loopBlocks = [];
        foreach ($this->innerBlocks as $block) {
            $name = isset($block['blockName']) ? $block['blockName'] : '';
            if ($name === 'fluent-cart/email-row-header') {
                $headerBlocks[] = $block;
            } elseif ($name === 'fluent-cart/email-row-footer') {
                $footerBlocks[] = $block;
            } else {
                $loopBlocks[] = $block;
            }
        }

        if (!empty($headerBlocks)) {
            $result .= $this->renderBlocksViaParser($headerBlocks);
        }

        foreach ($licenses as $index => $license) {
            $license->sl = $index + 1;
            $this->parserData['current_license'] = $license;

            $licenseHtml = $this->renderBlocksViaParser($loopBlocks);
            $licenseHtml = ShortcodeTemplateBuilder::make($licenseHtml, array_merge($this->parserData, ['license' => $license]));

            $result .= $licenseHtml;

            if ($showSeparator && $index < $licenseCount - 1) {
                $result .= '<hr style="border: none; border-top: 1px solid ' . esc_attr($separatorColor) . '; margin: 0;">';
            }
        }

        unset($this->parserData['current_license']);

        // Render footer blocks once (after all items)
        if (!empty($footerBlocks)) {
            $result .= $this->renderBlocksViaParser($footerBlocks);
        }

        return $this->wrapWithStyles($result);
    }

}
