<?php

namespace FluentCart\App\Services\Renderer;
use FluentCart\Api\StoreSettings;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Vite;
use FluentCart\App\Helpers\Helper;
use FluentCart\Api\Resource\ShopResource;

class SearchBarRenderer
{

    protected $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;

    }

    public function render(){
        $wrapperAttributes = [
            'class' => 'fluent-cart-search-bar-app-wrapper',
            'data-link-with-shop-app' => esc_attr(Arr::get($this->config, 'link_with_shop_app', false)),
            'data-fluent-cart-search-bar-app-wrapper' => '',
            'data-url-mode' => esc_attr(Arr::get($this->config, 'url_mode', '')),
        ];

        ?>

            <div <?php RenderHelper::renderAtts($wrapperAttributes); ?> role="search">
                <div class="fluent-cart-search-bar-app-wrapper-header-wrap">
                    <?php 
                        if (Arr::get($this->config, 'category_mode', '')){
                            $this->renderCategory();
                        } 
                    ?>

                    <?php $this->renderInput(); ?>
                </div>

                <?php $this->renderSearchResult(); ?>
            </div>
            

        <?php
    }

    public function renderCategory(){
        ?>
        
            <div class="fluent-cart-search-bar-app-wrapper-select-container" style="display: inline;">
                <label for="fluent-cart-search-category" class="sr-only">
                    <?php esc_html_e('Select Category', 'fluent-cart'); ?>
                </label>

                <select
                    id="fluent-cart-search-category"
                    data-fluent-cart-search-bar-app-taxonomy 
                    name="termId"
                    style="padding: 10px; border: 1px solid #D3D3D3; border-radius: 8px; font-size: 16px; background-color: #fff; margin-right: 10px;"
                    aria-describedby="category-help"
                >
                    <option selected value="">
                        <?php echo esc_html__('Select Category', 'fluent-cart'); ?>
                    </option>

                    <?php if (Arr::get($this->config, 'termData', [])): ?>
                        <?php foreach (Arr::get($this->config, 'termData', []) as $term): ?>
                            <option value="<?php echo esc_attr(Arr::get($term, 'termId', '')); ?>">
                                <?php echo esc_html(Arr::get($term, 'termName', '')); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>

                </select>

                <small id="category-help" class="sr-only">
                    <?php esc_html_e('Filter search results by category', 'fluent-cart'); ?>
                </small>
            </div>

        <?php
    }

    public function renderInput(){
        ?>

            <div class="fluent-cart-search-bar-app-input-wrap">
                <label for="fluent-cart-search-input" class="sr-only">
                    <?php esc_html_e('Search Products', 'fluent-cart'); ?>
                </label>

                <div class="fluent-cart-search-bar-app-input-search">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 18 18" fill="none">
                        <path d="M13.583 13.583L17.333 17.333" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M15.666 8.1665C15.666 4.02437 12.3082 0.666504 8.16601 0.666504C4.02388 0.666504 0.666016 4.02437 0.666016 8.1665C0.666016 12.3086 4.02388 15.6665 8.16601 15.6665C12.3082 15.6665 15.666 12.3086 15.666 8.1665Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                    </svg>
                </div>

                <input
                    id="fluent-cart-search-input"
                    class="fluent-cart-search-bar-app-input"
                    data-fluent-cart-search-bar
                    type="text"
                    placeholder="<?php echo esc_attr(__('Search Products...', 'fluent-cart')) ?>"
                    aria-describedby="search-help"
                />
                
                <button
                        class="fluent-cart-search-bar-app-input-clear"
                        data-fluent-cart-search-clear
                        title="<?php echo esc_attr(__('Clear search', 'fluent-cart')); ?>"
                        type="button"
                        aria-label="<?php esc_attr_e('Clear search', 'fluent-cart'); ?>"
                        aria-controls="fluent-cart-search-input"
                >
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 18 18" fill="none">
                        <path d="M11.4995 11.5L6.5 6.5M6.50053 11.5L11.5 6.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M17.3337 8.99967C17.3337 4.3973 13.6027 0.66634 9.00033 0.66634C4.39795 0.66634 0.666992 4.3973 0.666992 8.99967C0.666992 13.602 4.39795 17.333 9.00033 17.333C13.6027 17.333 17.3337 13.602 17.3337 8.99967Z" stroke="currentColor" stroke-width="1.2"/>
                    </svg>
                </button>

                <small id="search-help" class="sr-only">
                    <?php esc_html_e('Type to search products', 'fluent-cart'); ?>
                </small>
            </div>

        <?php
    }

    public function renderSearchResult() {
        ?>

            <div class="fluent-cart-search-bar-app-wrapper-result-wrap">
                 <h5 id="search-suggestions-heading"><?php esc_html_e('Suggestions', 'fluent-cart'); ?></h5>

                 <ul
                    id="search-suggestions-list"
                    class="fluent-cart-search-bar-app-list-wrapper" 
                    data-fluent-cart-search-bar-lists-wrapper
                    role="list"
                    aria-labelledby="search-suggestions-heading"
                >
                    <?php $this->renderResultItems(); ?>
                </ul>
            </div>

        <?php
    }
    
    public function renderResultItems($products = [])
    {
        $pageTarget = (Arr::get($this->config, 'url_mode', '') == 'new-tab') ? 'target="_blank"' : '';

        foreach ($products as $product) {
            $productTitle = Arr::get($product, 'post_title', '');
            $productUrl = Arr::get($product, 'guid', '');
            $aria_label = sprintf(
                    /* translators: 1: Product title */
                    esc_attr__('View product: %1$s', 'fluent-cart'),
                    esc_html($productTitle)
            );
            ?>
                <li data-fluent-cart-search-bar-lists-list-item style="z-index: 9999;" role="listitem">
                    <a
                        href="<?php echo esc_url($productUrl) ?>"
                        <?php echo esc_html($pageTarget); ?>
                        aria-label="<?php echo esc_attr($aria_label); ?>"
                    >
                        <?php echo esc_html($productTitle); ?>
                    </a>
                </li>
            <?php
        }
    }

}
