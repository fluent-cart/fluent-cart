<?php
namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Models\Product;

class ProductModalRenderer
{
    protected $product;
    protected $config = [];

    public function __construct(Product $product, $config = [])
    {
        $this->product = $product;
        $this->config = $config;
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

                <?php (new ProductRenderer($this->product))->render(); ?>
            </div>
        </div>
        <?php

    }

}
