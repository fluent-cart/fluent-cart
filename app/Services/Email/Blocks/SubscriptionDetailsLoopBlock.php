<?php

namespace FluentCart\App\Services\Email\Blocks;

use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;

/**
 * Subscription Details Loop Block Renderer for Email
 *
 * Iterates over order subscriptions, renders InnerBlocks per subscription,
 * and replaces {{subscription.*}} shortcodes with actual values.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class SubscriptionDetailsLoopBlock extends OrderItemsLoopBlock
{
    public function render(): string
    {
        if (!$this->evaluateBlockCondition()) {
            return '';
        }

        if (empty($this->innerBlocks)) {
            return '{{order.subscription_details}}';
        }

        $order = isset($this->parserData['order']) ? $this->parserData['order'] : null;

        if (!$order) {
            return '';
        }

        $subscriptions = $order->subscriptions;

        if (!$subscriptions || $subscriptions->isEmpty()) {
            return '';
        }

        $subscriptionCount = $subscriptions->count();
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

        foreach ($subscriptions as $index => $subscription) {
            $subscription->sl = $index + 1;
            $this->parserData['current_subscription'] = $subscription;

            $subHtml = $this->renderBlocksViaParser($loopBlocks);
            $subHtml = ShortcodeTemplateBuilder::make($subHtml, array_merge($this->parserData, ['subscription' => $subscription]));

            $result .= $subHtml;

            if ($showSeparator && $index < $subscriptionCount - 1) {
                $result .= '<hr style="border: none; border-top: 1px solid ' . esc_attr($separatorColor) . '; margin: 0;">';
            }
        }

        unset($this->parserData['current_subscription']);

        // Render footer blocks once (after all items)
        if (!empty($footerBlocks)) {
            $result .= $this->renderBlocksViaParser($footerBlocks);
        }

        return $this->wrapWithStyles($result);
    }

}
