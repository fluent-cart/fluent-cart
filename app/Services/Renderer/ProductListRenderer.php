<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Framework\Support\Arr;

class ProductListRenderer
{
    protected $products = [];

    protected $listTitle = null;

    protected $wrapperClass = null;

    protected $cursor = null;

    protected $columns = 4;

    protected $hideExcerpt = false;

    protected $ratingContext = '';

    public function __construct($products, $listTitle = null, $wrapperClass = null, $config = [])
    {
        $this->products = $products;
        $this->listTitle = $listTitle;
        $this->wrapperClass = $wrapperClass;
        $columns = Arr::get($config, 'columns', 4);
        $this->columns = max(1, min(6, intval($columns)));
        $this->hideExcerpt = Arr::get($config, 'hide_excerpt', false);
        $this->ratingContext = Arr::get($config, 'rating_context', '');

        if($products instanceof \FluentCart\Framework\Pagination\CursorPaginator){
            $this->cursor = wp_parse_args(wp_parse_url($products->nextPageUrl(), PHP_URL_QUERY));
            $this->cursor = Arr::get($this->cursor, 'cursor', '');
        }

    }

    public function render()
    {
        if (
            (is_array($this->products) && empty($this->products)) ||
            ($this->products instanceof \FluentCart\Framework\Pagination\CursorPaginator && $this->products->count() === 0)
        ) {
            return '';
        }

        $columns = $this->columns
        ? 'style="--fct-product-list-columns: ' . $this->columns . ';"'
        : '';

        ?>
        <section class="fct-product-list-container <?php echo esc_attr($this->wrapperClass); ?>" aria-label="<?php echo esc_attr($this->listTitle ?: __('Product List', 'fluent-cart')); ?>">
            <?php $this->renderTitle(); ?>
            <div
                class="fct-product-list"
                role="list"
                aria-live="polite"
                aria-busy="false"
                <?php echo $columns; ?>
            >
                <?php $this->renderProductList(); ?>
            </div>
        </section>
        <?php
    }

    public function renderProductList()
    {

        foreach ($this->products as $index => $product) {
            $config = ['hide_excerpt' => $this->hideExcerpt];
            if ($this->ratingContext) {
                $config['rating_context'] = $this->ratingContext;
            }
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
