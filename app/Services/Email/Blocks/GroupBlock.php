<?php

namespace FluentCart\App\Services\Email\Blocks;

/**
 * Group Block Renderer for Email
 *
 * Converts Gutenberg core/group blocks to email-compatible HTML.
 *
 * @package FluentCart\App\Services\Email\Blocks
 */
class GroupBlock extends BaseBlock
{
    /**
     * Whether this is a root-level group
     *
     * @var bool
     */
    protected $isRoot = false;

    /**
     * Set whether this is a root group
     *
     * @param bool $isRoot
     * @return self
     */
    public function setIsRoot(bool $isRoot): self
    {
        $this->isRoot = $isRoot;
        return $this;
    }

    /**
     * Render the group block
     *
     * @return string Email-compatible HTML
     */
    public function render(): string
    {
        $layout = $this->attrs['layout'] ?? [];
        $layoutType = $layout['type'] ?? 'default';
        $orientation = $layout['orientation'] ?? 'horizontal';

        // Detect flex-row layout
        if ($layoutType === 'flex' && $orientation !== 'vertical') {
            return $this->renderFlexRow($layout);
        }

        $content = $this->renderInnerContent();

        if (empty(trim($content))) {
            return '';
        }

        // Build comprehensive styles: colors, border, spacing, typography
        $styleResult = $this->buildStyles('', false, true, true, true, true);
        $divStyles = $styleResult['styles'];

        // If no explicit padding was set, use a sensible default for groups with background
        $hasBackground = isset($this->attrs['backgroundColor'])
            || !empty($this->style['color']['background'])
            || !empty($this->style['color']['gradient']);
        $hasExplicitPadding = !empty($this->style['spacing']['padding']);

        if (!$hasExplicitPadding) {
            $divStyles = ($hasBackground ? 'padding: 20px;' : '') . $divStyles;
        }

        // Support layout.contentSize for max-width
        if (!empty($layout['contentSize'])) {
            $divStyles .= " max-width: {$layout['contentSize']}; margin-left: auto; margin-right: auto;";
        }

        $divClass = $this->isRoot ? 'fct_root_group' : 'fct_inner_group';

        return "<div class=\"{$divClass}\" style=\"{$divStyles}\">{$content}</div>";
    }

    /**
     * Render flex-row layout as table cells (email-safe alternative to CSS flex)
     *
     * @param array $layout Layout attributes
     * @return string Email-compatible HTML
     */
    protected function renderFlexRow(array $layout): string
    {
        if (empty($this->innerBlocks) || !$this->parser) {
            return '';
        }

        // Build container styles: colors, border, spacing
        $styleResult = $this->buildStyles('', false, true, true, true, true);
        $tableStyles = $styleResult['styles'];

        $hasBackground = isset($this->attrs['backgroundColor'])
            || !empty($this->style['color']['background'])
            || !empty($this->style['color']['gradient']);
        $hasExplicitPadding = !empty($this->style['spacing']['padding']);

        if (!$hasExplicitPadding && $hasBackground) {
            $tableStyles = 'padding: 20px;' . $tableStyles;
        }

        if (!empty($layout['contentSize'])) {
            $tableStyles .= " max-width: {$layout['contentSize']}; margin-left: auto; margin-right: auto;";
        }

        // Resolve justifyContent → text-align for table alignment
        $justify = $layout['justifyContent'] ?? 'flex-start';
        $alignMap = [
            'left'          => 'left',
            'flex-start'    => 'left',
            'center'        => 'center',
            'right'         => 'right',
            'flex-end'      => 'right',
            'space-between' => 'left',
        ];
        $textAlign = $alignMap[$justify] ?? 'left';

        // Resolve blockGap for spacing between cells
        $blockGapRaw = $this->style['spacing']['blockGap'] ?? '10px';
        if (is_array($blockGapRaw)) {
            $blockGapRaw = $blockGapRaw['left'] ?? ($blockGapRaw['top'] ?? '10px');
        }
        $gap = $this->resolveSpacingValue($blockGapRaw);
        $gapValue = (float) $gap;
        $gapUnit = preg_replace('/[\d.]+/', '', $gap) ?: 'px';
        $halfGap = $gapValue / 2;

        $blockCount = count($this->innerBlocks);
        $divClass = $this->isRoot ? 'fct_root_group' : 'fct_inner_group';

        $html = '<table role="presentation" class="' . $divClass . '" width="100%" cellspacing="0" cellpadding="0" border="0" style="text-align: ' . $textAlign . ';' . $tableStyles . '"><tr>';

        foreach ($this->innerBlocks as $index => $block) {
            $cellContent = $this->parser->renderNestedBlock($block);

            $tdPadding = '';
            if ($halfGap > 0) {
                if ($index > 0) {
                    $tdPadding .= " padding-left: {$halfGap}{$gapUnit};";
                }
                if ($index < $blockCount - 1) {
                    $tdPadding .= " padding-right: {$halfGap}{$gapUnit};";
                }
            }

            $html .= '<td style="vertical-align: middle;' . $tdPadding . '">' . $cellContent . '</td>';
        }

        $html .= '</tr></table>';

        return $html;
    }

    /**
     * Render inner content from blocks or innerHTML
     *
     * @return string
     */
    protected function renderInnerContent(): string
    {
        if (!empty($this->innerBlocks) && $this->parser) {
            return $this->parser->renderNestedBlocks($this->innerBlocks);
        }

        return $this->innerHTML;
    }
}