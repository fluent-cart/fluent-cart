<?php

namespace FluentCart\App\Services\Email\Blocks;

use FluentCart\Framework\Support\Arr;

/**
 * Buttons Container Block Renderer for Email
 *
 * Converts Gutenberg core/buttons blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class ButtonsBlock extends BaseBlock
{
    /**
     * The full block data (needed for innerContent)
     *
     * @var array
     */
    protected $block = [];

    /**
     * Constructor with block data
     *
     * @param array $attrs Block attributes
     * @param string $innerHTML Inner HTML content
     * @param array $innerBlocks Inner blocks
     * @param mixed $parser Parent parser
     * @param array $block Full block data
     */
    public function __construct(array $attrs = [], string $innerHTML = '', array $innerBlocks = [], $parser = null, array $block = [])
    {
        parent::__construct($attrs, $innerHTML, $innerBlocks, $parser);
        $this->block = $block;
    }

    /**
     * Render the buttons container block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $content = Arr::get($this->block, 'innerContent.0', '');

        // Extract style attribute from div
        $styleAttr = $this->extractStyleFromContent($content);

        // Extract class attribute from div
        $classAttr = $this->extractClassFromContent($content);

        // Handle justify content alignment
        $justifyContent = Arr::get($this->attrs, 'layout.justifyContent');
        if ($justifyContent === 'center') {
            $classAttr .= ' has-text-align-center';
        } elseif ($justifyContent === 'right') {
            $classAttr .= ' has-text-align-right';
        }

        // Render inner buttons
        $buttonsHtml = $this->renderButtons();

        return "<table role=\"presentation\" class=\"{$classAttr} fluent_buttons\" style=\"{$styleAttr}\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\">
    <tr>
        <td style=\"padding: 0;\">
            {$buttonsHtml}
        </td>
    </tr>
</table>";
    }

    /**
     * Extract style attribute from content
     *
     * @param string $content HTML content
     * @return string Style value
     */
    protected function extractStyleFromContent(string $content): string
    {
        if (preg_match('/<div[^>]*style=["\']([^"\']*)["\'][^>]*>/s', $content, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Extract class attribute from content
     *
     * @param string $content HTML content
     * @return string Class value
     */
    protected function extractClassFromContent(string $content): string
    {
        if (preg_match('/<div[^>]*class=["\']([^"\']*)["\'][^>]*>/s', $content, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Resolve the blockGap value for spacing between buttons
     *
     * @return string CSS gap value (e.g., '0.5em', '20px')
     */
    protected function resolveBlockGap(): string
    {
        $blockGapRaw = $this->style['spacing']['blockGap'] ?? null;

        if (empty($blockGapRaw)) {
            return '0.5em';
        }

        // Handle object format {left: "...", top: "..."}
        if (is_array($blockGapRaw)) {
            $blockGapRaw = $blockGapRaw['left'] ?? ($blockGapRaw['top'] ?? '0.5em');
        }

        return $this->resolveSpacingValue($blockGapRaw);
    }

    /**
     * Render inner button blocks
     *
     * @return string HTML for all buttons
     */
    protected function renderButtons(): string
    {
        $html = '';
        $gap = $this->resolveBlockGap();
        $buttonBlocks = [];

        foreach ($this->innerBlocks as $button) {
            if ($button['blockName'] === 'core/button') {
                $buttonBlocks[] = $button;
            }
        }

        $total = count($buttonBlocks);
        foreach ($buttonBlocks as $index => $button) {
            $buttonBlock = new ButtonBlock(
                $button['attrs'] ?? [],
                $button['innerHTML'] ?? '',
                $button['innerBlocks'] ?? [],
                $this->parser
            );
            $buttonHtml = $buttonBlock->render();

            // Add margin-right to all buttons except the last
            if ($index < $total - 1 && !empty($gap) && $gap !== '0') {
                // Inject margin-right into existing style attribute
                $buttonHtml = preg_replace(
                    '/style="/',
                    'style="margin-right: ' . $gap . '; ',
                    $buttonHtml,
                    1
                );
            }

            $html .= $buttonHtml;
        }

        return $html;
    }
}