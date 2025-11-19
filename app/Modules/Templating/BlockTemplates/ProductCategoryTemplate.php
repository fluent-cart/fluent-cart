<?php

namespace FluentCart\App\Modules\Templating\BlockTemplates;

/**
 * ProductCategoryTemplate class.
 *
 */
class ProductCategoryTemplate
{

    /**
     * The slug of the template.
     *
     * @var string
     */
    const SLUG = 'taxonomy-product-categories';

    /**
     * Initialization method.
     */
    public function init()
    {
        register_block_template('fluent-cart//taxonomy-product-categories', [
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
        return _x('Products by Category', 'Template name', 'fluent-cart');
    }

    /**
     * Returns the description of the template.
     *
     * @return string
     */
    public function getDescription()
    {
        return __('Displays products filtered by a category.', 'fluent-cart');
    }

    public function getDefaultTemplate()
    {
        ob_start();
        ?>
        <!-- wp:template-part {"slug":"header","tagName":"header","area":"header"} /-->

        <!-- wp:group {"tagName":"main","align":"full","layout":{"type":"constrained"}} -->
        <main class="wp-block-group alignfull"><!-- wp:query-title {"type":"archive","showPrefix":false,"align":"wide","style":{"typography":{"lineHeight":"1"},"spacing":{"padding":{"top":"var:preset|spacing|20"}}}} /-->

            <!-- wp:term-description {"align":"wide"} /-->

            <!-- wp:group {"className":"alignwide","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
            <div class="wp-block-group alignwide"><!-- wp:fluent-cart/products {"colors":{}} -->
                <div class="wp-block-fluent-cart-products"><div class="fct-products-wrapper" data-fluent-cart-shop-app="true" data-fluent-cart-product-wrapper=""><!-- wp:fluent-cart/shopapp-product-view-switcher {"className":"fluent-product-view-switcher","metadata":{"name":"View Switcher"}} /-->

                        <!-- wp:fluent-cart/shopapp-product-container {"className":"fluent-product-container"} -->
                        <div class="wp-block-fluent-cart-shopapp-product-container fluent-product-container" data-fluent-cart-shop-app-product-list=""><!-- wp:fluent-cart/shopapp-product-filter -->
                            <div class="fluent-cart-product-filter-wrapper"><!-- wp:fluent-cart/shopapp-product-filter-search-box /-->

                                <!-- wp:fluent-cart/shopapp-product-filter-filters /-->

                                <!-- wp:fluent-cart/shopapp-product-filter-button -->
                                <div class="fct-product-block-filter-item"><!-- wp:fluent-cart/shopapp-product-filter-apply-button /-->

                                    <!-- wp:fluent-cart/shopapp-product-filter-reset-button /--></div>
                                <!-- /wp:fluent-cart/shopapp-product-filter-button --></div>
                            <!-- /wp:fluent-cart/shopapp-product-filter -->

                            <!-- wp:fluent-cart/shopapp-product-loop {"wp_client_id":"694667c7-282b-4595-9acd-98b8c2e13c6e","last_changed":"2025-09-28T09:02:35.262Z","className":"fluent-product-loop","metadata":{"name":"Product Loop"}} -->
                            <div class="fluent-cart-product-loop fct-product-block-editor-product-card"><!-- wp:fluent-cart/shopapp-product-image -->
                                <div class="fluent-cart-product-image"></div>
                                <!-- /wp:fluent-cart/shopapp-product-image -->

                                <!-- wp:fluent-cart/shopapp-product-title /-->

                                <!-- wp:fluent-cart/shopapp-product-price /-->

                                <!-- wp:fluent-cart/shopapp-product-buttons /--></div>
                            <!-- /wp:fluent-cart/shopapp-product-loop --></div>
                        <!-- /wp:fluent-cart/shopapp-product-container -->

                        <!-- wp:fluent-cart/product-paginator {"className":"fluent-product-paginator","metadata":{"name":"Paginator"}} -->
                        <div class="fluent-cart-product-paginator"><!-- wp:fluent-cart/product-paginator-info /-->

                            <!-- wp:fluent-cart/product-paginator-number /--></div>
                        <!-- /wp:fluent-cart/product-paginator -->

                        <!-- wp:fluent-cart/shopapp-product-no-result {"className":"fluent-product-no-result","metadata":{"name":"No Result"}} -->
                        <div class="fct-product-block-filter-item"><!-- wp:paragraph {"align":"center","fontSize":"large"} -->
                            <p class="has-text-align-center has-large-font-size">No results found</p>
                            <!-- /wp:paragraph -->

                            <!-- wp:paragraph {"align":"center"} -->
                            <p class="has-text-align-center">You can try <a href="#">clearing any filters</a> or head to our <a href="#">store's home</a></p>
                            <!-- /wp:paragraph --></div>
                        <!-- /wp:fluent-cart/shopapp-product-no-result --></div></div>
                <!-- /wp:fluent-cart/products --></div>
            <!-- /wp:group --></main>
        <!-- /wp:group -->

        <!-- wp:template-part {"slug":"footer","tagName":"footer","area":"footer"} /-->
        <?php
        return ob_get_clean();
    }
}
