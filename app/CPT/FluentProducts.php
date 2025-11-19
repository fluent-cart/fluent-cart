<?php

namespace FluentCart\App\CPT;


use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Services\URL;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class FluentProducts
{

    const CPT_NAME = 'fluent-products';

    protected $showStandaloneMenu = false;

    public function register()
    {
        add_filter( 'get_edit_post_link', function ($link, $postId) {
            if($this->showStandaloneMenu) {
                return $link;
            }

            $post = get_post($postId);
            if ($post && $post->post_type === 'fluent-products') {
                return URL::getDashboardUrl('products/' . $postId );
            }

            return $link;
        }, 99, 2);


        add_action('init', function () {

            $this->showStandaloneMenu = apply_filters('fluent_cart/show_standalone_product_menu', false);

            $this->registerPostType();
            $this->registerProductTaxonomies();
        });
        add_action('admin_enqueue_scripts', function () {
            if ($this->showStandaloneMenu) {
                return;
            }
            $screen = get_current_screen();
            $isProductEditingScreen = $screen && $screen->post_type === 'fluent-products' && $screen->base === 'post';
            if (!$isProductEditingScreen) {
                if ($screen && $screen->post_type === 'fluent-products' && ($screen->base == 'edit-tags' || $screen->base == 'term')) {
                    $this->customizeTaxonomyScreen();
                }
                return;
            }

            // Make sure the default admin styles are enqueued
            wp_enqueue_style('wp-admin');

            $custom_css =
                '#editor .editor-sidebar__panel .editor-post-summary .editor-post-trash,' .
                '#wpadminbar,' .
                '#adminmenu,' .
                '#adminmenuback,' .
                '#adminmenuwrap {' .
                'display: none !important;' .
                '}' .
                '#wpbody-content .interface-interface-skeleton {' .
                'left: 0 !important;' .
                'top: 0 !important;' .
                '}' .
                '#wpcontent, #wpfooter {' .
                'margin-left: 0 !important;' .
                'margin-right: 0 !important;' .
                '}';

            wp_add_inline_style('wp-admin', $custom_css);

            wp_register_script('fluent-products-inline-js', '', [], FLUENTCART_VERSION, true);
            wp_enqueue_script('fluent-products-inline-js');

            $custom_js = "window.addEventListener('click', function (event) {
                    const anchor = event.target.closest('a');
                    if (anchor) {
                        event.preventDefault();
                        let href = anchor.getAttribute('href');
                        if (href && href != '#' && !href.startsWith('javascript:')) {
                            window.open(href, '_blank');
                        }
                    }
                });";

            //  wp_add_inline_script('fluent-products-inline-js', $custom_js);

        });

//        add_action('elementor/editor/after_enqueue_scripts', function () {
//            $this->registerElementorScript();
//        });

        add_action('enqueue_block_editor_assets', function () {
            global $post;

            // Only apply to book post type
            if (!isset($post->post_type) || 'fluent-products' !== $post->post_type) {
                return;
            }


            wp_add_inline_script(
                    'wp-blocks',
                    "
        wp.domReady( function() {
            // Force fullscreen mode
            wp.data.dispatch( 'core/edit-post' ).toggleFeature( 'fullscreenMode', true );
            
            // Hide the fullscreen toggle button
            const style = document.createElement( 'style' );
            style.textContent = '.edit-post-fullscreen-mode-close, .components-button[aria-label=\"Exit fullscreen\"], .edit-post-fullscreen-mode-close ~ .edit-post-header-toolbar__left button[aria-label=\"Exit fullscreen\"] { display: none !important; }';
            document.head.appendChild( style );
        } );
        "
            );
        });

        add_action('update_post_meta', [$this, 'handleThumbChange'], 10, 4);
        add_action('added_post_meta', [$this, 'handleThumbChange'], 10, 4);
    }

    public function registerElementorScript()
    {
        $custom_js = "console.log('doc ready - editor context');

            // Wait for Elementor editor to be ready
            jQuery(window).on('elementor:init', () => {
                console.log('Elementor editor initialized');

                jQuery(document).on('click', '#elementor-editor-wrapper-v2 .MuiButtonGroup-root > button', function() {
                    console.log('Button clicked!', this);

                    let content = '';

                    try {
                        const previewFrame = jQuery('#elementor-preview-iframe')[0];
                        if (previewFrame && previewFrame.contentDocument) {
                            const previewContent = previewFrame.contentDocument.querySelector('.elementor');
                            if (previewContent) {
                                content = previewContent.outerHTML;
                                console.log('Content from preview frame:', content.substring(0, 200) + '...');
                            }
                        }
                    } catch (e) {
                        console.log('Could not access preview frame:', e);
                    }

                    // Fallback: Get structured data
                    if (!content && typeof elementor !== 'undefined') {
                        try {
                            content = JSON.stringify(elementor.documents.currentDocument.container.children.toJSON());
                        } catch (e) {
                            console.log('Could not get elementor data:', e);
                        }
                    }

                    // Send message to parent window
                    window.parent.postMessage(
                        {
                            type: 'gutenbergContentChanged',
                            content: content,
                            timestamp: Date.now()
                        },
                        '*'
                    );
                });
            });";

        wp_add_inline_script('elementor-editor', $custom_js);
    }

    public function registerPostType()
    {
        $productSlug = (new StoreSettings())->get('product_slug') ?? 'item';
        $urlSlug = apply_filters('fluent_cart/front_url_slug', $productSlug, []);

        $singularName = __('Product', 'fluent-cart');

        if (defined('WC_PLUGIN_FILE')) {
            $singularName = __('Product (FluentCart)', 'fluent-cart');
        }

        register_post_type(self::CPT_NAME, [
                'capability_type'       => 'post',
//            'capabilities'          => [
//                'edit_post'          => 'edit_your_post_type',
//                'read_post'          => 'read_your_post_type',
//                'delete_post'        => 'do_not_allow', // block trash/delete
//                'edit_posts'         => 'edit_your_post_types',
//                'edit_others_posts'  => 'edit_others_your_post_types',
//                'publish_posts'      => 'publish_your_post_types',
//                'read_private_posts' => 'read_private_your_post_types',
//            ],
//            'map_meta_cap'          => true,
                'label'                 => __('Products', 'fluent-cart'),
                'labels'                => [
                        'name'          => __('Products', 'fluent-cart'),
                        'singular_name' => $singularName,
                        'add_new'       => _x('Add New Product', 'product', 'fluent-cart'),
                        'add_new_item'  => __('Add New Product', 'fluent-cart'),
                        'edit_item'     => __('Edit Product', 'fluent-cart'),
                        'view_item'     => __('View Product', 'fluent-cart'),
                        'search_items'  => __('Search products', 'fluent-cart'),
                ],
            //'_edit_link' => 'admin.php?page=fluent-cart#/products/%d/pricing',
                'description'           => __('FluentCart products post type', 'fluent-cart'),
                'public'                => true,
                'hierarchical'          => false,
                'exclude_from_search'   => false,
                'publicly_queryable'    => true,
                'show_ui'               => true,
                'show_in_menu'          => $this->showStandaloneMenu,
                'show_in_nav_menus'     => true,
                'show_in_admin_bar'     => true,
                'menu_position'         => 24,
                'has_archive'           => true,
                'show_in_rest'          => true,
//            'rest_base'             => $urlSlug, // Optional: customize REST API base
                'rest_controller_class' => 'WP_REST_Posts_Controller', // Optional: use default controller
                'supports'              => [
                        'title',
                        'editor',
                        'excerpt',
                        'thumbnail',
                        'author',
                        'revisions',
                        'custom-fields',
                ],
                'rewrite'               => [
                        'slug'       => $urlSlug,
                        'with_front' => true,
                        'feeds'      => true,
                        'pages'      => true,
                ],
                'query_var'             => $urlSlug,
                'can_export'            => true,
                'delete_with_user'      => false
        ]);

        $this->disableCreateAndEditForFluentProducts();
    }

    public function disableCreateAndEditForFluentProducts()
    {
        add_action('admin_init', function () {
            // return for ajax and rest api
            if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
                return;
            }
            global $pagenow;
            // disable direct create product
            $postType = App::request()->get('post_type');
            if ($pagenow == 'post-new.php' && $postType == self::CPT_NAME) {
                wp_redirect(admin_url('admin.php?page=fluent-cart#/products/?add-new=true'));
                exit;
            }
        });
    }

    public function registerProductTaxonomies()
    {
        register_taxonomy('product-categories', self::CPT_NAME, [
                'hierarchical'      => true,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'query_var'         => true,
                'rewrite'           => ['slug' => 'product-categories'],
                'labels'            => [
                        'name'              => __('Categories', 'fluent-cart'),
                        'singular_name'     => __('Category', 'fluent-cart'),
                        'search_items'      => __('Search Categories', 'fluent-cart'),
                        'all_items'         => __('All Categories', 'fluent-cart'),
                        'parent_item'       => __('Parent Category', 'fluent-cart'),
                        'parent_item_colon' => __('Parent Category:', 'fluent-cart'),
                        'edit_item'         => __('Edit Category', 'fluent-cart'),
                        'update_item'       => __('Update Category', 'fluent-cart'),
                        'add_new_item'      => __('Add New Category', 'fluent-cart'),
                        'new_item_name'     => __('New Category Name', 'fluent-cart'),
                        'menu_name'         => __('Product Category', 'fluent-cart'),
                        'not_found'         => __('No categories found.', 'fluent-cart'),
                ],
        ]);

        register_taxonomy('product-brands', self::CPT_NAME, [
                'hierarchical'      => true,
                'show_ui'           => true,
                'show_in_rest'      => true,
                'show_admin_column' => false,
                'query_var'         => true,
                'rewrite'           => ['slug' => 'product-brands'],
                'labels'            => [
                        'name'          => __('Brands', 'fluent-cart'),
                        'singular_name' => __('Brand', 'fluent-cart'),
                        'search_items'  => __('All Brand', 'fluent-cart'),
                        'all_items'     => __('All Brands', 'fluent-cart'),
                        'parent_item'   => __('Parent Brand', 'fluent-cart'),
                        'edit_item'     => __('Edit Brand', 'fluent-cart'),
                        'update_item'   => __('Update Brand', 'fluent-cart'),
                        'add_new_item'  => __('Add New Brand', 'fluent-cart'),
                        'new_item_name' => __('New Brand Name', 'fluent-cart'),
                        'menu_name'     => __('Product Brand', 'fluent-cart'),
                        'view_item'     => __('View Brand', 'fluent-cart'),
                        'not_found'     => __('No Brand found', 'fluent-cart'),
                ],
        ]);
    }

    public function customizeTaxonomyScreen()
    {

        Vite::enqueueScript('fluent_cart_admin_global_js',
                'admin/global.js',
        );

        Vite::enqueueStyle('fluent_cart_admin_app_css',
                'styles/tailwind/style.css',
        );

        Vite::enqueueStyle('fluent_cart_taxonomy_css',
                'styles/tailwind/taxonomy.scss',
        );

        add_action('in_admin_header', function () {
            ?>
            <div style="margin-left: -20px;" class="fc_taxonomy_menu" id="fct_admin_menu_holder">
                <?php do_action('fluent_cart/admin_menu'); ?>
            </div>
            <?php
        });
    }

    public function handleThumbChange($meta_id, $object_id, $meta_key, $_meta_value)
    {
        if ($meta_key !== '_thumbnail_id') {
            return;
        }


        $post = get_post($object_id);


        if ($post->post_type !== FluentProducts::CPT_NAME) {
            return;
        }


        //get post meta
        $oldGallery = get_post_meta($object_id, FluentProducts::CPT_NAME . '-gallery-image', true);

        if (!is_array($oldGallery)) {
            $oldGallery = [];
        }

        $currentThumbnailId = $_meta_value;


        //get thumbnail image with all info like title id and url
        $currentThumbnail = wp_prepare_attachment_for_js($currentThumbnailId);
        $currentThumbnail = [
                'id'   => $currentThumbnailId,
                'url'  => Arr::get($currentThumbnail, 'url'),
                'name' => Arr::get($currentThumbnail, 'title')
        ];

        $currentThumbnailId = Arr::get($currentThumbnail, 'id');


        $found = false;
        $index = 0;
        foreach ($oldGallery as $index => $image) {
            if ($image['id'] == $currentThumbnailId) {
                $found = true;
                break;
            }
        }


        //if found bring it on top
        if ($found) {
            unset($oldGallery[$index]);
            array_unshift($oldGallery, $image);
        } else {
            array_unshift($oldGallery, $currentThumbnail);
        }
        update_post_meta($object_id, FluentProducts::CPT_NAME . '-gallery-image', $oldGallery);
    }
}
