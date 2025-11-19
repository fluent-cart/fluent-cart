<?php

namespace FluentCart\App\Modules\Templating\Bricks;

use Bricks\Elements;
use Bricks\Query;
use FluentCart\Framework\Support\Arr;

class BricksLoader
{
    public function register()
    {
        if (!defined('BRICKS_VERSION')) {
            return;
        }

        add_action('init', [$this, 'loadElements'], 20);

        (new DynamicData())->register();

        add_filter('fluent_cart/template/disable_taxonomy_fallback', function ($result) {
            $bricks_data = \Bricks\Database::get_template_data('archive');

            if ($bricks_data) {
                return true;
            }

            return $result;
        });

        add_filter('fluent_cart/products_views/preload_collection_bricks', [$this, 'preloadProductCollectionsAjax'], 10, 2);

    }

    public function loadElements()
    {
        $elements = [
            'ProductTitle'            => 'fct-product-title',
            'ProductShortDescription' => 'fct-product-short-description',
            'ProductContent'          => 'fct-product-content',
            'ProductStock'            => 'fct-product-stock',
            'PriceRange'              => 'fct-price-range',
            'ProductAddToCart'        => 'fct-product-buy-section',
            'ProductGallery'          => 'fct-product-gallery',
            'ProductsCollection'      => 'fct-products',
        ];

        foreach ($elements as $elementKey => $elementName) {
            $elementFile = FLUENTCART_PLUGIN_PATH . "app/Modules/Templating/Bricks/Elements/$elementKey.php";
            $className = "\\FluentCart\\App\\Modules\\Templating\\Bricks\\Elements\\$elementKey";
            Elements::register_element($elementFile, $elementName, $className);
        }

    }

    public function preloadProductCollectionsAjax($view, $args)
    {
        $products = $args['products'];
        $clientId = Arr::get($args, 'client_id', '');

        $settings = get_transient('fc_bx_collection_' . $clientId);
        if (!$settings) {
            return $view;
        }

        do_action('fluent_community/bricks/rendering_ajax_collection');

        ob_start();
        $post_index = 1;

        foreach ($products as $product) {
            $post = get_post($product->ID);
            setup_postdata($post);
            BricksHelper::setFormCurrentPost($post);
            BricksHelper::renderCollectionCard($settings, $product, $post_index, $clientId);

            $post_index++;
        }
        wp_reset_postdata();

        return ob_get_clean();
    }
}
