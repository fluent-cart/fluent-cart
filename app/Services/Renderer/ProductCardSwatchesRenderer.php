<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Modules\MultiCurrency\Services\StorefrontPrice;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

/**
 * Attribute swatches for product cards (one colour per term, with that colour's image).
 *
 * Attribute links and variant images are loaded for every product on the page at once,
 * so a grid of cards costs a few queries, not a few per card.
 */
class ProductCardSwatchesRenderer
{
    /** @var array<int, array<int, int>> variantId => [groupId => termId] */
    private static $variantTerms = [];

    /** @var array<int, string> variantId => image url */
    private static $variantImages = [];

    /** @var array<int, AttributeTerm> */
    private static $terms = [];

    /** @var array<int, AttributeGroup> */
    private static $groups = [];

    /** @var array<int, true> products whose variants were already preloaded */
    private static $preloadedProducts = [];

    const MAX_ROWS = 10;

    /** Row id of a simple-variations product's options, which have no attribute group. */
    const OPTION_ROW = 'option';

    /**
     * One swatch row per attribute group, plus every active variant's values so a click can
     * match all rows at once.
     *
     * @param int[]     $groupIds     The picked attributes, in order.
     * @param string    $optionLabel  Name of a simple-variations product's one row; empty means "Option".
     * @param bool|null $includeOther True adds the product's other attributes after the picked ones, in
     *                                library order; false shows only the picked ones. Null (saved pages,
     *                                builders) keeps the old rule: no pick shows every attribute.
     * @param int[]     $hiddenGroupIds Attributes the merchant unticked: never added as "other" ones.
     * @return array{rows: array[], variants: array[], resolves: bool}
     */
    public static function rowsForProduct(Product $product, array $groupIds = [], string $optionLabel = '', ?bool $includeOther = null, array $hiddenGroupIds = []): array
    {
        self::preload(array_merge(ProductDataSetup::getCachedProducts(), [$product->ID => $product]));
        $variants = self::activeVariants($product);

        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if ($includeOther === null ? !$groupIds : $includeOther) {
            $hidden = array_flip(array_map('intval', $hiddenGroupIds));
            $others = array_filter(self::productGroupIds($variants), function ($groupId) use ($hidden) {
                return !isset($hidden[(int) $groupId]);
            });
            $groupIds = array_values(array_unique(array_merge($groupIds, $others)));
        }

        // Up to MAX_ROWS rows the product has: picks it does not use must not take the slots.
        $rows = [];
        foreach ($groupIds as $groupId) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
            $row = self::forProduct($product, $groupId);
            if ($row) {
                $rows[] = $row;
            }
        }

        $isOptionList = !$rows && $variants->isNotEmpty() && $product->detail
            && $product->detail->variation_type === Helper::PRODUCT_TYPE_SIMPLE_VARIATION;
        if ($isOptionList) {
            $rows[] = self::optionRow($product, $variants, $optionLabel);
        }

        // The card button may only act for the rows when they always pin a single variant.
        $shownGroups = array_column($rows, 'group_id');
        $resolves = (bool) $rows;
        $manageStock = ModuleSettings::isActive('stock_management');
        $isBundle = $product->isBundleProduct();
        $variantData = [];
        $combinations = [];
        $tax = new \FluentCart\App\Modules\Tax\TaxModule();
        $taxSettings = (array) get_option('fluent_cart_tax_configuration_settings', []);
        foreach ($variants as $variant) {
            $terms = $isOptionList ? [self::OPTION_ROW => (int) $variant->id] : (self::$variantTerms[$variant->id] ?? []);
            $signatureTerms = $terms;
            ksort($signatureTerms);
            $signature = wp_json_encode($signatureTerms);
            if (array_diff(array_keys($terms), $shownGroups) || array_diff($shownGroups, array_keys($terms)) || isset($combinations[$signature])) {
                $resolves = false;
            }

            $combinations[$signature] = true;

            // isStock() reads the product; hand it over instead of a query per variant.
            $variant->setRelation('product', $product);
            $isSubscription = $variant->payment_type === 'subscription';
            $variantData[] = [
                'id'           => (int) $variant->id,
                'terms'        => $terms,
                'image'        => self::$variantImages[$variant->id] ?? '',
                'in_stock'     => !$manageStock || $isBundle || $variant->isStock(),
                'subscription' => $isSubscription,
                'price'        => $isSubscription
                    ? $variant->getSubscriptionTermsText()
                    : StorefrontPrice::format((int) apply_filters('fluent_cart/product/display_price', $variant->item_price, [
                        'product'   => $product,
                        'variation' => $variant,
                    ])),
                'price_suffix' => RenderHelper::getPriceSuffix($product, $variant, 'product_card', $tax->getPriceSuffix($variant, $taxSettings), [
                    'tax_inclusion' => Arr::get($variant->other_info ?: [], 'tax_inclusion') ?: Arr::get($taxSettings, 'tax_inclusion', 'included'),
                ]),
                'purchase_url' => $isSubscription ? $variant->getPurchaseUrl() : '',
            ];
        }

        return ['rows' => $rows, 'variants' => $variantData, 'resolves' => $resolves];
    }

    /**
     * What the card's button becomes for the picked variant; null keeps View Options.
     * Bundles keep it too: their stock depends on child items the card does not load.
     */
    private static function cardButtonConfig(Product $product, array $swatchData): ?array
    {
        if (!$swatchData['resolves'] || $product->isBundleProduct()) {
            return null;
        }

        $context = RenderGate::context($product, RenderGate::SCOPE_CARD);
        $canAdd = RenderGate::shouldRenderPurchaseButton('add_to_cart_button', $context);
        $canBuy = RenderGate::shouldRenderPurchaseButton('buy_now_button', $context);
        if (!$canAdd && !$canBuy) {
            return null;
        }

        return [
            'can_add' => $canAdd,
            'can_buy' => $canBuy,
            'modal'   => Helper::isModalCheckoutEnabled(),
            'labels'  => [
                'add'          => __('Add To Cart', 'fluent-cart'),
                'buy'          => __('Buy Now', 'fluent-cart'),
                'out_of_stock' => __('Out of stock', 'fluent-cart'),
                'unavailable'  => __('Not available', 'fluent-cart'),
                'choose'       => __('Select options', 'fluent-cart'),
            ],
        ];
    }

    /**
     * Simple variations have no attributes: their variants become one row of options.
     */
    private static function optionRow(Product $product, Collection $variants, string $label): array
    {
        $productUrl = get_permalink($product->ID);
        $defaultVariantId = (int) $product->detail->default_variation_id;

        $terms = [];
        foreach ($variants as $variant) {
            $terms[] = [
                'term_id'    => (int) $variant->id,
                'title'      => $variant->variation_title,
                'serial'     => (int) $variant->serial_index,
                'color'      => '',
                'term_image' => '',
                'image'      => self::$variantImages[$variant->id] ?? '',
                'url'        => add_query_arg('selected', (int) $variant->id, $productUrl),
                'is_default' => (int) $variant->id === $defaultVariantId,
            ];
        }

        return [
            'group_id'    => self::OPTION_ROW,
            'group_type'  => 'label',
            'group_title' => $label !== '' ? $label : __('Option', 'fluent-cart'),
            'terms'       => $terms,
        ];
    }

    /**
     * The swatch rows for one product card, shared by the Gutenberg block and the page
     * builder add-ons. Settings: group_ids (picked, in order), show_other_groups (unset: empty group_ids = all),
     * max_visible (0 = all), sold_out_display ('normal', 'cross' or 'hide'; simple_sold_out_display overrides it for list products), show_label, show_group_name,
     * selected_position ('name' or 'below'), advanced_enabled; simple variations: simple_enabled, simple_label,
     * simple_max_visible, simple_show_label, simple_show_selected (unset follows show_label), simple_selected_position.
     *
     * @param string $wrapperAttributes Escaped attributes for the wrapper; must include its class.
     */
    public static function render(Product $product, array $settings, string $wrapperAttributes = 'class="fct-card-swatch-rows"'): string
    {
        // Builders store toggles as 'yes'/'on', the block as booleans.
        $isOn = function ($key, $default) use ($settings) {
            return filter_var(Arr::get($settings, $key, $default), FILTER_VALIDATE_BOOLEAN);
        };

        // Attribute rows count as advanced variations, whatever type a product was saved with.
        $isOptionList = $product->detail && $product->detail->variation_type === Helper::PRODUCT_TYPE_SIMPLE_VARIATION;
        if (!$isOn($isOptionList ? 'simple_enabled' : 'advanced_enabled', true)) {
            return '';
        }

        $groupIds = array_map('absint', (array) Arr::get($settings, 'group_ids', []));
        $optionLabel = sanitize_text_field((string) Arr::get($settings, 'simple_label', ''));
        // Unset (saved pages, builders) keeps the old rule; the block's checkbox sends true or false.
        $includeOther = Arr::get($settings, 'show_other_groups');
        $includeOther = $includeOther === null || $includeOther === '' ? null : $isOn('show_other_groups', false);
        // Every unticked attribute counts: the editor loads 100 at a time, so a big catalog can save more.
        // 1000 is far past any store's attribute count, and only bounds the input.
        $hiddenGroupIds = array_slice(array_map('absint', (array) Arr::get($settings, 'hidden_group_ids', [])), 0, 1000);
        $swatchData = self::rowsForProduct($product, $groupIds, $optionLabel, $includeOther, $hiddenGroupIds);
        if (!$swatchData['rows']) {
            return '';
        }

        // Sold-out values: 'cross' keeps them, struck through and not pickable; 'hide' drops them
        // before How many to show counts. 'normal' (saved pages, builders) keeps today's swatches.
        $soldOutDisplay = Arr::get($settings, 'sold_out_display', 'normal');
        // A page builder can set this per type: the list's own, when given, wins for list products.
        $listSoldOut = Arr::get($settings, 'simple_sold_out_display');
        if ($isOptionList && in_array($listSoldOut, ['normal', 'cross', 'hide'], true)) {
            $soldOutDisplay = $listSoldOut;
        }
        $marksSoldOut = in_array($soldOutDisplay, ['cross', 'hide'], true);
        // The card's buy button follows the product's own stock too, so a product marked out of stock
        // has every value sold out, whatever its variants say.
        $productSoldOut = $marksSoldOut && ModuleSettings::isActive('stock_management') && !$product->isStock();
        $soldOut = $marksSoldOut ? self::soldOutTerms($swatchData, $productSoldOut) : [];
        if ($soldOutDisplay === 'hide' && $soldOut) {
            foreach ($swatchData['rows'] as $index => $row) {
                $swatchData['rows'][$index]['terms'] = array_values(array_filter($row['terms'], function ($term) use ($soldOut, $row) {
                    return !self::isSoldOut($soldOut, $row['group_id'], $term);
                }));
            }
            $swatchData['rows'] = array_values(array_filter($swatchData['rows'], function ($row) {
                return (bool) $row['terms'];
            }));
            if (!$swatchData['rows']) {
                return '';
            }
            // The card script picks variants from this list; one using a hidden value has no button.
            // Such a variant is sold out anyway (its value has no in-stock variant).
            $swatchData['variants'] = array_values(array_filter($swatchData['variants'], function ($variant) use ($soldOut) {
                foreach ($variant['terms'] as $groupId => $termId) {
                    if (!empty($soldOut[$groupId][(int) $termId])) {
                        return false;
                    }
                }
                return true;
            }));
            $soldOut = [];
        }
        if ($marksSoldOut) {
            // The script takes the first variant with a clicked value, so in-stock ones come first.
            $isInStock = function ($variant) {
                return $variant['in_stock'];
            };
            $swatchData['variants'] = array_merge(
                array_values(array_filter($swatchData['variants'], $isInStock)),
                array_values(array_filter($swatchData['variants'], function ($variant) use ($isInStock) {
                    return !$isInStock($variant);
                }))
            );
        }
        /* translators: %s: an attribute value, e.g. XL */
        $soldOutLabel = __('%s, sold out', 'fluent-cart');

        $maxVisible = $isOptionList ? Arr::get($settings, 'simple_max_visible', 4) : Arr::get($settings, 'max_visible', 3);
        // 0 is the block's "All": every value shows. Anything else stays within 1–12. The editor's
        // preview request sends block numbers as floats (0.0), so a numeric zero counts too.
        $maxVisible = is_numeric($maxVisible) && (float) $maxVisible === 0.0 ? PHP_INT_MAX : max(1, min(12, (int) $maxVisible));
        // Each product type has its own selected-choice switch; list products without one (saved
        // pages, builders) follow show_label, which used to cover both.
        $ownSelected = $isOptionList ? Arr::get($settings, 'simple_show_selected') : null;
        $showLabel = $ownSelected === null || $ownSelected === '' ? $isOn('show_label', false) : $isOn('simple_show_selected', false);
        $selectedBelow = $showLabel && Arr::get($settings, $isOptionList ? 'simple_selected_position' : 'selected_position') === 'below';
        $selectedAfterName = $showLabel && !$selectedBelow;
        $selectedValues = [];
        $showGroupName = $isOn($isOptionList ? 'simple_show_label' : 'show_group_name', false);
        $cardButton = self::cardButtonConfig($product, $swatchData);
        // The +N chip opens quick view, which follows the card's action row like View Options.
        $canQuickView = RenderGate::shouldRender('actions', RenderGate::context($product, RenderGate::SCOPE_CARD));
        $productUrl = get_permalink($product->ID);
        $startTerms = self::startTerms($product, $swatchData, $maxVisible, $soldOut, $marksSoldOut);

        ob_start();
        ?>
        <div <?php echo $wrapperAttributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
             data-fct-card-swatch-rows
             <?php if ($cardButton) : ?>data-card-button="<?php echo esc_attr(wp_json_encode($cardButton)); ?>"<?php endif; ?>
             data-product-url="<?php echo esc_url($productUrl); ?>"
             data-variants="<?php echo esc_attr(wp_json_encode($swatchData['variants'])); ?>"
             <?php if ($marksSoldOut) : ?>
                 <?php // With Cross out the script also marks a value sold out in combination with the other rows' picks. ?>
                 data-sold-out-display="<?php echo esc_attr($soldOutDisplay); ?>"
                 data-sold-out-label="<?php echo esc_attr($soldOutLabel); ?>"
                 <?php /* translators: %s: an attribute value, e.g. XL */ ?>
                 data-unavailable-label="<?php echo esc_attr__('%s, not available', 'fluent-cart'); ?>"
             <?php endif; ?>>
            <?php foreach ($swatchData['rows'] as $swatches) :
                $visibleTerms = array_slice($swatches['terms'], 0, $maxVisible);
                $hiddenCount = count($swatches['terms']) - count($visibleTerms);

                // Start on the default variant's value, else the first, so the label matches the card.
                // A crossed-out value is never the start: with every shown value crossed out, none is.
                $isSoldOut = function ($term) use ($soldOut, $swatches) {
                    return self::isSoldOut($soldOut, $swatches['group_id'], $term);
                };
                $activeIndex = null;
                $firstAvailable = null;
                foreach ($visibleTerms as $index => $term) {
                    if ($isSoldOut($term)) {
                        continue;
                    }
                    $firstAvailable = $firstAvailable ?? $index;
                    if ($startTerms !== null ? (int) $term['term_id'] === (int) ($startTerms[$swatches['group_id']] ?? 0) : $term['is_default']) {
                        $activeIndex = $index;
                        break;
                    }
                }
                $activeIndex = $activeIndex ?? $firstAvailable;
                if ($activeIndex !== null) {
                    $selectedValues[] = $visibleTerms[$activeIndex]['title'];
                }
                ?>
                <div class="fct-card-swatches is-<?php echo esc_attr($swatches['group_type']); ?>"
                     data-fct-card-swatches
                     data-group-id="<?php echo esc_attr($swatches['group_id']); ?>"
                     role="group"
                     aria-label="<?php echo esc_attr($swatches['group_title']); ?>">
                    <?php if ($showGroupName || $selectedAfterName) : ?>
                        <?php // One caption line above the swatches, e.g. "COLOR · Black". ?>
                        <div class="fct-card-swatch-heading">
                            <?php if ($showGroupName) : ?>
                                <?php // The group's aria-label already names it for screen readers. ?>
                                <span class="fct-card-swatch-group-name" aria-hidden="true"><?php echo esc_html($swatches['group_title']); ?></span>
                            <?php endif; ?>
                            <?php if ($selectedAfterName) : ?>
                                <span class="fct-card-swatch-label" data-fct-card-swatch-label aria-live="polite"><?php echo esc_html($activeIndex !== null ? $visibleTerms[$activeIndex]['title'] : ''); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="fct-card-swatch-list">
                        <?php foreach ($visibleTerms as $index => $term) :
                            $termImage = $term['term_image'] ?: $term['image'];
                            $showsText = $swatches['group_type'] !== 'color' && !($swatches['group_type'] === 'image' && $termImage);
                            ?>
                            <button type="button"
                                    class="fct-card-swatch"
                                    data-fct-card-swatch
                                    data-term-id="<?php echo esc_attr($term['term_id']); ?>"
                                    data-image="<?php echo esc_url($term['image']); ?>"
                                    data-url="<?php echo esc_url($term['url']); ?>"
                                    data-label="<?php echo esc_attr($term['title']); ?>"
                                    aria-pressed="<?php echo $index === $activeIndex ? 'true' : 'false'; ?>"
                                    <?php if ($isSoldOut($term)) : ?>
                                        <?php // Still focusable, so a screen reader hears that it is sold out; the script ignores clicks. ?>
                                        data-sold-out aria-disabled="true"
                                        aria-label="<?php echo esc_attr(sprintf($soldOutLabel, $term['title'])); ?>"
                                    <?php else : ?>
                                        aria-label="<?php echo esc_attr($term['title']); ?>"
                                    <?php endif; ?>
                                    <?php // A native tooltip swaps the hand cursor for an arrow, so only a swatch without visible text gets one. ?>
                                    <?php if (!$showsText) : ?>title="<?php echo esc_attr($term['title']); ?>"<?php endif; ?>>
                                <?php if ($swatches['group_type'] === 'color') : ?>
                                    <span class="fct-card-swatch-fill" aria-hidden="true"
                                          style="<?php echo $term['color'] ? esc_attr('background-color: ' . $term['color']) : ''; ?>"></span>
                                <?php elseif ($swatches['group_type'] === 'image' && ($term['term_image'] || $term['image'])) : ?>
                                    <img class="fct-card-swatch-fill" src="<?php echo esc_url($term['term_image'] ?: $term['image']); ?>"
                                         alt="" loading="lazy" width="36" height="36">
                                <?php else : ?>
                                    <span class="fct-card-swatch-text" aria-hidden="true"><?php echo esc_html($term['title']); ?></span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                        <?php if ($hiddenCount > 0) :
                            /* translators: 1: number of hidden values, 2: attribute name */
                            $moreLabel = sprintf(__('%1$d more %2$s choices', 'fluent-cart'), $hiddenCount, $swatches['group_title']);
                            ?>
                            <?php if ($canQuickView) : ?>
                                <button type="button"
                                        class="fct-card-swatch-more"
                                        data-fct-card-swatch-more
                                        data-fluent-cart-single-product-card-view-button
                                        data-product-id="<?php echo esc_attr($product->ID); ?>"
                                        aria-label="<?php echo esc_attr($moreLabel); ?>">
                                    +<?php echo esc_html($hiddenCount); ?>
                                </button>
                            <?php else : ?>
                                <a class="fct-card-swatch-more" data-fct-card-swatch-more href="<?php echo esc_url($productUrl); ?>"
                                   aria-label="<?php echo esc_attr($moreLabel); ?>">+<?php echo esc_html($hiddenCount); ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                </div>
            <?php endforeach; ?>
            <?php if ($selectedBelow) : ?>
                <?php // One line under every row, e.g. "Selected: Navy · M"; the script keeps it in step. ?>
                <div class="fct-card-swatch-selected" aria-live="polite" aria-atomic="true">
                    <?php
                    $values = '<span data-fct-card-swatch-selected-values>' . esc_html(implode(' · ', $selectedValues)) . '</span>';
                    /* translators: %s: the picked value of each row, e.g. "Navy · M" */
                    echo sprintf(esc_html__('Selected: %s', 'fluent-cart'), $values); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** The attributes the product's variants use, in the store's attribute order. */
    private static function productGroupIds($variants): array
    {
        $groupIds = [];
        foreach ($variants as $variant) {
            $groupIds = array_merge($groupIds, array_keys(self::$variantTerms[$variant->id] ?? []));
        }
        $groupIds = array_values(array_unique($groupIds));
        usort($groupIds, function ($a, $b) {
            $serialA = (int) (self::$groups[$a]->serial ?? 0);
            $serialB = (int) (self::$groups[$b]->serial ?? 0);
            return $serialA === $serialB ? $a <=> $b : $serialA <=> $serialB;
        });
        return $groupIds;
    }

    /**
     * The rows start on each one's default (or first) value. When those make a combination the
     * product does not sell, start every row on one variant instead: the default when all its
     * values are visible, else the first that is. Null keeps the per-row start.
     */
    private static function startTerms(Product $product, array $swatchData, int $maxVisible, array $soldOut = [], bool $preferInStock = false): ?array
    {
        $visible = [];
        $perRow = [];
        foreach ($swatchData['rows'] as $row) {
            $terms = array_slice($row['terms'], 0, $maxVisible);
            $visible[$row['group_id']] = array_map('intval', array_column($terms, 'term_id'));
            // Crossed-out values are never the start, unless the whole row is sold out.
            $available = array_values(array_filter($terms, function ($term) use ($soldOut, $row) {
                return !self::isSoldOut($soldOut, $row['group_id'], $term);
            })) ?: $terms;
            $default = array_values(array_filter($available, function ($term) {
                return $term['is_default'];
            }));
            $perRow[$row['group_id']] = (int) ($default ? $default[0]['term_id'] : $available[0]['term_id']);
        }
        foreach ($swatchData['variants'] as $variant) {
            $sold = true;
            foreach ($perRow as $groupId => $termId) {
                if ((int) ($variant['terms'][$groupId] ?? 0) !== $termId) {
                    $sold = false;
                    break;
                }
            }
            // The per-row start is a real variant; while sold-out values are marked it must also be in stock.
            if ($sold && (!$preferInStock || $variant['in_stock'])) {
                return null;
            }
        }
        $defaultVariantId = $product->detail ? (int) $product->detail->default_variation_id : 0;
        $variants = $swatchData['variants'];
        usort($variants, function ($a, $b) use ($defaultVariantId, $preferInStock) {
            // While sold-out values are marked, a variant in stock starts first.
            if ($preferInStock && $a['in_stock'] !== $b['in_stock']) {
                return $a['in_stock'] ? -1 : 1;
            }
            return ((int) ($b['id'] === $defaultVariantId)) <=> ((int) ($a['id'] === $defaultVariantId));
        });
        foreach ($variants as $variant) {
            $fits = true;
            foreach ($visible as $groupId => $termIds) {
                $termId = (int) ($variant['terms'][$groupId] ?? 0);
                // A crossed-out value never starts, so a variant using one would start mixed with the fallback.
                if (!in_array($termId, $termIds, true) || !empty($soldOut[$groupId][$termId])) {
                    $fits = false;
                    break;
                }
            }
            if ($fits) {
                return $variant['terms'];
            }
        }
        return null;
    }

    private static function isSoldOut(array $soldOut, $groupId, array $term): bool
    {
        return !empty($soldOut[$groupId][(int) $term['term_id']]);
    }

    /**
     * The values no in-stock variant has, by row: [group_id => [term_id => true]]. A list product's
     * one row ('option') holds variant ids, so there a value is sold out when its variant is.
     * A product marked out of stock ($productSoldOut) has every value sold out.
     */
    private static function soldOutTerms(array $swatchData, bool $productSoldOut = false): array
    {
        $inStock = [];
        foreach ($productSoldOut ? [] : $swatchData['variants'] as $variant) {
            if ($variant['in_stock']) {
                foreach ($variant['terms'] as $groupId => $termId) {
                    $inStock[$groupId][(int) $termId] = true;
                }
            }
        }
        $soldOut = [];
        foreach ($swatchData['rows'] as $row) {
            foreach ($row['terms'] as $term) {
                if (empty($inStock[$row['group_id']][(int) $term['term_id']])) {
                    $soldOut[$row['group_id']][(int) $term['term_id']] = true;
                }
            }
        }
        return $soldOut;
    }

    /**
     * Queue the swatch styles and click handler. Call before any early return, since cards
     * loaded later by AJAX bring no stylesheet of their own.
     */
    public static function enqueueAssets(): void
    {
        Vite::enqueueStyle(
            'fluent-cart-product-swatches',
            'admin/BlockEditor/ProductSwatches/style/product-swatches-block-editor.scss'
        );
        // The click handler ships with the product card script. If another card block loaded
        // it first and rendered empty, WP 6.9 dropped it and the loader won't queue it twice.
        AssetLoader::loadProductCardAssets();
        wp_enqueue_script('fluent-cart-product-card-js');
    }

    /**
     * @param int $groupId 0 picks the product's first colour or image group.
     * @return array|null ['group_id', 'group_type', 'group_title', 'terms' => [...]]
     */
    public static function forProduct(Product $product, int $groupId = 0): ?array
    {
        self::preload(array_merge(ProductDataSetup::getCachedProducts(), [$product->ID => $product]));

        $variants = self::activeVariants($product);
        if ($variants->isEmpty()) {
            return null;
        }

        $groupId = $groupId ?: self::pickVisualGroup($variants);
        $group = self::$groups[$groupId] ?? null;
        if (!$group) {
            return null;
        }

        $productUrl = get_permalink($product->ID);
        $defaultVariantId = $product->detail ? (int) $product->detail->default_variation_id : 0;
        $settingsType = (string) Arr::get((array) $group->settings, 'type', '');

        $terms = [];
        foreach ($variants as $variant) {
            $termId = self::$variantTerms[$variant->id][$groupId] ?? 0;
            $term = self::$terms[$termId] ?? null;
            if (!$term) {
                continue;
            }

            $image = self::$variantImages[$variant->id] ?? '';

            if (isset($terms[$termId])) {
                // The first variant with an image supplies the swatch's image.
                if (!$terms[$termId]['image'] && $image) {
                    $terms[$termId]['image'] = $image;
                }
                if ((int) $variant->id === $defaultVariantId) {
                    $terms[$termId]['is_default'] = true;
                }
                continue;
            }

            $termSettings = (array) $term->settings;
            $terms[$termId] = [
                'term_id'    => (int) $termId,
                'title'      => $term->title,
                'serial'     => (int) $term->serial,
                'color'      => AdvancedVariationRenderer::safeCssColor(Arr::get($termSettings, 'color', '')),
                'term_image' => (string) Arr::get($termSettings, 'image', ''),
                'image'      => $image,
                'url'        => add_query_arg('selected', (int) $variant->id, $productUrl),
                'is_default' => (int) $variant->id === $defaultVariantId,
            ];
        }

        if (!$terms) {
            return null;
        }

        uasort($terms, function ($a, $b) {
            return $a['serial'] <=> $b['serial'];
        });

        return [
            'group_id'    => (int) $group->id,
            'group_type'  => in_array($settingsType, ['color', 'image'], true) ? $settingsType : 'label',
            'group_title' => $group->title,
            'terms'       => array_values($terms),
        ];
    }

    private static function activeVariants(Product $product): Collection
    {
        return (new Collection($product->variants ?: []))
            ->filter(function ($variant) {
                return $variant->item_status === 'active';
            })
            ->sortBy('serial_index')
            ->values();
    }

    private static function pickVisualGroup($variants): int
    {
        $fallback = 0;
        foreach ($variants as $variant) {
            foreach (array_keys(self::$variantTerms[$variant->id] ?? []) as $groupId) {
                $group = self::$groups[$groupId] ?? null;
                if (!$group) {
                    continue;
                }
                if (in_array(Arr::get((array) $group->settings, 'type', ''), ['color', 'image'], true)) {
                    return (int) $groupId;
                }
                $fallback = $fallback ?: (int) $groupId;
            }
        }

        // Without a colour or image group, the first group still gives useful choices.
        return $fallback;
    }

    /**
     * Load attribute links, terms, groups and variant images for variants not seen yet.
     */
    private static function preload(array $products): void
    {
        // Every card passes the whole page; only the products not seen yet need work.
        $products = array_filter($products, function ($product) {
            return !isset(self::$preloadedProducts[(int) $product->ID]);
        });
        if (!$products) {
            return;
        }
        foreach ($products as $product) {
            self::$preloadedProducts[(int) $product->ID] = true;
        }

        self::loadMissingVariants($products);

        $variantIds = [];
        foreach ($products as $product) {
            foreach ($product->variants ?: [] as $variant) {
                if (!array_key_exists($variant->id, self::$variantTerms)) {
                    $variantIds[] = (int) $variant->id;
                    self::$variantTerms[$variant->id] = [];
                }
            }
        }

        if (!$variantIds) {
            return;
        }

        foreach (array_chunk($variantIds, 500) as $chunk) {
            $relations = AttributeRelation::query()
                ->whereIn('object_id', $chunk)
                ->get(['object_id', 'group_id', 'term_id']);

            foreach ($relations as $relation) {
                self::$variantTerms[(int) $relation->object_id][(int) $relation->group_id] = (int) $relation->term_id;
            }

            $thumbnails = ProductMeta::query()
                ->whereIn('object_id', $chunk)
                ->where('meta_key', 'product_thumbnail')
                ->get(['object_id', 'meta_value']);

            foreach ($thumbnails as $thumbnail) {
                $url = Arr::get((array) $thumbnail->meta_value, '0.url', '');
                if ($url) {
                    self::$variantImages[(int) $thumbnail->object_id] = $url;
                }
            }
        }

        $termIds = [];
        $groupIds = [];
        foreach (self::$variantTerms as $groupTerms) {
            foreach ($groupTerms as $groupId => $termId) {
                if (!isset(self::$terms[$termId])) {
                    $termIds[$termId] = $termId;
                }
                if (!isset(self::$groups[$groupId])) {
                    $groupIds[$groupId] = $groupId;
                }
            }
        }

        if ($termIds) {
            foreach (AttributeTerm::query()->whereIn('id', array_values($termIds))->get() as $term) {
                self::$terms[(int) $term->id] = $term;
            }
        }

        if ($groupIds) {
            foreach (AttributeGroup::query()->whereIn('id', array_values($groupIds))->get() as $group) {
                self::$groups[(int) $group->id] = $group;
            }
        }
    }

    /**
     * Carousels and related products hand over products without variants; one query
     * loads them for all, instead of one lazy load per card.
     */
    private static function loadMissingVariants(array $products): void
    {
        $missing = [];
        foreach ($products as $product) {
            if ($product instanceof Product && !$product->relationLoaded('variants')) {
                $missing[(int) $product->ID] = $product;
            }
        }

        if (!$missing) {
            return;
        }

        $variantsByProduct = ProductVariation::query()
            ->search(['post_id' => ['operator' => 'in', 'value' => array_keys($missing)]])
            ->get()
            ->groupBy('post_id');

        foreach ($missing as $productId => $product) {
            $product->setRelation('variants', $variantsByProduct->get($productId) ?: $product->newCollection());
        }
    }
}
