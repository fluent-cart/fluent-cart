<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Framework\Support\Arr;

class ProductCategoriesListRenderer
{
    /**
     * Convert string/boolean to actual boolean value
     * Handles shortcode string attributes like "true"/"false"
     */
    private function toBool($value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['false', '0', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    public static function getCategories(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product-categories',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $categories = [];
        foreach ($terms as $term) {
            $categories[] = [
                'term_id'  => $term->term_id,
                'name'     => $term->name,
                'slug'     => $term->slug,
                'count'    => $term->count,
                'parent'   => $term->parent,
                'link'     => get_term_link($term),
            ];
        }

        return $categories;
    }

    public function render(array $atts = []): void
    {
        $defaults = [
            'is_shortcode' => false,
        ];

        $atts = wp_parse_args($atts, $defaults);

        $showHierarchy  = $this->toBool(Arr::get($atts, 'show_hierarchy'), true);
        $showEmpty      = $this->toBool(Arr::get($atts, 'show_empty'), false);
        $displayStyle   = Arr::get($atts, 'display_style', 'list');
        $isShortcode    = $this->toBool(Arr::get($atts, 'is_shortcode'), false);

        $wrapperAttributes = '';
        if (!$isShortcode) {
            $wrapperAttributes = get_block_wrapper_attributes();
        }

        $args = [
            'taxonomy'   => 'product-categories',
            'hide_empty' => !$showEmpty,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];

        if ($showHierarchy) {
            $args['parent'] = 0;
        }

        $categories = get_terms($args);

        if (is_wp_error($categories) || empty($categories)) {
            $this->renderEmpty($wrapperAttributes);
            return;
        }

        $dropdownClasses = $displayStyle === 'dropdown' ? ' fct-product-categories-list--dropdown' : '';

        ?>
        <div <?php echo $wrapperAttributes; ?>>
            <div class="fct-product-categories-list <?php echo esc_attr($dropdownClasses); ?>">
                <?php if ($displayStyle === 'dropdown') : ?>
                    <?php $this->renderDropdown($categories, $atts); ?>
                <?php else : ?>
                    <?php $this->renderList($categories, $atts); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function renderButton() {
        ?>

            <button
                type="button"
                class="fct-categories-go-btn"
                data-fct-categories-go-btn
                aria-label="<?php esc_attr_e('Go', 'fluent-cart'); ?>"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
            </button>
        <?php
    }

    public function renderDropdown(array $categories, array $atts): void
    {

        ?>
            <div class="fct-categories-dropdown-wrap" data-fct-categories-dropdown-wrap>
                <select class="fct-categories-dropdown" data-fct-categories-dropdown>
                    <option value="">
                        <?php echo esc_html__('Select a category', 'fluent-cart'); ?>
                    </option>

                    <?php $this->renderDropdownOptions($categories, $atts, 0); ?>
                </select>

                <?php $this->renderButton(); ?>
            </div>

            <?php
    }

    public function renderDropdownOptions(array $categories, array $atts, int $depth = 0): void
    {
        $showCount     = $this->toBool(Arr::get($atts, 'show_product_count'), true);
        $showHierarchy = $this->toBool(Arr::get($atts, 'show_hierarchy'), true);
        $showEmpty     = $this->toBool(Arr::get($atts, 'show_empty'), false);

        foreach ($categories as $category) {
            $indent = str_repeat('— ', $depth);
            $label  = $indent . esc_html($category->name);

            if ($showCount) {
                $label .= ' (' . $category->count . ')';
            }

            $link = get_term_link($category);

            if (!is_wp_error($link)) {
                echo '<option value="' . esc_url($link) . '">' . $label . '</option>';
            }

            // Render children if hierarchy is enabled
            if ($showHierarchy) {
                $children = get_terms([
                    'taxonomy'   => 'product-categories',
                    'hide_empty' => !$showEmpty,
                    'parent'     => $category->term_id,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                ]);

                if (!is_wp_error($children) && !empty($children)) {
                    $this->renderDropdownOptions($children, $atts, $depth + 1);
                }
            }
        }
    }


    public function renderList(array $categories, array $atts) {
        ?>
            <ul class="fct-categories-list">
                <?php $this->renderListItems($categories, $atts); ?>
            </ul>
        <?php
    }

    public function renderChildrenList(array $children, array $atts, int $depth = 0) {
        ?>
            <ul class="fct-categories-children">
                <?php $this->renderListItems($children, $atts, $depth + 1);?>
            </ul>
        <?php
    }



    public function renderListItems(array $categories, array $atts, int $depth = 0): void
    {
        foreach ($categories as $category) {
            $this->renderCategoryItem($category, $atts, $depth);
        }
    }

    public function renderCategoryItem($category, $atts, $depth = 0) {
        $classes = 'fct-category-item fct-category-item--depth-' . $depth;
        $showCount     = $this->toBool(Arr::get($atts, 'show_product_count'), true);
        $showHierarchy = $this->toBool(Arr::get($atts, 'show_hierarchy'), true);
        $showEmpty     = $this->toBool(Arr::get($atts, 'show_empty'), false);

        $link = get_term_link($category);
        if (is_wp_error($link)) {
            return;
        }

        ?>
            <li class="<?php echo esc_attr($classes); ?>">
                <span class="fct-category-link-wrap">
                    <a href="<?php echo esc_url($link); ?>" class="fct-category-link">
                         <?php echo esc_html($category->name); ?>
                    </a>

                    <?php if ($showCount) : ?>
                        <span class="fct-category-count">
                            &#40;<?php echo esc_html($category->count); ?>&#41;
                        </span>
                    <?php endif; ?>
                </span>

                <?php
                    if ($showHierarchy) {
                        $children = get_terms([
                                'taxonomy'   => 'product-categories',
                                'hide_empty' => !$showEmpty,
                                'parent'     => $category->term_id,
                                'orderby'    => 'name',
                                'order'      => 'ASC',
                        ]);

                        if (!is_wp_error($children) && !empty($children)) {
                            $this->renderChildrenList($children, $atts, $depth);
                        }
                    }
                ?>
            </li>
        <?php
    }

    public function renderEmpty(string $wrapperAttributes = '')
    {
        ?>
            <div <?php echo $wrapperAttributes; ?>>
                <div class="fct-product-categories-list fct-product-categories-list--empty">
                    <p><?php echo esc_html__('No categories found.', 'fluent-cart'); ?></p>
                </div>
            </div>
        <?php
    }
}
