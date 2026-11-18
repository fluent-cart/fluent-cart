<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\App;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\App\Services\TermProductsService;
use FluentCart\App\Vite;

/**
 * Product picker on the Product Categories and Brands term screens.
 */
class TermProductsHandler
{
    const TAXONOMIES = ['product-categories', 'product-brands'];

    const PRELOAD_LIMIT = 200;

    public function register(): void
    {
        foreach (self::TAXONOMIES as $taxonomy) {
            add_action($taxonomy . '_add_form_fields', [$this, 'renderAddField'], 10, 1);
            add_action($taxonomy . '_edit_form_fields', [$this, 'renderEditField'], 10, 2);
        }

        add_action('created_term', [$this, 'save'], 10, 3);
        add_action('edited_term', [$this, 'save'], 10, 3);

        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function renderAddField($taxonomy): void
    {
        if (!PermissionManager::hasPermission(['products/edit'])) {
            return;
        }
        ?>
        <div class="form-field term-fct-products-wrap">
            <label for="fct-term-products"><?php esc_html_e('Products', 'fluent-cart'); ?></label>
            <?php $this->renderMount(['products' => [], 'total' => 0]); ?>
        </div>
        <?php
    }

    public function renderEditField($term, $taxonomy): void
    {
        if (!PermissionManager::hasPermission(['products/edit'])) {
            return;
        }

        $current = TermProductsService::getProducts((int) $term->term_id, $taxonomy, self::PRELOAD_LIMIT);
        $hidden = $current['total'] - count($current['products']);
        if ($hidden > 0) {
            $current['more_text'] = sprintf(
                /* translators: %d: number of products not shown in the picker */
                _n('and %d more product', 'and %d more products', $hidden, 'fluent-cart'),
                $hidden
            );
            // Same link as the Count column.
            $current['more_url'] = admin_url('edit.php?' . $taxonomy . '=' . $term->slug . '&post_type=' . FluentProducts::CPT_NAME);
        }
        ?>
        <tr class="form-field term-fct-products-wrap">
            <th scope="row"><label for="fct-term-products"><?php esc_html_e('Products', 'fluent-cart'); ?></label></th>
            <td><?php $this->renderMount($current); ?></td>
        </tr>
        <?php
    }

    public function save($termId, $ttId, $taxonomy): void
    {
        if (!in_array($taxonomy, self::TAXONOMIES, true)) {
            return;
        }

        $request = App::request();
        $add = $request->get('fct_products_add', '');
        $remove = $request->get('fct_products_remove', '');
        if ($add === '' && $remove === '') {
            return;
        }

        TermProductsService::sync((int) $termId, $taxonomy, wp_parse_id_list($add), wp_parse_id_list($remove));
    }

    public function enqueueAssets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->base, ['edit-tags', 'term'], true) || !in_array($screen->taxonomy, self::TAXONOMIES, true)) {
            return;
        }

        if (!PermissionManager::hasPermission(['products/edit'])) {
            return;
        }

        Vite::enqueueScript('fluent_cart_term_products', 'admin/term-products.js', [], FLUENTCART_VERSION, true)
            ->with([
                'fluentCartRestVars' => ['rest' => Helper::getRestInfo()],
            ]);
    }

    private function renderMount(array $config): void
    {
        $config['wp_rest_url'] = untrailingslashit(rest_url('wp/v2'));
        $config['strings'] = [
            'placeholder' => __('Search products', 'fluent-cart'),
            'help'        => __('Pick the products to attach to this term.', 'fluent-cart'),
            'no_results'  => __('No products found', 'fluent-cart'),
            'load_error'  => __('Could not load products.', 'fluent-cart'),
        ];
        ?>
        <div class="fct-term-products" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>"></div>
        <?php
    }
}
