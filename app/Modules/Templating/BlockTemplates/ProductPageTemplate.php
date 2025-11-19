<?php

namespace FluentCart\App\Modules\Templating\BlockTemplates;

/**
 * ProductCategoryTemplate class.
 *
 */
class ProductPageTemplate
{

    /**
     * The slug of the template.
     *
     * @var string
     */
    const SLUG = 'single-fluent-products';

    /**
     * Initialization method.
     */
    public function init()
    {
        register_block_template('fluent-cart//single-fluent-products', [
            'title'       => $this->getTitle(),
            'description' => $this->getDescription(),
            'content'     => $this->getDefaultTemplate(),
        ]);
    }

    /**
     * Returns the title of the template.
     *
     * @return string
     */
    public function getTitle()
    {
        return _x('Single Product', 'Template name', 'fluent-cart');
    }

    /**
     * Returns the description of the template.
     *
     * @return string
     */
    public function getDescription()
    {
        return __('Displays Single FluentCart Product.', 'fluent-cart');
    }


    public function getDefaultTemplate()
    {
        ob_start();
        ?>


        <!-- wp:template-part {"slug":"header","tagName":"header","area":"header"} /-->

        <!-- wp:group {"tagName":"main","align":"full","className":"is-style-default","layout":{"type":"constrained"}} -->
        <main class="wp-block-group alignfull is-style-default"><!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
            <div class="wp-block-group alignwide"><!-- wp:spacer {"height":"var:preset|spacing|40"} -->
                <div style="height:var(--wp--preset--spacing--40)" aria-hidden="true" class="wp-block-spacer"></div>
                <!-- /wp:spacer -->

                <!-- wp:fluent-cart/product-info -->
                <div class="wp-block-fluent-cart-product-info my-plugin-product-card"><div class="product-card-inner"><!-- wp:columns -->
                        <div class="wp-block-columns"><!-- wp:column -->
                            <div class="wp-block-column"><!-- wp:fluent-cart/product-gallery {"inside_product_info":"yes"} /--></div>
                            <!-- /wp:column -->

                            <!-- wp:column -->
                            <div class="wp-block-column"><!-- wp:post-title /-->

                                <!-- wp:fluent-cart/stock /-->

                                <!-- wp:fluent-cart/buy-section {"inside_product_info":"yes"} /--></div>
                            <!-- /wp:column --></div>
                        <!-- /wp:columns --></div></div>
                <!-- /wp:fluent-cart/product-info --></div>
            <!-- /wp:group -->

            <!-- wp:post-content {"lock":{"move":false,"remove":true},"align":"wide","style":{"spacing":{"blockGap":"0","padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"default"}} /-->

            <!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
            <div class="wp-block-group alignwide"><!-- wp:shortcode -->
                [fluent_cart_related_products]
                <!-- /wp:shortcode --></div>
            <!-- /wp:group --></main>
        <!-- /wp:group -->

        <!-- wp:template-part {"slug":"footer","tagName":"footer","area":"footer"} /-->
        <?php
        return ob_get_clean();
    }
}
