<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Framework\Support\Arr;

class ProductListRenderer
{
    protected $products = [];

    protected $listTitle = null;

    protected $wrapperClass = null;

    protected $cursor = null;

    public function __construct($products, $listTitle = null, $wrapperClass = null)
    {
        $this->products = $products;
        $this->listTitle = $listTitle;
        $this->wrapperClass = $wrapperClass;

        if($products instanceof \FluentCart\Framework\Pagination\CursorPaginator){
            $this->cursor = wp_parse_args(wp_parse_url($products->nextPageUrl(), PHP_URL_QUERY));
            $this->cursor = Arr::get($this->cursor, 'cursor', '');
        }

    }

    public function render()
    {
        ?>
        <section class="fct-product-list-container <?php echo esc_attr($this->wrapperClass); ?>" aria-label="<?php echo esc_attr($this->listTitle ?: __('Product List', 'fluent-cart')); ?>">
            <?php $this->renderTitle(); ?>
            <div
                class="fct-product-list"
                role="list"
                aria-live="polite"
                aria-busy="false"
            >
                <?php $this->renderProductList(); ?>
            </div>
        </section>
        <?php
    }

    public function renderProductList()
    {

        foreach ($this->products as $index => $product) {
            $config = [];
            if($index == 0 && $this->cursor){
                $config['cursor'] = $this->cursor;
            }
            ?>
            <div
                class="fct-product-list-item"
                role="listitem"
            >
                <?php (new ProductCardRender($product, $config))->render(); ?>
            </div>

            <?php
        }
    }

    public function renderTitle() {

        if(!empty($this->listTitle)) : ?>
            <h4 class="fct-product-list-heading">
                <?php echo esc_html($this->listTitle); ?>
            </h4>
        <?php endif;

    }

}
