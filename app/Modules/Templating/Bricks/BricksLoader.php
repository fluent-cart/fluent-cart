<?php

namespace FluentCart\App\Modules\Templating\Bricks;

use Bricks\Elements;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;

class BricksLoader
{
    const TEMPLATE_TYPE_PRODUCT = 'fct_product';

    public function register()
    {
        if (!defined('BRICKS_VERSION')) {
            return;
        }

        add_action('init', [$this, 'loadElements'], 20);

        (new DynamicData())->register();

        add_filter('bricks/setup/control_options', [$this, 'addTemplateTypes']);
        add_filter('bricks/database/content_type', [$this, 'setContentType'], 10, 2);
        add_filter('bricks/active_templates', [$this, 'setActiveTemplates'], 10, 3);

        add_action('wp_footer', [$this, 'addDynamicFieldClassesScript']);

        add_filter('fluent_cart/template/disable_taxonomy_fallback', function ($result) {
            $bricks_data = \Bricks\Database::get_template_data('archive');

            if ($bricks_data) {
                return true;
            }

            return $result;
        });

        add_filter('fluent_cart/products_views/preload_collection_bricks', [$this, 'preloadProductCollectionsAjax'], 10, 2);

    }

    public function loadElements()
    {
        $elements = [
            'ProductTitle'            => 'fct-product-title',
            'ProductShortDescription' => 'fct-product-short-description',
            'ProductContent'          => 'fct-product-content',
            'ProductStock'            => 'fct-product-stock',
            'PriceRange'              => 'fct-price-range',
            'BuySection'              => 'fct-product-buy-section',
            'ProductGallery'          => 'fct-product-gallery',
            'ProductsCollection'      => 'fct-products',
        ];

        foreach ($elements as $elementKey => $elementName) {
            $elementFile = FLUENTCART_PLUGIN_PATH . "app/Modules/Templating/Bricks/Elements/$elementKey.php";
            $className = "\\FluentCart\\App\\Modules\\Templating\\Bricks\\Elements\\$elementKey";
            Elements::register_element($elementFile, $elementName, $className);
        }

    }

    /**
     * Add FluentCart template types to the Bricks template type dropdown.
     */
    public function addTemplateTypes($controlOptions)
    {
        if (!isset($controlOptions['templateTypes']) || !is_array($controlOptions['templateTypes'])) {
            return $controlOptions;
        }

        $templateTypes = $controlOptions['templateTypes'];

        if (isset($templateTypes[self::TEMPLATE_TYPE_PRODUCT])) {
            return $controlOptions;
        }

        $fluentCartType = [
            self::TEMPLATE_TYPE_PRODUCT => __('FluentCart - Product', 'fluent-cart'),
        ];

        if (array_key_exists('content', $templateTypes)) {
            $keys = array_keys($templateTypes);
            $offset = array_search('content', $keys, true) + 1;

            $templateTypes = array_slice($templateTypes, 0, $offset, true)
                + $fluentCartType
                + array_slice($templateTypes, $offset, null, true);
        } else {
            $templateTypes = array_merge($templateTypes, $fluentCartType);
        }

        $controlOptions['templateTypes'] = $templateTypes;

        return $controlOptions;
    }

    /**
     * Let Bricks resolve FluentCart single product templates as product templates.
     */
    public function setContentType($contentType, $postId)
    {
        if (is_search()) {
            return $contentType;
        }

        if (is_singular('fluent-products')) {
            return self::TEMPLATE_TYPE_PRODUCT;
        }

        return $contentType;
    }

    /**
     * Make the FluentCart product template type render on single product pages.
     */
    public function setActiveTemplates($activeTemplates, $postId, $contentType)
    {
        if ($contentType !== self::TEMPLATE_TYPE_PRODUCT || !is_singular('fluent-products')) {
            return $activeTemplates;
        }

        if (!empty($activeTemplates[self::TEMPLATE_TYPE_PRODUCT])) {
            $activeTemplates['content'] = $activeTemplates[self::TEMPLATE_TYPE_PRODUCT];
            return $activeTemplates;
        }

        $templateId = $this->getProductTemplateId($postId);

        if (!$templateId) {
            return $activeTemplates;
        }

        $activeTemplates['content'] = $templateId;
        $activeTemplates[self::TEMPLATE_TYPE_PRODUCT] = $templateId;

        return $activeTemplates;
    }

    protected function getProductTemplateId($postId)
    {
        if (!class_exists('\Bricks\Templates')) {
            return 0;
        }

        $templateIds = \Bricks\Templates::get_templates_by_type(self::TEMPLATE_TYPE_PRODUCT);

        if (empty($templateIds) || !is_array($templateIds)) {
            return 0;
        }

        $foundTemplates = [];
        $defaultTemplateId = 0;

        foreach ($templateIds as $templateId) {
            $conditions = \Bricks\Helpers::get_template_setting('templateConditions', $templateId);

            if (!$conditions) {
                if (!$defaultTemplateId) {
                    $defaultTemplateId = absint($templateId);
                }
                continue;
            }

            $foundTemplates = \Bricks\Database::screen_conditions($foundTemplates, $templateId, $conditions, $postId, '');
        }

        if (!empty($foundTemplates)) {
            $scores = array_keys($foundTemplates);
            return $foundTemplates[max($scores)];
        }

        return $defaultTemplateId;
    }

    public function preloadProductCollectionsAjax($view, $args)
    {
        $products = $args['products'];
        $clientId = Arr::get($args, 'client_id', '');

        $settings = get_transient('fc_bx_collection_' . $clientId);
        if (!$settings) {
            return $view;
        }

        do_action('fluent_cart/bricks/rendering_ajax_collection');

        ob_start();
        $post_index = 1;

        foreach ($products as $product) {
            $post = get_post($product->ID);
            setup_postdata($post);
            BricksHelper::setFormCurrentPost($post);
            BricksHelper::renderCollectionCard($settings, $product, $post_index, $clientId);

            $post_index++;
        }
        wp_reset_postdata();

        return ob_get_clean();
    }

    public function addDynamicFieldClassesScript()
    {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const classMap = {
                'product-title': 'fct-dynamic-product-title',
                'product-image': 'fct-dynamic-product-image',
                'product-excerpt': 'fct-dynamic-product-excerpt',
                'product-price': 'fct-dynamic-product-price',
                'product-button': 'fct-dynamic-product-button'
            };

            const applyDynamicFieldClass = function(marker) {
                if (marker.hasAttribute('data-fct-field-class-applied')) {
                    return;
                }

                const className = classMap[marker.getAttribute('data-fct-field')];

                if (!className) {
                    marker.setAttribute('data-fct-field-class-applied', '1');
                    return;
                }

                let dynamicDiv = marker.closest('.dynamic');

                if (!dynamicDiv) {
                    dynamicDiv = marker.parentElement;
                    while (dynamicDiv && !dynamicDiv.classList.contains('dynamic')) {
                        dynamicDiv = dynamicDiv.parentElement;
                    }
                }

                if (dynamicDiv) {
                    dynamicDiv.classList.add(className);
                }

                marker.setAttribute('data-fct-field-class-applied', '1');
            };

            let scheduled = false;
            const pendingNodes = new Set();

            const processNode = function(node) {
                if (!node || node.nodeType !== 1) {
                    return;
                }

                const markers = node.matches('[data-fct-field]')
                    ? [node]
                    : node.querySelectorAll('[data-fct-field]');

                markers.forEach(applyDynamicFieldClass);
            };

            const scheduleNode = function(node) {
                pendingNodes.add(node);

                if (scheduled) {
                    return;
                }

                scheduled = true;
                requestAnimationFrame(function() {
                    pendingNodes.forEach(processNode);
                    pendingNodes.clear();
                    scheduled = false;
                });
            };

            document.querySelectorAll('[data-fluent-cart-shop-app-product-list]').forEach(function(productList) {
                processNode(productList);

                new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        mutation.addedNodes.forEach(scheduleNode);
                    });
                }).observe(productList, {
                    childList: true,
                    subtree: true
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get product options for select controls.
     * Limits to 50 recent products for editor performance.
     * Users can search or use manual product ID for others.
     */
    public static function getProductOptions()
    {
        if (!class_exists('\FluentCart\App\Models\Product')) {
            return [];
        }

        $options = [];

        try {
            $products = Product::query()
                ->where('post_status', 'publish')
                ->orderBy('post_date', 'DESC')
                ->limit(50)
                ->get();

            foreach ($products as $product) {
                $options[$product->ID] = $product->post_title;
            }
        } catch (\Exception $e) {
            // Silently fail if models aren't available
        }

        return $options;
    }
}
