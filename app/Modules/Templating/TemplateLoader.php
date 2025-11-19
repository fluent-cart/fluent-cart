<?php

namespace FluentCart\App\Modules\Templating;

use FluentCart\Api\StoreSettings;
//use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\AddToCartShortcode;
use FluentCart\App\Hooks\Handlers\ShortCodes\SingleProductShortCode;
use FluentCart\App\Modules\Templating\BlockTemplates\ProductCategoryTemplate;
use FluentCart\App\Modules\Templating\BlockTemplates\ProductPageTemplate;

class TemplateLoader
{

    private static $supportedTheme = false;

    public static $currentRenderingPageType = '';

    public static $currentTaxonomy = null;

    public static function init()
    {
        static $loaded;
        if ($loaded) {
            return;
        }

        $loaded = true;

        self::$supportedTheme = self::hasThemeSupport();

        if (self::$supportedTheme) {
            add_filter('template_include', [self::class, 'loadTemplate'], 10);
        } else {
            add_action('template_redirect', array(__CLASS__, 'initUnsupportedTheme'), 1);
        }

        // Handling Post Type Archive redirection or simulation to the shop page
        add_action('template_redirect', function () {
            if (is_post_type_archive('fluent-products')) {
                global $wp;
                // we will redirect to shop page if its set
                $storeSettings = new StoreSettings();
                $shopPage = trim($storeSettings->getShopPage(), '/');
                $requestUrl = trim(home_url($wp->request), '/');

                if ($shopPage && $shopPage !== $requestUrl) {
                    wp_redirect($shopPage, 301);
                    exit;
                }
            }
        }, 1);

        add_action('fluent_cart/render_products_archive', [__CLASS__, 'renderProductsArchive']);

        add_filter('fluent_cart/shop_app_product_query_taxonomy_filters', function ($taxFilters, $args) {

            return $taxFilters;

            $currentTaxonomy = TemplateLoader::$currentTaxonomy;
            if ($currentTaxonomy && $args['is_main_query']) {
                unset($taxFilters[$currentTaxonomy->taxonomy]);
                $taxFilters[$currentTaxonomy->taxonomy] = [$currentTaxonomy->term_id];
            }

            return $taxFilters;
        }, 10, 2);

        (new TemplateActions())->register();

        AssetLoader::register();

    }

    public static function registerBlockParts()
    {
        if (!self::supportsBlockTemplates('wp_template')) {
            return;
        }

        (new ProductCategoryTemplate())->init();
        (new ProductPageTemplate())->init();

    }

    public static function loadTemplate($template)
    {
        if (is_embed()) {
            return $template;
        }

        $default_file = self::getDefaultTemplateFile();

        if (!$default_file) {
            return $template;
        }

        $search_files = self::getTemplateLoaderFiles($default_file);

        $locatedFile = locate_template($search_files);

        if ($locatedFile) {
            return $locatedFile;
        }

        return $template;
    }

    public static function initUnsupportedTheme()
    {
        $isTaxPages = is_tax(get_object_taxonomies('fluent-products'));

        if ($isTaxPages) {
            if (apply_filters('fluent_cart/template/disable_taxonomy_fallback', false)) {
                return;
            }

            add_filter('template_include', array(__CLASS__, 'loadGenericFallbackTemplate'), 9999);
        } else if (is_singular('fluent-products')) {
            (new TemplateActions())->initSingleProductHooks();
        }

    }

    private static function simulateProductsArchive()
    {

    }

    public static function loadGenericFallbackTemplate($template)
    {
        return __DIR__ . '/fallback-generic-template.php';
    }

    public static function renderProductsArchive($args = [])
    {
        echo do_shortcode('[fluent_cart_products]');
    }

    public static function hasThemeSupport()
    {
        return (bool)current_theme_supports('fluent_cart') || wp_is_block_theme();
    }

    private static function getDefaultTemplateFile()
    {
        if (is_singular('fluent-products') && !self::hasBlockTemplate('single-fluent-products')
        ) {
            $default_file = 'single-fluent-products.php';
        } elseif (is_tax(get_object_taxonomies('fluent-products'))) {
            $object = get_queried_object();
            self::$currentRenderingPageType = $object->taxonomy;
            self::$currentTaxonomy = $object;

            if (self::taxonomyHasBlockTemplate($object)) {
                $default_file = '';
            } elseif (is_tax('product-categories') || is_tax('product-brands')) {
                $default_file = 'taxonomy-' . $object->taxonomy . '.php';
            } elseif (!self::hasBlockTemplate('archive-fluent-products')) {
                $default_file = 'archive-fluent-products.php';
            } else {
                $default_file = '';
            }
        } else {
            $default_file = '';
        }

        return $default_file;
    }

    private static function hasBlockTemplate($template_name)
    {
        if (!$template_name) {
            return false;
        }

        $has_template = false;
        $template_filename = $template_name . '.html';

        $filepath = DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $template_filename;
        $possible_paths = array(
            get_stylesheet_directory() . $filepath,
            get_template_directory() . $filepath,
        );

        // Check the first matching one.
        foreach ($possible_paths as $path) {
            if (is_readable($path)) {
                $has_template = true;
                break;
            }
        }

        return (bool)apply_filters('fluent_cart/has_block_template', $has_template, [
            'template_name' => $template_name
        ]);
    }

    private static function taxonomyHasBlockTemplate($taxonomy): bool
    {
        $template_name = 'taxonomy-' . $taxonomy->taxonomy;
        return self::hasBlockTemplate($template_name);
    }

    private static function getTemplateLoaderFiles($defaultFile)
    {
        $templates = apply_filters('fluent_cart/template_loader_files', array(), [
            'default_file' => $defaultFile,
        ]);

        $templates[] = 'fluent-cart.php';

        if (is_page_template()) {
            $page_template = get_page_template_slug();
            if ($page_template) {
                $validated_file = validate_file($page_template);
                if (0 === $validated_file) {
                    $templates[] = $page_template;
                }
            }
        }

        if (is_singular('fluent-products')) {
            $object = get_queried_object();
            $name_decoded = urldecode($object->post_name);
            if ($name_decoded !== $object->post_name) {
                $templates[] = "single-fluent-products-{$name_decoded}.php";
            }
            $templates[] = "single-fluent-products-{$object->post_name}.php";
        }

        if (is_tax(get_object_taxonomies('fluent-products'))) {
            $object = get_queried_object();
            $templates[] = 'taxonomy-' . $object->taxonomy . '-' . $object->slug . '.php';
            $templates[] = self::templatePath() . 'taxonomy-' . $object->taxonomy . '-' . $object->slug . '.php';
            $templates[] = 'taxonomy-' . $object->taxonomy . '.php';
            $templates[] = self::templatePath() . 'taxonomy-' . $object->taxonomy . '.php';
        }

        $templates[] = $defaultFile;

        $templates[] = self::templatePath() . $defaultFile;

        return array_unique($templates);
    }

    public static function templatePath()
    {
        return apply_filters('fluent_cart/template_path', 'fluent-cart/');
    }

    public static function supportsBlockTemplates($templateType = 'wp_template')
    {

        return ($templateType === 'wp_template_part' &&
                (wp_is_block_theme() || current_theme_supports('block-template-parts'))
            )
            || ($templateType == 'wp_template' && wp_is_block_theme());
    }
}
