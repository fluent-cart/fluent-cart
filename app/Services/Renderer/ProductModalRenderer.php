<?php
namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\StoreSettings;
use FluentCart\Api\Resource\ShopResource;
use FluentCart\App\Models\Product;

class ProductModalRenderer
{
    protected $product;
    protected $config = [];
    protected $storeSettings;

    public function __construct(Product $product, $config = [])
    {
        $this->product = $product;
        $this->config = $config;
        $this->storeSettings = new StoreSettings();
    }

    public function render()
    {
        ?>
        <div
            class="fct-product-modal"
            data-fluent-cart-shop-app-single-product-modal
            role="dialog"
            aria-modal="true"
            aria-label="<?php esc_attr($this->product->post_title); ?>"
        >
            <div
                    data-fluent-cart-shop-app-single-product-modal-overlay
                    class="fct-product-modal-overlay"
                    role="presentation"
                    aria-hidden="true"
            >
            </div>
            <div class="fct-product-modal-body">
                <button
                    class="fct-product-modal-close"
                    data-fluent-cart-shop-app-single-product-modal-close
                    type="button"
                    aria-label="<?php esc_attr_e('Close product modal', 'fluent-cart'); ?>"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <path d="M12.8337 1.16663L1.16699 12.8333M1.16699 1.16663L12.8337 12.8333" stroke="#2F3448" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <?php
                    (new ProductRenderer($this->product, [
                        'view_type'   => $this->storeSettings->get('variation_view', 'both'),
                        'column_type' => $this->storeSettings->get('variation_columns', 'masonry')
                    ]))->render();

                    $this->renderRelevantProducts();
                ?>
            </div>
        </div>
        <?php

    }

    /**
     * Related products below the modal's product, when the store asks for
     * them. Off by default, so a quick view stays a quick view unless the
     * setting is turned on.
     *
     * @return void
     */
    protected function renderRelevantProducts()
    {
        $showRelevant = $this->storeSettings->get('show_relevant_product_in_modal') == 'yes';
        $showRelevant = apply_filters(
            'fluent_cart/product_modal/show_relevant_products',
            $showRelevant,
            $this->product->ID
        );

        if (!$showRelevant) {
            return;
        }

        $products = ShopResource::getSimilarProducts($this->product->ID, false);

        if (empty($products)) {
            return;
        }

        (new ProductListRenderer(
            $products,
            __('Related Products', 'fluent-cart'),
            'fct-similar-product-list-container',
            ['rating_context' => 'relevant']
        ))->render();
    }
}
