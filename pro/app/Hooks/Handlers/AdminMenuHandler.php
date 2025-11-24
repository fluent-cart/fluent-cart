<?php

namespace FluentCartPro\App\Hooks\Handlers;

use FluentCartPro\App\Core\App;
use FluentCartPro\App\Utils\Enqueuer\Enqueue;

class AdminMenuHandler
{
    /**
     * Add Custom Menu
     * 
     * @return null
     */
    public function add()
    {
        add_filter('fluent_cart/admin_menu_title', function ($title) {
            return $title . ' Pro ';
        }, 10, 2);

        $this->enqueueAssets(
            $app = App::getInstance(),
            $slug = $app->config->get('app.slug')
        );
    }


    /**
     * Render the menu page
     * 
     * @return null
     */
    public function render()
    {

        $this->enqueueAssets(
            $app = App::getInstance(),
            $slug = $app->config->get('app.slug')
        );

    }

    /**
     * Enqueue all the scripts and styles
     * @param  WPFluent\Foundation\Application $app
     * @param  string $slug
     * @return null
     */
    public function enqueueAssets($app, $slug)
    {
        $app->doAction($slug . '_loading_app');

//        Enqueue::style($slug . '_admin_app', 'scss/admin.scss');
//        Enqueue::script(
//            $slug . '_admin_app',
//            'admin/app.js',
//            ['jquery'],
//            '1.0',
//            true
//        );
//        Enqueue::script(
//            $slug . '_global_admin',
//            'admin/global_admin.js',
//            [],
//            '1.0',
//            true
//        );

//        $this->localizeScript($app, $slug);
    }

    /**
     * Push/Localize the JavaScript variables
     * to the browser using wp_localize_script.
     * 
     * @param  WPFluent\Foundation\Application $app
     * @param  string $slug
     * @return null
     */
    protected function localizeScript($app, $slug)
    {
        $currentUser = get_user_by('ID', get_current_user_id());

        wp_localize_script($slug . '_admin_app', 'fluentCartProAdminApp', [
            'slug'  => $slug,
            'nonce' => wp_create_nonce($slug),
            'user_locale' => get_locale(),
            'rest'  => $this->getRestInfo($app),
            'brand_logo' => $this->getMenuIcon(),
            'asset_url' => $app['url.assets'],
        ]);
    }

    /**
     * Gether rest info/settings for http client.
     * 
     * @param  WPFluent\Foundation\Application $app
     * @return array
     */
    protected function getRestInfo($app)
    {
        $ns = $app->config->get('app.rest_namespace');
        $ver = $app->config->get('app.rest_version');

        return [
            'base_url'  => esc_url_raw(rest_url()),
            'url'       => rest_url($ns . '/' . $ver),
            'nonce'     => wp_create_nonce('wp_rest'),
            'namespace' => $ns,
            'version'   => $ver
        ];
    }

    /**
     * Get the default icon for custom menu
     * added by the add_menu in the WP menubar.
     * 
     * @return string
     */
    protected function getMenuIcon()
    {
        return 'dashicons-wordpress-alt';
    }

    /**
     * Makes the class invokable.
     * 
     * @return null
     */
    public function __invoke()
    {
        $this->add();
    }
}

