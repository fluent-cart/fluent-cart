<?php

namespace FluentCart\App\Services\Email\Blocks;

use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;

/**
 * Order Items Loop Block Renderer for Email
 *
 * Iterates over order items, renders InnerBlocks per item,
 * and replaces {{item.*}} shortcodes with actual values.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class OrderItemsLoopBlock extends BaseBlock
{
    /**
     * @var array Parser data containing order, etc.
     */
    protected $parserData = [];

    /**
     * @param array $data Reference to parser data array
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

        if (empty($this->innerBlocks)) {
            return '{{order.items_table}}';
        }

        $order = isset($this->parserData['order']) ? $this->parserData['order'] : null;

        if (!$order || !$order->order_items || $order->order_items->isEmpty()) {
            return '';
        }

        $items = $order->order_items->toArray();
        $itemCount = count($items);
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

        // Render header blocks once (not per-item)
        if (!empty($headerBlocks)) {
            $result .= $this->renderBlocksViaParser($headerBlocks);
        }

        foreach ($items as $index => $item) {
            $this->parserData['current_item'] = $item;

            $item['sl'] = $index + 1;
            $itemHtml = $this->renderBlocksViaParser($loopBlocks);
            $itemHtml = ShortcodeTemplateBuilder::make($itemHtml, array_merge($this->parserData, ['item' => $item]));

            $result .= $itemHtml;

            if ($showSeparator && $index < $itemCount - 1) {
                $result .= '<hr style="border: none; border-top: 1px solid ' . esc_attr($separatorColor) . '; margin: 0;">';
            }
        }

        unset($this->parserData['current_item']);

        // Render footer blocks once (after all items)
        if (!empty($footerBlocks)) {
            $result .= $this->renderBlocksViaParser($footerBlocks);
        }

        return $this->wrapWithStyles($result);
    }

    /**
     * Call renderBlocks on the parser via reflection.
     */
    protected function renderBlocksViaParser(array $blocks): string
    {
        if (empty($blocks) || !$this->parser) {
            return '';
        }

        return $this->parser->renderNestedBlocks($blocks);
    }

    /**
     * Build inline styles from block attributes and wrap content.
     */
    protected function wrapWithStyles(string $content): string
    {
        $wrapperStyles = '';

        // Background color
        if (!empty($this->attrs['backgroundColor'])) {
            $wrapperStyles .= 'background-color: ' . $this->getColorFromSlug($this->attrs['backgroundColor']) . ';';
        } elseif (!empty($this->style['color']['background'])) {
            $wrapperStyles .= 'background-color: ' . $this->getColorFromSlug($this->style['color']['background']) . ';';
        }

        // Text color
        if (!empty($this->attrs['textColor'])) {
            $wrapperStyles .= ' color: ' . $this->getColorFromSlug($this->attrs['textColor']) . ';';
        } elseif (!empty($this->style['color']['text'])) {
            $wrapperStyles .= ' color: ' . $this->getColorFromSlug($this->style['color']['text']) . ';';
        }

        $wrapperStyles .= $this->getSpacingStyles('padding');
        $wrapperStyles .= $this->getSpacingStyles('margin');
        $wrapperStyles .= $this->getBorderStyles();

        if (!empty(trim($wrapperStyles))) {
            return '<div style="' . esc_attr(trim($wrapperStyles)) . '">' . $content . '</div>';
        }

        return $content;
    }
}
