<?php

namespace FluentCart\App\Modules\Templating;

use FluentCart\Api\Resource\ShopResource;
use FluentCart\Api\StoreSettings;
use FluentCart\Api\Taxonomy;
use FluentCart\App\CPT\FluentProducts;
//use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\AddToCartShortcode;
use FluentCart\App\Hooks\Handlers\ShortCodes\SingleProductShortCode;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Modules\Data\ProductQuery;
use FluentCart\App\Services\Renderer\ProductListRenderer;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Renderer\ShopAppRenderer;
use FluentCart\App\Services\TemplateService;
use FluentCart\Framework\Support\Arr;

class TemplateActions
{
    public function register()
    {
        add_action('fluent_cart/template/main_content', [$this, 'renderMainContent']);
        add_action('fluent_cart/template/product_archive', [$this, 'renderProductArchive']);
        add_action('fluent_cart/product/render_product_header', [$this, 'renderProductHeader']);

        add_shortcode('fluent_cart_product_header', function ($atts = []) {
            $atts = shortcode_atts([
                'id' => 0,
            ], $atts);

            $productId = absint($atts['id']);

            if (!$productId) {
                $productId = get_the_ID();
            }

            if (!$productId) {
                return 'Product ID not found';
            }

            $product = ProductDataSetup::getProductModel($productId);
            if (!$product || !$product->detail) {
                return 'Product not found';
            }

            ob_start();
            $this->renderProductHeader($product->ID);
            $content = ob_get_clean();

            return $this->tempFixShortcodeContent($content);
        });

        add_shortcode('fluent_cart_related_products', function ($atts = []) {
            $atts = shortcode_atts([
                'id' => 0,
            ], $atts);

            $productId = absint($atts['id']);

            if (!$productId) {
                $productId = get_the_ID();
            }

            if (!$productId) {
                return __('Product ID not found', 'fluent-cart');
            }

            $product = ProductDataSetup::getProductModel($productId);
            if (!$product || !$product->detail) {
                return __('Product not found', 'fluent-cart');
            }


            $products = ShopResource::getSimilarProducts($productId, false);
            if (empty($products['products'])) {
                return '';
            }

            ob_start();
            (new ProductListRenderer(
                $products['products'],
                __('Related Products', 'fluent-cart'),
                'fct-similar-product-list-container'
            ))->render();

            $content = ob_get_clean();

            return $this->tempFixShortcodeContent($content);
        });

        add_action('fluent_cart/template/before_content', [$this, 'renderArchiveHeader']);

    }

    public function renderMainContent()
    {
        $isTaxPages = is_tax(get_object_taxonomies('fluent-products'));
        if ($isTaxPages) {
            do_action('fluent_cart/template/product_archive');
        }
    }

    public function renderArchiveHeader()
    {
        if (TemplateService::getCurrentFcPageType() !== 'product_taxonomy') {
            return;
        }

        ?>
        <div class="fct-archive-header-wrap">
            <h1 class="fct-archive-title">
                <?php
                $queried_object = get_queried_object();
                if ($queried_object && !empty($queried_object->name)) {
                    echo esc_html($queried_object->name);
                } else {
                    echo esc_html(get_the_archive_title());
                }
                ?>
            </h1>
            <?php
            $termDescription = term_description();
            if ($termDescription) {
                ?>
                <div class="fct-archive-description">
                    <?php echo wp_kses_post(wpautop($termDescription)); ?>
                </div>
                <?php
            }
            ?>
        </div>
        <?php

    }

    public function renderProductArchive()
    {
        $queried_object = get_queried_object();

        if (empty($queried_object->taxonomy) || !is_tax(get_object_taxonomies('fluent-products'))) {
            return;
        }

        $productsQuery = (new ProductQuery([
            'is_main_query' => true,
            'paginate'      => 'simple',
            'with'          => ['detail', 'variants']
        ]));

        $publicTaxonomies = Taxonomy::getTaxonomies();


        unset($publicTaxonomies[$queried_object->taxonomy]);

        $connectedTerms = $productsQuery->getConnectedTerms(array_values($publicTaxonomies), true);
        //TODO: also add the child terms
        $taxFilters = [];

        foreach ($connectedTerms as $taxonomyName => $term) {

            $taxObj = get_taxonomy($taxonomyName);
            if (!$taxObj) {
                continue;
            }

            $taxFilters[$taxonomyName] = [
                'options' => $term['terms'],
                'term' => $taxonomyName,
                'label'   => $taxObj->label
            ];
        }

        $products = $productsQuery->get();
        (new ShopAppRenderer($products, [
            'default_filters' => $productsQuery->getDefaultFilters(),
            'custom_filters'  => [
                'taxonomies'  => [
                    $taxFilters
                ],
                'search'      => true,
                'price_range' => true,
            ],
            'pagination_type' => 'simple'
        ]))->render();
    }

    public function initSingleProductHooks()
    {
        add_filter('the_title', function ($title, int $post_id) {
            if (apply_filters('fluent_cart/disable_auto_single_product_page', false)) {
                return $title;
            }

            global $post;
            global $wp_query;

            if (
                // this is the main query for a single product page
                $wp_query->is_main_query()
                // post_id is get_queried id
                && $post_id === $wp_query->get_queried_object_id()
                && $post->post_type === FluentProducts::CPT_NAME
            ) {
                return '';
            }

            return $title;
        }, 10, 2);
        add_filter('the_content', [$this, 'filterSingleProductContent'], 999);
    }

    public function filterSingleProductContent($content)
    {
        if (apply_filters('fluent_cart/disable_auto_single_product_page', false)) {
            return $content;
        }

        global $post;
        global $wp_query;

        if (!$wp_query->is_main_query() && $post->post_type !== FluentProducts::CPT_NAME) {
            return $content;
        }

        remove_filter('the_content', [$this, 'filterSingleProductContent']);
        ob_start();
        do_action('fluent_cart/product/render_product_header', $post->ID);
        $headerContent = ob_get_clean();
        $content = $headerContent . $content;

        $storeSettings = new StoreSettings();

        $showRelevant = $storeSettings->get('show_relevant_product_in_single_page') == 'yes';
        $showRelevant = apply_filters('fluent_cart/single_product_page/show_relevant_products', $showRelevant, $post->ID);
        if ($showRelevant) {
            $products = ShopResource::getSimilarProducts($post->ID, false);
            ob_start();
            (new ProductListRenderer(
                  Arr::get($products, 'products', []),
                __('Related Products', 'fluent-cart'),
                'fct-similar-product-list-container'
            ))->render();

            $relevantProducts = ob_get_clean();
            $content .= $relevantProducts;
        }

        return $content;
    }

    public function renderProductHeader($productId = false)
    {
        if (!$productId) {
            $productId = get_the_ID();
        }

        if (!$productId) {
            return;
        }

        $product = ProductDataSetup::getProductModel($productId);
        if (!$product || !$product->detail) {
            return;
        }

        $storeSettings = new StoreSettings();
        (new ProductRenderer($product, [
            'view_type'   => $storeSettings->get('variation_view', 'both'),
            'column_type' => $storeSettings->get('variation_columns', 'masonry')
        ]))->render();
    }

    private function tempFixShortcodeContent($content)
    {

        if (!$content) {
            return $content;
        }

        // Remove empty <p> tags (including those with whitespace), <br> tags, and new lines
        $cleaned = preg_replace([
            '/<p>\s*<\/p>/i',             // Remove empty <p> tags
            '/<p>\s*<br\s*\/?>\s*<\/p>/i', // Remove <p> tags containing only <br>
            '/<br\s*\/?>/i',              // Remove all <br> tags
            '/[\r\n]+/'                   // Remove all new lines
        ], '', $content);

        // Remove extra whitespace between tags to clean up the output
        return preg_replace('/>\s+</', '><', $cleaned);
    }

}
