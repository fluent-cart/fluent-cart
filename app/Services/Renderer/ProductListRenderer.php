<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Modules\Data\ProductDataSetup;
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

    protected $cardConfig = [];

    /** @var callable|null A builder's own card markup for one product, in place of core's card. */
    protected $renderCard = null;

    public function __construct($products, $listTitle = null, $wrapperClass = null, $config = [])
    {
        $this->products = $products;
        $this->listTitle = $listTitle;
        $this->wrapperClass = $wrapperClass;
        $columns = Arr::get($config, 'columns', 4);
        $this->columns = max(1, min(6, intval($columns)));
        $this->hideExcerpt = Arr::get($config, 'hide_excerpt', false);
        $this->ratingContext = Arr::get($config, 'rating_context', '');
        $this->cardConfig = Arr::only($config, ['card_layout', 'layout_price_format', 'layout_options']);
        $this->renderCard = is_callable(Arr::get($config, 'render_card')) ? $config['render_card'] : null;

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

        ?>
        <section class="fct-product-list-container <?php echo esc_attr($this->wrapperClass); ?>" aria-label="<?php echo esc_attr($this->listTitle ?: __('Product List', 'fluent-cart')); ?>">
            <?php $this->renderTitle(); ?>
            <div
                class="fct-product-list"
                role="list"
                aria-live="polite"
                aria-busy="false"
                style="--fct-product-list-columns: <?php echo (int) $this->columns; ?>;"
            >
                <?php $this->renderProductList(); ?>
            </div>
        </section>
        <?php
    }

    public function renderProductList()
    {
        // Layout cards render swatches, which batch their queries over the cached products.
        if (Arr::get($this->cardConfig, 'card_layout', '')) {
            ProductDataSetup::setProductsCache($this->products);
        }

        foreach ($this->products as $index => $product) {
            $config = array_merge($this->cardConfig, ['hide_excerpt' => $this->hideExcerpt]);
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
                <?php
                if ($this->renderCard) {
                    echo call_user_func($this->renderCard, $product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the builder escapes its own card
                } else {
                    (new ProductCardRender($product, $config))->render();
                }
                ?>
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
