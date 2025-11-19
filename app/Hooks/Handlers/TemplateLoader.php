<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\ProductDetail;

class TemplateLoader
{
    public function init()
    {
        add_action('fluent_cart/single_product_summary', [$this, 'showTitle'], 10);
        add_action('fluent_cart/single_product_summary', [$this, 'showExcerpt'], 20);
        add_action('fluent_cart/single_product_summary', [$this, 'showBuy'], 25);
    }

    public function showTitle()
    {
        global $post;
        echo wp_kses_post('<h1>' . $post->post_title . '</h1>');
    }

    public function showExcerpt()
    {
        global $post;
        $short_description = apply_filters('fluent_cart/product_short_description', $post->post_excerpt, []);
        ?>
        <div class="fct_product-details_short_description">
            <?php echo wp_kses_post($short_description); // WPCS: XSS ok.
            ?>
        </div>
        <?php
    }

    public function showBuy()
    {
        global $product_detail;

        ?>
        <p class="<?php echo esc_attr(apply_filters('fluent_cart/price_class', 'price')); ?>"><?php echo wp_kses_post($product_detail->getPriceHtml()); ?></p>
        <button type="submit" name="add-to-cart" value="<?php echo esc_attr($product_detail->id); ?>"
                class="single_add_to_cart_button button alt">Buy Now
        </button>
        <?php
    }

    // public function loadTemplate($template)
    // {
    //     if (is_singular(FluentProducts::CPT_NAME)) {
    //         global $post;
    //         unset($GLOBALS['product_detail']);
    //         $product = ProductDetail::where('product_id', $post->ID)->first();

    //         $GLOBALS['product_detail'] = $product;

    //         return FLUENTCART_PLUGIN_PATH . 'templates/single-product.php';
    //     }

    //     return $template;
    // }
}
