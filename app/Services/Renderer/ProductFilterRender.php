<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\StoreSettings;
use FluentCart\Api\Taxonomy;
use FluentCart\App\App;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ProductFilterRender
{
    protected $product;
    protected $filters = [];

    protected $currency = 'USD';

    protected $urlFilters = [];

    protected bool $shouldLoadRangePlugin = false;

    public function __construct($config = [])
    {
        $this->buildFilters($config);
        $this->currency = (new StoreSettings())->getCurrency();
    }

    public function render()
    {
        ?>
        <form class="fct-shop-filter-form" data-fluent-cart-product-filter-form role="search"
              aria-label="<?php esc_attr_e('Product filter form', 'fluent-cart'); ?>">
            <?php $this->renderSearch(); ?>
            <?php $this->renderOptions(); ?>
        </form>
        <?php
    }

    public function renderSearch()
    {
        ?>
        <div class="fct-shop-product-search" role="searchbox">
            <label for="fct-shop-search-input" class="sr-only">
                <?php esc_html_e('Search products', 'fluent-cart'); ?>
            </label>

            <div class="fct-search-icon">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 18 18"
                     fill="none">
                    <path d="M13.583 13.583L17.333 17.333" stroke="currentColor" stroke-width="1.2"
                          stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M15.666 8.1665C15.666 4.02437 12.3082 0.666504 8.16601 0.666504C4.02388 0.666504 0.666016 4.02437 0.666016 8.1665C0.666016 12.3086 4.02388 15.6665 8.16601 15.6665C12.3082 15.6665 15.666 12.3086 15.666 8.1665Z"
                          stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                </svg>
            </div>

            <input
                    id="fct-shop-search-input"
                    class="fct-shop-input"
                    data-fluent-cart-search-bar
                    type="text"
                    name="wildcard"
                    placeholder="<?php echo esc_attr__('Search Products...', 'fluent-cart') ?>"
                    aria-label="<?php esc_attr_e('Search products', 'fluent-cart'); ?>"
            />

            <div
                    class="fct-search-clear hide"
                    data-fluent-cart-search-clear
                    title="<?php echo esc_attr__('Clear search', 'fluent-cart'); ?>"
                    type="button"
                    aria-label="<?php esc_attr_e('Clear search', 'fluent-cart'); ?>"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 18 18" fill="none">
                    <path d="M11.4995 11.5L6.5 6.5M6.50053 11.5L11.5 6.5" stroke="currentColor" stroke-width="1.2"
                          stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M17.3337 8.99967C17.3337 4.3973 13.6027 0.66634 9.00033 0.66634C4.39795 0.66634 0.666992 4.3973 0.666992 8.99967C0.666992 13.602 4.39795 17.333 9.00033 17.333C13.6027 17.333 17.3337 13.602 17.3337 8.99967Z"
                          stroke="currentColor" stroke-width="1.2"/>
                </svg>
            </div>
        </div>
        <?php
    }

    public function renderOptions()
    {
        foreach ($this->filters as $key => $filter): ?>

            <?php if ($filter['filter_type'] === 'options' && $filter['options']): ?>
                <div class="fct-shop-filter-item">
                    <span class="sr-only"><?php echo esc_html($filter['label']); ?></span>

                    <div
                            class="fct-shop-item-collapse-wrap"
                            data-fluent-cart-shop-app-filter-form-item-collapse
                            role="button"
                            aria-expanded="false"
                            aria-controls="filter-<?php echo esc_attr($key); ?>"
                            tabindex="0"
                    >
                        <h3 class="item-heading"><?php echo esc_html($filter['label']); ?></h3>

                        <button type="button" class="toggle-icon"
                                aria-label="<?php echo esc_attr__('Toggle filter', 'fluent-cart'); ?>">
                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="12" height="7"
                                 viewBox="0 0 12 7" fill="none">
                                <path d="M11 1.5L6.70711 5.79289C6.37377 6.12623 6.20711 6.29289 6 6.29289C5.79289 6.29289 5.62623 6.12623 5.29289 5.79289L1 1.5"
                                      stroke="#565865" stroke-width="1.25" stroke-linecap="round"
                                      stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>

                    <div id="filter-<?php echo esc_attr($key); ?>" class="fct-shop-checkbox-group">
                        <?php
                        foreach ($filter['options'] as $option) :
                            $option['parent_key'] = $key;
                            ?>
                            <?php if (empty($option['children'])) : ?>
                            <?php $this->renderCheckbox($option); ?>
                        <?php else : ?>
                            <div class="fct-shop-checkbox-child-group"
                                 data-fluent-cart-shop-app-filter-checkbox-child-group>
                                <?php $this->renderCheckbox($option); ?>

                                <div class="fct-shop-checkbox-child-options">
                                    <?php foreach ($option['children'] as $childOption) :
                                        $childOption['parent_key'] = $key;
                                        ?>
                                        <?php $this->renderCheckbox($childOption); ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php endforeach; ?>

                    </div>
                </div>
            <?php endif ?>

            <?php if ($filter['filter_type'] === 'range') : ?>
                <?php $this->renderPriceRange($filter); ?>
            <?php endif; ?>

        <?php endforeach;


    }

    public function renderCheckbox($option)
    {

        $key = Arr::get($option, 'parent_key', '');
        $checkboxId = 'fct-filter-' . esc_attr($key . '-' . $option['value']);
        ?>
        <label class="fct-shop-checkbox">
            <input
                    id="<?php echo esc_attr($checkboxId); ?>"
                    type="checkbox"
                    name="<?php echo esc_attr($key); ?>"
                    value="<?php echo esc_attr($option['value']) ?>"
                <?php echo !empty($option['children']) ? 'data-parent-checkbox' : '' ?>
            >
            <span class="checkmark" aria-hidden="true"></span>

            <span><?php echo esc_html($option['label']) ?></span>

            <?php if (isset($option['text'])): ?>
                <span><?php echo esc_html($option['text']) ?></span>
            <?php endif; ?>

        </label>
        <?php

    }

    public function renderPriceRange($filter = [])
    {

        $minPrice = ProductVariation::query()->min('item_price');
        $maxPrice = ProductVariation::query()->max('item_price');

        $currencySign = (new CurrenciesHelper())->getCurrencySign($this->currency);

        ?>
        <div class="fct-shop-filter-item">
            <span class="sr-only"><?php echo esc_html($filter['label']); ?></span>

            <div class="fct-shop-item-collapse-wrap">
                <h3 class="item-heading"><?php echo esc_html($filter['label']); ?></h3>
            </div>

            <div class="fct-shop-price-range-container" data-filter-type="range" data-filter-name="price_range">
                <div class="fct-shop-price-range-wrap">
                    <div class="fct-shop-price-range">
                        <label for="price-range-from" class="sr-only">
                            <?php esc_html_e('Minimum price', 'fluent-cart'); ?>
                        </label>

                        <div class="fct-shop-currency-sign" aria-hidden="true">
                            <?php echo esc_html($currencySign); ?>
                        </div>

                        <input
                                id="price-range-from"
                                class="fc_price_range_input"
                                type="text"
                                value="<?php echo esc_attr($minPrice / 100); ?>"
                                name="price_range_from"
                                placeholder="<?php echo esc_html__('e.g 100', 'fluent-cart'); ?>"
                                data-range-slider-from-value
                                data-value="<?php echo esc_attr($minPrice / 100); ?>"
                                aria-describedby="price-range-help"
                        >
                    </div>

                    <div class="fct-shop-price-range">
                        <label for="price-range-to" class="sr-only">
                            <?php esc_html_e('Maximum price', 'fluent-cart'); ?>
                        </label>

                        <div class="fct-shop-currency-sign" aria-hidden="true">
                            <?php echo esc_html($currencySign); ?>
                        </div>

                        <input
                                id="price-range-to"
                                class="fc_price_range_input"
                                type="text"
                                value="<?php echo esc_attr($maxPrice / 100); ?>"
                                name="price_range_to"
                                placeholder="<?php echo esc_html__('e.g 500', 'fluent-cart'); ?>"
                                data-range-slider-to-value
                                data-value="<?php echo esc_attr($maxPrice / 100); ?>"
                                aria-describedby="price-range-help"
                        >
                    </div>

                </div>
                <div class="fct-shop-range-slider" data-range-slider-wrapper></div>
                <small id="price-range-help" class="sr-only">
                    <?php
                    /* translators: %s is the currency symbol (e.g., $, €, £) */
                    printf(esc_html__('Price range in %s', 'fluent-cart'), esc_html($currencySign));
                    ?>
                </small>
            </div>
        </div>
        <?php
    }

    public function buildFilters($config)
    {
        $formattedFilters = [];
        $this->urlFilters = Arr::get(App::request()->all(), 'filters', []);

        // Sort by filter_type with "options" first
        uasort($config, function ($a, $b) {
            if (Arr::get($a, 'filter_type') === Arr::get($b, 'filter_type')) {
                return 0;
            }
            if (Arr::get($a, 'filter_type') === 'options') {
                return -1;
            }
            if (Arr::get($b, 'filter_type') === 'options') {
                return 1;
            }
            return strcmp(Arr::get($a, 'filter_type'), Arr::get($b, 'filter_type'));
        });

        foreach ($config ?? [] as $key => $val) {
            //Filter Out The filters are disabled
            $enabled = Arr::get($val, 'enabled', false);

            $isEnabled = in_array($enabled, [true, '1', 'true'], true);

            if (!$isEnabled) {
                continue;
            }

            $formattedFilters[$key]['label'] = Arr::get($val, 'label', ucfirst($key));
            $formattedFilters[$key]['filter_type'] = Arr::get($val, 'filter_type', '');

            if (is_array($val) && Arr::get($val, 'enabled', false) !== false && Arr::get($val, 'is_meta', false) !== false) {
                $prefilled = Arr::get($this->urlFilters, $key);
                if (!empty(Arr::get($val, 'options'))) {
                    $formattedFilters[$key]['options'] = Arr::get($val, 'options');
                } else {
                    $formattedFilters[$key]['options'] = $this->getMetaFilterOptions($key, $prefilled);
                }
            }
            if ($formattedFilters[$key]['filter_type'] === 'range') {
                $this->shouldLoadRangePlugin = true;
                $minValue = Helper::toDecimalWithoutComma(ProductDetail::query()->min('min_price'));
                $maxValue = Helper::toDecimalWithoutComma(ProductDetail::query()->max('max_price'));

                $minFromUrl = Arr::get($this->urlFilters, $key . '_from', 0);
                $maxFromUrl = Arr::get($this->urlFilters, $key . '_to', $maxValue);

                $formattedFilters[$key]['min_value'] = ($minFromUrl < $minValue) ? $minValue : (min($minFromUrl, $maxValue));
                $formattedFilters[$key]['max_value'] = ($maxFromUrl < 0) ? 0 : (min($maxFromUrl, $maxValue));

                $formattedFilters[$key]['min'] = $minValue;
                $formattedFilters[$key]['max'] = $maxValue;
            }
        }

        $this->filters = $formattedFilters;
    }

    private function getMetaFilterOptions($key, $prefilled = []): array
    {
        return Taxonomy::getFormattedTerms($key, false, null, 'value', 'label', $prefilled);
    }

    public static function renderResponsiveFilter()
    {
        ?>
        <div data-fluent-cart-shop-app-responsive-filter-wrapper class="fct-shop-responsive-filter-wrapper">
            <div data-fluent-cart-shop-app-responsive-filter-container class="fct-shop-responsive-filter-container">
                <div class="fct-shop-responsive-filter-header">
                    <h3><?php echo esc_html__('Filters', 'fluent-cart'); ?></h3>
                    <button
                            data-fluent-cart-shop-app-responsive-filter-close-button
                            class="fct-shop-responsive-filter-close-button"
                            type="button"
                            aria-label="<?php esc_attr_e('Close filter menu', 'fluent-cart'); ?>"
                    >
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                             width="14" height="14" viewBox="0 0 14 14" fill="none">
                            <path d="M12.8337 1.1665L1.16699 12.8332M1.16699 1.1665L12.8337 12.8332"
                                  stroke="currentColor"
                                  stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

}
