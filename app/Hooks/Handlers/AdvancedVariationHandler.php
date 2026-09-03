<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Services\TemplateService;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\AdvancedVariationService;
use FluentCart\App\Services\Renderer\AdvancedVariationRenderer;

class AdvancedVariationHandler
{
    public function register()
    {
        add_filter('fluent_cart/variation_types', function ($types) {
            $types[Helper::PRODUCT_TYPE_ADVANCE_VARIATION] = __('Advanced Variations', 'fluent-cart');
            return $types;
        });

        add_filter('fluent_cart/product/get_response_data', function ($payload) {
            $product       = Arr::get($payload, 'product', []);
            $variationType = Arr::get($product, 'detail.variation_type');

            if ($variationType !== Helper::PRODUCT_TYPE_ADVANCE_VARIATION) {
                return $payload;
            }

            $payload['product'] = AdvancedVariationService::hydrateProductData($product);
            return $payload;
        });

        // Declared with the 2-arg signature to match the apply_filters site
        // contract ($variant, $postId). $postId is intentionally unused — this
        // handler only strips a derived field from the variant payload before
        // save; the postId is available for future handlers that need scoping.
        add_filter('fluent_cart/product/variant_save_data', function ($variant, $postId) {
            // attr_map is hydrated from AttributeRelation — not a column on fc_product_variations.
            unset($variant['attr_map']);
            return $variant;
        }, 10, 2);

        add_filter('fluent_cart/product/variant_option_sync', function ($payload) {
            $data          = Arr::get($payload, 'data', []);
            $variationType = Arr::get($data, 'variation_type');

            if ($variationType !== Helper::PRODUCT_TYPE_ADVANCE_VARIATION) {
                return $payload;
            }

            $productId = (int) Arr::get($payload, 'product_id');

            $payload['handled']  = true;
            $payload['response'] = AdvancedVariationService::syncVariantOption($productId, $data);

            return $payload;
        });

        // Build both gallery variation maps for the product renderer.
        // variant_term_map:        variantId → primary-visual-term ID (color/image group).
        // variant_first_media_map: variantId → { id, url } of the variant's first image.
        add_filter('fluent_cart/product/gallery_variation_data', function ($data, $product) {
            $otherInfo  = (array) Arr::get($product->detail, 'other_info');
            $attrConfig = (array) Arr::get($otherInfo, 'attribute_config', []);
            if (empty($attrConfig)) {
                return $data;
            }

            $groupIds = array_filter(array_map(function ($g) {
                return (int) Arr::get($g, 'group_id');
            }, $attrConfig));
            if (empty($groupIds)) {
                return $data;
            }

            $groups         = AttributeGroup::whereIn('id', $groupIds)->get()->keyBy('id');
            $primaryGroupId = 0;
            $primaryTermIds = [];
            foreach ($attrConfig as $group) {
                $groupId        = (int) Arr::get($group, 'group_id');
                $attributeGroup = $groups->get($groupId);
                $type           = $attributeGroup ? Arr::get((array) $attributeGroup->settings, 'type', '') : '';
                if (in_array($type, ['color', 'image'], true)) {
                    $primaryGroupId = $groupId;
                    $primaryTermIds = array_map('intval', (array) Arr::get($group, 'variants', []));
                    break;
                }
            }
            if (!$primaryGroupId) {
                return $data;
            }

            $primaryTermSet = array_flip($primaryTermIds);
            $variantTermMap = [];
            foreach ($product->variants as $variant) {
                $identifier = Arr::get($variant, 'variation_identifier', '');
                if (!$identifier) {
                    continue;
                }
                foreach (array_map('intval', explode('_', $identifier)) as $tid) {
                    if (isset($primaryTermSet[$tid])) {
                        $variantTermMap[(int) Arr::get($variant, 'id')] = $tid;
                        break;
                    }
                }
            }

            $variantFirstMediaMap = [];
            foreach ($product->variants as $variant) {
                $variantId = (int) Arr::get($variant, 'id');
                $media     = Arr::get($variant, 'media.meta_value', []);
                if (empty($media) || !is_array($media)) {
                    continue;
                }
                $firstItem = $media[0] ?? null;
                if (!$firstItem) {
                    continue;
                }
                $firstUrl     = Arr::get($firstItem, 'url', '');
                $firstMediaId = (int) Arr::get($firstItem, 'id', 0);
                if ($firstUrl) {
                    $variantFirstMediaMap[$variantId] = [
                        'id'  => $firstMediaId,
                        'url' => $firstUrl,
                    ];
                }
            }

            $data['variant_term_map']        = $variantTermMap;
            $data['variant_first_media_map'] = $variantFirstMediaMap;
            return $data;
        }, 10, 2);

        add_filter('fluent_cart/product/render_advanced_variation', function ($context) {
            // Storefront server-side render. The renderer echoes HTML
            // directly into the product template's output buffer (the
            // apply_filters site captures that output around the call).
            // render() returns true only when it actually emitted markup —
            // it no-ops for a product switched to advanced variations before
            // its attributes are configured. Propagate that bool into
            // 'rendered' so core skips its default variation block when we
            // rendered, and falls through to its simple-variation rendering
            // when we didn't.
            $product       = Arr::get($context, 'product');
            $selectorStyle = Arr::get($context, 'selector_style', 'auto');
            if ($product) {
                $context['rendered'] = (new AdvancedVariationRenderer($product))->render($selectorStyle);
            }
            return $context;
        });

        // Storefront assets for the variation selector. Enqueued on every
        // page type that can host an advanced-variation product UI: the
        // single-product page itself, plus shop and product-taxonomy
        // archives (which render product cards that open the View Options
        // modal — the same rendered selector markup, just inside a dialog
        // instead of the main column). Cart / checkout / receipt / login /
        // registration / customer-dashboard stay excluded so they don't pay
        // the asset weight. Filterable for custom contexts.
        $advVariationAssetPages = apply_filters(
            'fluent_cart/advanced_variation/asset_page_types',
            ['single_product', 'shop', 'product_taxonomy']
        );

        add_action('wp_enqueue_scripts', function () use ($advVariationAssetPages) {
            if (!in_array(TemplateService::getCurrentFcPageType(), $advVariationAssetPages, true)) {
                return;
            }
            Vite::enqueueStyle(
                'fluent-cart-advanced-variation-style',
                'public/single-product/advanced-variations.scss'
            );
        });

        // Single hook for the advanced-variation selector assets. Core fires
        // fluent_cart/advanced_variation/enqueue_assets from every product
        // purchase surface — Shop, Product List, Product Carousel, Related
        // Products, Product Info, Buy Section, the (single) product shortcodes
        // and the quick-view modal. It may fire more than once per request, so
        // a static guard registers the assets a single time.
        add_action('fluent_cart/advanced_variation/enqueue_assets', function () {
            static $enqueued = false;
            if ($enqueued) {
                return;
            }
            $enqueued = true;
            Vite::enqueueStyle(
                'fluent-cart-advanced-variation-style',
                'public/single-product/advanced-variations.scss'
            );
            Vite::enqueueScript(
                'fluent-cart-advanced-variation-public',
                'public/single-product/advanced-variation-public.js',
                [],
                null,
                true
            );
        });

        add_action('wp_enqueue_scripts', function () use ($advVariationAssetPages) {
            if (!in_array(TemplateService::getCurrentFcPageType(), $advVariationAssetPages, true)) {
                return;
            }
            Vite::enqueueScript(
                'fluent-cart-advanced-variation-public',
                'public/single-product/advanced-variation-public.js',
                [],
                null,
                true
            );
        });
    }
}
