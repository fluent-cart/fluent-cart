<?php

namespace FluentCart\App\Services\Email\Blocks;

use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;

/**
 * Download Details Loop Block Renderer for Email
 *
 * Iterates over order downloads (flattened), renders InnerBlocks per file,
 * and replaces {{download.*}} shortcodes with actual values.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class DownloadDetailsLoopBlock extends OrderItemsLoopBlock
{
    public function render(): string
    {
        if (!$this->evaluateBlockCondition()) {
            return '';
        }

        if (empty($this->innerBlocks)) {
            return '{{order.download_details}}';
        }

        $order = isset($this->parserData['order']) ? $this->parserData['order'] : null;

        if (!$order) {
            return '';
        }

        $downloadGroups = $order->getDownloads();

        if (empty($downloadGroups)) {
            return '';
        }

        // Flatten nested download groups into a flat list of files
        $files = [];
        foreach ($downloadGroups as $group) {
            $productName = isset($group['title']) ? $group['title'] : '';
            if (!empty($group['downloads'])) {
                foreach ($group['downloads'] as $file) {
                    $file['product_name'] = $productName;
                    $files[] = $file;
                }
            }
        }

        if (empty($files)) {
            return '';
        }

        $fileCount = count($files);
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

        foreach ($files as $index => $file) {
            $file['sl'] = $index + 1;
            $this->parserData['current_download'] = $file;

            $fileHtml = $this->renderBlocksViaParser($loopBlocks);
            $fileHtml = ShortcodeTemplateBuilder::make($fileHtml, array_merge($this->parserData, ['download' => $file]));

            $result .= $fileHtml;

            if ($showSeparator && $index < $fileCount - 1) {
                $result .= '<hr style="border: none; border-top: 1px solid ' . esc_attr($separatorColor) . '; margin: 0;">';
            }
        }

        unset($this->parserData['current_download']);

        // Render footer blocks once (after all items)
        if (!empty($footerBlocks)) {
            $result .= $this->renderBlocksViaParser($footerBlocks);
        }

        return $this->wrapWithStyles($result);
    }

}
