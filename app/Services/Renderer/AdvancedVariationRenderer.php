<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\Framework\Support\Arr;

class AdvancedVariationRenderer
{
    protected $product;

    public function __construct($product)
    {
        $this->product = $product;
    }

    /**
     * Whitelist a term's stored color before it lands in an inline
     * `style="background-color: ..."` attribute. esc_attr escapes HTML
     * attribute chars but NOT CSS syntax — a stored value saved through
     * sanitize_text_field (which never validated color format) like
     * "red;position:fixed;inset:0;z-index:9999" would otherwise inject
     * extra CSS declarations and deface the product page. Accept only
     * hex (#rgb/#rgba/#rrggbb/#rrggbbaa), rgb()/rgba() with numeric
     * content, or a bare CSS named color; anything else returns '' and
     * the caller drops the style attribute entirely.
     */
    protected function safeCssColor($color): string
    {
        $color = trim((string) $color);
        if ($color === '') {
            return '';
        }
        if (preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color)) {
            return $color;
        }
        if (preg_match('/^rgba?\(\s*[0-9.,%\s]+\)$/i', $color)) {
            return $color;
        }
        if (preg_match('/^[a-zA-Z]{1,40}$/', $color)) {
            return $color;
        }
        return '';
    }

    /**
     * Emits the storefront selector markup. Returns true only when markup
     * was actually echoed — false on every early bail. The caller writes
     * this into the filter's 'rendered' flag so free core can fall through
     * to its simple-variation rendering when this renderer no-ops (e.g. a
     * product switched to advanced_variations before attributes are built).
     */
    public function render($selectorStyle = 'auto'): bool
    {
        $variants = $this->product->variants;
        if (!$variants || $variants->isEmpty()) {
            return false;
        }

        $variants->load(['attrMap', 'media']);

        $otherInfo = (array)Arr::get($this->product->detail, 'other_info');
        $attributeConfig = Arr::get($otherInfo, 'attribute_config', []);

        if (empty($attributeConfig)) {
            return false;
        }

        $allTermIds = [];
        foreach ($attributeConfig as $attrGroup) {
            // (array) cast — other_info is an independent read path; if a
            // corrupt or non-syncVariantOption writer ever stored a scalar
            // under 'variants', a bare array_merge would TypeError and fatal
            // the whole product page.
            $termIds = (array) Arr::get($attrGroup, 'variants', []);
            $allTermIds = array_merge($allTermIds, $termIds);
        }
        $allTermIds = array_unique(array_map('intval', $allTermIds));

        $terms = AttributeTerm::query()->whereIn('id', $allTermIds)->get()->keyBy('id');

        $groupIds = array_column($attributeConfig, 'group_id');
        $groups = AttributeGroup::query()->whereIn('id', array_map('intval', $groupIds))->get()->keyBy('id');

        $variationMap = [];
        $variantData = [];

        foreach ($variants as $variant) {
            if ($variant->item_status !== 'active') {
                continue;
            }

            $termIds = [];
            foreach ($variant->attrMap as $rel) {
                $termIds[] = (int)$rel->term_id;
            }
            sort($termIds, SORT_NUMERIC);
            $identifier = implode('_', $termIds);

            $variationMap[$identifier] = (int)$variant->id;

            $variantData[$variant->id] = [
                'stock' => ($variant->manage_stock ? ($variant->stock_status ?: 'in-stock') : 'in-stock'),
                // Raw — esc_attr on the JSON below escapes the HTML-attribute
                // context, and the JS consumer must render via textContent
                // (NOT innerHTML). Pre-escaping here would double-encode
                // titles like "S&L" into "S&amp;L" by the time JS reads
                // them, showing literal "&amp;" to the shopper.
                'title' => $variant->variation_title,
                // Authoritative payment_type column so the selector can hide
                // Add to Cart for subscription combinations (subscriptions are
                // Buy Now only) — mirrors the simple-variation flow.
                'payment_type' => $variant->payment_type,
                // serial_index so the selector can fall back to the first
                // combination by order (not first in-stock) when the product has
                // no default_variation_id — matching the server-side default rule.
                // Keep NULL as null (don't cast to 0) so the selector's
                // `?? Infinity` guard sorts an unordered legacy row LAST, matching
                // the server fallback — casting null->0 would make it sort first.
                'serial_index' => is_null($variant->serial_index) ? null : (int) $variant->serial_index,
            ];
        }

        // If every variant was filtered out (all inactive), there's nothing
        // for the selector to map to — bail before emitting markup that
        // would just be dead controls.
        if (empty($variationMap)) {
            return false;
        }

        // Authoritative group→terms ownership map — used by JS to avoid heuristic narrowing
        $attrConfigForJs = [];
        foreach ($attributeConfig as $attrGroup) {
            $groupId = absint(Arr::get($attrGroup, 'group_id'));
            $termIds = array_values(array_map('absint', Arr::get($attrGroup, 'variants', [])));
            $attrConfigForJs[$groupId] = $termIds;
        }

        // Auto-detect the primary visual group (color or image type) — its terms
        // drive the main gallery image swap on the storefront. First match wins.
        $primaryGroupId = 0;
        foreach ($attributeConfig as $attrGroup) {
            $gid   = absint(Arr::get($attrGroup, 'group_id'));
            $group = $groups->get($gid);
            $type  = $group ? Arr::get((array) $group->settings, 'type', '') : '';
            if (in_array($type, ['color', 'image'], true)) {
                $primaryGroupId = $gid;
                break;
            }
        }

        // Build term→image map for the primary group: first non-empty thumbnail
        // found among variants that belong to each term wins.
        $termImageMap = [];
        if ($primaryGroupId) {
            foreach ($variants as $variant) {
                $thumb = $variant->thumbnail ?: '';
                if (!$thumb) {
                    continue;
                }
                foreach ($variant->attrMap as $rel) {
                    $tid = (int) $rel->term_id;
                    if ((int) $rel->group_id === $primaryGroupId && !isset($termImageMap[$tid])) {
                        $termImageMap[$tid] = $thumb;
                    }
                }
            }
        }

        // wp_json_encode returns false on encoding failure (e.g. invalid UTF-8
        // in a term title); fall back to a parseable empty object so the JS
        // doesn't crash on JSON.parse(""). Default to {} (not []) because
        // the JS consumers expect object lookups by string key.
        $variationMapJson  = wp_json_encode($variationMap) ?: '{}';
        $variantDataJson   = wp_json_encode($variantData) ?: '{}';
        $attrConfigJson    = wp_json_encode($attrConfigForJs) ?: '{}';
        $termImageMapJson  = wp_json_encode($termImageMap) ?: '{}';

        // Surface the merchant's saved default variant id so the JS
        // selector can pre-select that combination on first paint. When
        // the column is empty (legacy products, or the merchant cleared
        // it), the JS falls back to the first combination by serial_index. Read
        // straight off the model — (array) cast on a WPFluent model
        // returns its public protected/$attributes shape inconsistently
        // depending on lazy-load state, so `default_variation_id` came
        // back null on first paint even when the column was set.
        $defaultVariationId = $this->product->detail
            ? (int) $this->product->detail->default_variation_id
            : 0;

        ?>
        <div class="fct-advanced-variation-wrap"
             data-variation-map="<?php echo esc_attr($variationMapJson); ?>"
             data-variant-data="<?php echo esc_attr($variantDataJson); ?>"
             data-attribute-config="<?php echo esc_attr($attrConfigJson); ?>"
             data-primary-group-id="<?php echo esc_attr($primaryGroupId); ?>"
             data-term-image-map="<?php echo esc_attr($termImageMapJson); ?>"
             data-product-id="<?php echo esc_attr($this->product->ID); ?>"
             data-default-variation-id="<?php echo esc_attr($defaultVariationId); ?>"
             data-selector-style="<?php echo esc_attr($selectorStyle); ?>">
            <?php
            foreach ($attributeConfig as $attrConfig) {
                $groupId = absint(Arr::get($attrConfig, 'group_id'));
                $group = isset($groups[$groupId]) ? $groups[$groupId] : null;
                $termIds = Arr::get($attrConfig, 'variants', []);

                if (!$group || empty($termIds)) {
                    continue;
                }

                $groupTerms = [];
                foreach ($termIds as $termId) {
                    $termId = absint($termId);
                    if (isset($terms[$termId])) {
                        $groupTerms[] = $terms[$termId];
                    }
                }

                // After term resolution, every claimed term ID could be a
                // term that no longer exists (deleted after the editor save).
                // Rendering an empty dropdown / swatch row would show a
                // ghost control with no options — skip the whole group.
                if (empty($groupTerms)) {
                    continue;
                }

                $groupSettings = is_array($group->settings) ? $group->settings : [];
                $groupType = Arr::get($groupSettings, 'type', 'options');
                $groupStyling = Arr::get($groupSettings, 'styling', '');

                do_action('fluent_cart/product/single/before_variant_item', [
                    'product' => $this->product,
                    'group'   => $group,
                    'scope'   => 'attribute_selector_group',
                ]);

                if ($groupType === 'color' && $groupStyling === 'dropdown') {
                    $this->renderColorDropdown($group, $groupTerms);
                } elseif ($groupType === 'image' && $groupStyling === 'dropdown') {
                    $this->renderImageDropdown($group, $groupTerms);
                } elseif ($groupStyling === 'dropdown') {
                    $this->renderAttributeDropdown($group, $groupTerms);
                } else {
                    $this->renderAttributeSwatches($group, $groupTerms);
                }

                do_action('fluent_cart/product/single/after_variant_item', [
                    'product' => $this->product,
                    'group'   => $group,
                    'scope'   => 'attribute_selector_group',
                ]);
            }
            ?>

        </div>
        <?php

        return true;
    }

    protected function renderAttributeDropdown($group, $terms)
    {
        ?>
        <?php $listboxId = 'fct-listbox-' . (int) $group->id; ?>
        <div class="fct-attribute-selector fct-custom-dropdown fct-options-dropdown"
             data-group-id="<?php echo esc_attr($group->id); ?>"
             data-attribute-group="<?php echo esc_attr($group->id); ?>">
            <label class="fct-attribute-label">
                <?php echo esc_html($group->title); ?>
            </label>

            <div class="fct-custom-select" data-fct-custom-select tabindex="0"
                 role="combobox"
                 aria-haspopup="listbox"
                 aria-expanded="false"
                 aria-controls="<?php echo esc_attr($listboxId); ?>"
                 aria-label="<?php echo esc_attr($group->title); ?>">
                <div class="fct-custom-select__trigger" data-fct-select-trigger>
                    <span class="fct-custom-select__value" data-fct-select-value>
                        <?php
                            /* translators: %1$s is the attribute group title */
                            printf(esc_html__('Choose %1$s', 'fluent-cart'), esc_html($group->title));
                        ?>
                    </span>
                    <span class="fct-custom-select__arrow" aria-hidden="true"></span>
                </div>
                <div class="fct-custom-select__options" role="listbox" id="<?php echo esc_attr($listboxId); ?>">
                    <?php foreach ($terms as $term) : ?>
                        <div class="fct-custom-select__option"
                             data-fct-select-option
                             role="option"
                             aria-selected="false"
                             data-value="<?php echo esc_attr($term->id); ?>"
                             data-term-slug="<?php echo esc_attr($term->slug); ?>">
                            <span class="fct-option-label">
                                <?php echo esc_html($term->title); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    protected function renderColorDropdown($group, $terms)
    {
        ?>
        <?php $listboxId = 'fct-listbox-' . (int) $group->id; ?>
        <div class="fct-attribute-selector fct-custom-dropdown fct-color-dropdown"
             data-group-id="<?php echo esc_attr($group->id); ?>"
             data-attribute-group="<?php echo esc_attr($group->id); ?>">
            <label class="fct-attribute-label">
                <?php echo esc_html($group->title); ?>
            </label>

            <div class="fct-custom-select" data-fct-custom-select tabindex="0"
                 role="combobox"
                 aria-haspopup="listbox"
                 aria-expanded="false"
                 aria-controls="<?php echo esc_attr($listboxId); ?>"
                 aria-label="<?php echo esc_attr($group->title); ?>">
                <div class="fct-custom-select__trigger" data-fct-select-trigger>
                    <span class="fct-custom-select__value" data-fct-select-value>
                        <?php
                            /* translators: %1$s is the attribute group title */
                            printf(esc_html__('Choose %1$s', 'fluent-cart'), esc_html($group->title));
                        ?>
                    </span>
                    <span class="fct-custom-select__arrow" aria-hidden="true"></span>
                </div>
                <div class="fct-custom-select__options" role="listbox" id="<?php echo esc_attr($listboxId); ?>">
                    <?php foreach ($terms as $term) :
                        $settings = is_array($term->settings) ? $term->settings : [];
                        $color = $this->safeCssColor(Arr::get($settings, 'color', ''));
                        ?>
                        <div class="fct-custom-select__option"
                             data-fct-select-option
                             role="option"
                             aria-selected="false"
                             data-value="<?php echo esc_attr($term->id); ?>"
                             data-term-slug="<?php echo esc_attr($term->slug); ?>">
                            <div class="fct-color-option">
                                <?php if ($color) : ?>
                                    <span class="fct-color-swatch" style="background-color: <?php echo esc_attr($color); ?>"></span>
                                <?php endif; ?>
                                <span class="fct-color-label">
                                    <?php echo esc_html($term->title); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    protected function renderImageDropdown($group, $terms)
    {
        ?>
        <?php $listboxId = 'fct-listbox-' . (int) $group->id; ?>
        <div class="fct-attribute-selector fct-custom-dropdown fct-image-dropdown"
             data-group-id="<?php echo esc_attr($group->id); ?>"
             data-attribute-group="<?php echo esc_attr($group->id); ?>">
            <label class="fct-attribute-label">
                <?php echo esc_html($group->title); ?>
            </label>

            <div class="fct-custom-select" data-fct-custom-select tabindex="0"
                 role="combobox"
                 aria-haspopup="listbox"
                 aria-expanded="false"
                 aria-controls="<?php echo esc_attr($listboxId); ?>"
                 aria-label="<?php echo esc_attr($group->title); ?>">
                <div class="fct-custom-select__trigger" data-fct-select-trigger>
                    <span class="fct-custom-select__value" data-fct-select-value>
                        <?php
                            /* translators: %1$s is the attribute group title */
                            printf(esc_html__('Choose %1$s', 'fluent-cart'), esc_html($group->title));
                        ?>
                    </span>
                    <span class="fct-custom-select__arrow" aria-hidden="true"></span>
                </div>
                <div class="fct-custom-select__options" role="listbox" id="<?php echo esc_attr($listboxId); ?>">
                    <?php foreach ($terms as $term) :
                        $settings = is_array($term->settings) ? $term->settings : [];
                        $image = Arr::get($settings, 'image', '');
                        ?>
                        <div class="fct-custom-select__option"
                             data-fct-select-option
                             role="option"
                             aria-selected="false"
                             data-value="<?php echo esc_attr($term->id); ?>"
                             data-term-slug="<?php echo esc_attr($term->slug); ?>">
                            <div class="fct-image-option">
                                <?php if ($image) : ?>
                                    <img
                                        class="fct-image-src"
                                        src="<?php echo esc_url($image); ?>"
                                        alt="<?php echo esc_attr($term->title); ?>"
                                    />
                                <?php endif; ?>
                                <span class="fct-image-label">
                                    <?php echo esc_html($term->title); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    protected function renderAttributeSwatches($group, $terms)
    {
        $groupSettings = is_array($group->settings) ? $group->settings : [];
        // Image groups render thumbnail-only pills (no caption under each
        // image), so the currently selected term's title is surfaced next to
        // the group label instead — e.g. "Image: Design 2".
        $isImageGroup = Arr::get($groupSettings, 'type') === 'image';
        ?>
        <div class="fct-attribute-selector fct-swatch-selector<?php echo $isImageGroup ? ' fct-image-group' : ''; ?>" data-group-id="<?php echo esc_attr($group->id); ?>">
            <label class="fct-attribute-label">
                <?php echo esc_html($group->title); ?>
                <?php if ($isImageGroup) : ?>
                    <span class="fct-selected-term-title" aria-live="polite"></span>
                <?php endif; ?>
            </label>
            <div class="fct-swatch-options" role="radiogroup" aria-label="<?php echo esc_attr($group->title); ?>">
                <?php foreach ($terms as $term) :
                    $settings = is_array($term->settings) ? $term->settings : [];
                    $color = $this->safeCssColor(Arr::get($settings, 'color', ''));
                    $image = Arr::get($settings, 'image', '');
                    ?>
                    <?php if (!empty($color)) : ?>
                        <button class="fct-swatch-item fct-swatch-color"
                                data-term-id="<?php echo esc_attr($term->id); ?>"
                                data-group-id="<?php echo esc_attr($group->id); ?>"
                                data-term-title="<?php echo esc_attr($term->title); ?>"
                                title="<?php echo esc_attr($term->title); ?>"
                                role="radio"
                                aria-checked="false"
                                tabindex="0"
                                type="button">
                            <span class="fct-swatch-dot" style="background-color: <?php echo esc_attr($color); ?>"></span>
                            <span class="fct-swatch-text"><?php echo esc_html($term->title); ?></span>
                        </button>
                    <?php elseif (!empty($image)) : ?>
                        <button class="fct-swatch-item fct-swatch-image<?php echo $isImageGroup ? ' fct-swatch-image-only' : ''; ?>"
                                data-term-id="<?php echo esc_attr($term->id); ?>"
                                data-group-id="<?php echo esc_attr($group->id); ?>"
                                data-term-title="<?php echo esc_attr($term->title); ?>"
                                title="<?php echo esc_attr($term->title); ?>"
                                role="radio"
                                aria-checked="false"
                                tabindex="0"
                                type="button">
                            <span class="fct-swatch-thumb">
                                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($term->title); ?>" />
                            </span>
                            <?php if (!$isImageGroup) : ?>
                                <span class="fct-swatch-text"><?php echo esc_html($term->title); ?></span>
                            <?php endif; ?>
                        </button>
                    <?php else : ?>
                        <button class="fct-swatch-item fct-swatch-label"
                                data-term-id="<?php echo esc_attr($term->id); ?>"
                                data-group-id="<?php echo esc_attr($group->id); ?>"
                                data-term-title="<?php echo esc_attr($term->title); ?>"
                                role="radio"
                                aria-checked="false"
                                tabindex="0"
                                type="button">
                            <?php echo esc_html($term->title); ?>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
