<?php

namespace FluentCart\App\Modules\Templating\BlockTemplates;

/**
 * ProductCategoryTemplate class.
 *
 */
class ProductModalTemplate
{

    /**
     * The slug of the template.
     *
     * @var string
     */
    const SLUG = 'fct-single-product-modal';

    /**
     * Initialization method.
     */
    public function init()
    {
        register_block_template('fluent-cart//fct-single-product-modal', [
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
        return _x('Single Product Modal', 'Template name', 'fluent-cart');
    }

    /**
     * Returns the description of the template.
     *
     * @return string
     */
    public function getDescription()
    {
        return __('Template for Single Product Modal.', 'fluent-cart');
    }


    public function getDefaultTemplate()
    {
        ob_start();
        ?>
        <!-- wp:group {"tagName":"main","templateLock":"contentOnly","lock":{"move":false,"remove":false},"className":"alignfull is-style-default","layout":{"type":"default"}} -->
        <main class="wp-block-group alignfull is-style-default">
            <!-- wp:group {"lock":{"move":false,"remove":false},"align":"wide","layout":{"type":"default"}} -->
            <div class="wp-block-group alignwide"><!-- wp:shortcode -->[fluent_cart_product_header]
                <!-- /wp:shortcode --></div>
            <!-- /wp:group --></main>
        <!-- /wp:group -->
        <?php
        return ob_get_clean();
    }

    /**
     * Render the template with custom content if modified by user
     *
     * @return string
     */
    public function render()
    {
        $templates = get_block_templates(['slug__in' => [self::SLUG]]);
        if (!empty($templates) && isset($templates[0]->content)) {
            $content = $templates[0]->content;
            return do_shortcode(do_blocks($content));
        }

        return do_shortcode(do_blocks($this->getDefaultTemplate()));
    }
}
