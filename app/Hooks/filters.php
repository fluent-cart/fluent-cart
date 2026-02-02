<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Foundation\Application;

use FluentCart\App\Services\Payments\SubscriptionHelper;
use FluentCart\Framework\Support\Arr;

/**
 * All registered filter's handlers should be in app\Hooks\Handlers,
 * addFilter is similar to add_filter and addCustomFlter is just a
 * wrapper over add_filter which will add a prefix to the hook name
 * using the plugin slug to make it unique in all wordpress plugins,
 * ex: $app->addCustomFilter('foo', ['FooHandler', 'handleFoo']) is
 * equivalent to add_filter('slug-foo', ['FooHandler', 'handleFoo']).
 */

/**
 *
 * @var Application $app
 */


add_filter('block_categories_all', function ($categories) {
    $categories[] = array(
        'slug'  => 'fluent-cart',
        'title' => __('FluentCart', 'fluent-cart'),
    );

    $categories[] = array(
        'slug'  => 'fluent-cart-buttons',
        'title' => __('FluentCart Buttons', 'fluent-cart'),
    );

    return $categories;
});

add_filter('fluent_cart/dummy_product_info', function ($info) {
    $infos = [
        'mens-shoes' => [
            'title'    => __("Men’s Shoes", 'fluent-cart'),
            'count'    => "0",
            'category' => 'mens-shoes',
            'icon'     => 'RunningShoe'
        ],

        'menswear' => [
            'title'    => __("Menswear", 'fluent-cart'),
            'count'    => "0",
            'category' => 'menswear',
            'icon'     => 'Cloth'
        ],
//        'clothing' => [
//            'title' => __("Clothing's", 'fluent-cart'),
//            'count' => "0",
//            'category' => 'clothing'
//        ],
//        'food' => [
//            'title' => __("Food", 'fluent-cart'),
//            'count' => "0",
//            'category' => 'food'
//        ],
//        'electronics' => [
//            'title' => __('Electronics', 'fluent-cart'),
//            'count' => "0",
//            'category' => 'electronics'
//        ]
    ];

    foreach ($infos as $key => $info) {
        $filePath = FLUENTCART_PLUGIN_PATH . 'dummies' . DIRECTORY_SEPARATOR . $key . '.json';
        if (file_exists($filePath)) {
            try {
                $json = file_get_contents($filePath);
                $products = json_decode($json, true);
                $infos[$key]['count'] = count($products);
            } catch (\Exception $exception) {

            }
        }

    }

    return $infos;
});