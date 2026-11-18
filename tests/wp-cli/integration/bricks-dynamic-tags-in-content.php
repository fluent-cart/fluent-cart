<?php
/**
 * Bricks {fct_product_*} dynamic tags inside other content (FluentCart board 23259).
 *
 * DynamicData::renderValue() renders one tag. It was also attached to
 * bricks/dynamic_data/render_content and bricks/frontend/render_data, which
 * pass the whole field or page content, so "Title: {fct_product_title}" or a
 * Rich Text "<p>{fct_product_title}</p>" came back with the raw tag.
 */

use FluentCart\App\Modules\Templating\Bricks\DynamicData;

return [
    [
        'id'            => 'bricks-dynamic-tags-in-content',
        'name'          => 'Bricks product tags render inside surrounding content, alone, and as a single tag',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $dynamicData = new DynamicData();
            $hooks = [
                'bricks/dynamic_tags_list',
                'bricks/dynamic_data/render_tag',
                'bricks/dynamic_data/render_content',
                'bricks/frontend/render_data',
            ];
            $before = [];
            foreach ($hooks as $hook) {
                $before[$hook] = isset($GLOBALS['wp_filter'][$hook]) ? clone $GLOBALS['wp_filter'][$hook] : null;
            }
            $dynamicData->register();

            $product = new \WP_Post((object) [
                'ID'           => 0,
                'post_type'    => 'fluent-products',
                'post_title'   => 'Bricks Tag Shoe',
                'post_excerpt' => 'Short excerpt',
                'post_status'  => 'publish',
                'filter'       => 'raw',
            ]);
            $page = new \WP_Post((object) [
                'ID'         => 0,
                'post_type'  => 'page',
                'post_title' => 'Not a product',
                'filter'     => 'raw',
            ]);
            $title = '<span data-fct-field="product-title">Bricks Tag Shoe</span>';
            $excerpt = '<span data-fct-field="product-excerpt">Short excerpt</span>';

            try {
                FcTest::assertSame(
                    'Title: ' . $title,
                    apply_filters('bricks/dynamic_data/render_content', 'Title: {fct_product_title}', $product, 'text'),
                    'render_content renders a tag that follows other text'
                );
                FcTest::assertSame(
                    '<p>' . $title . ' | ' . $excerpt . '</p>',
                    apply_filters('bricks/dynamic_data/render_content', '<p>{fct_product_title} | {fct_product_excerpt}</p>', $product, 'text'),
                    'render_content renders every tag inside a Rich Text paragraph'
                );
                FcTest::assertSame(
                    '<div>Title: ' . $title . '</div>',
                    apply_filters('bricks/frontend/render_data', '<div>Title: {fct_product_title}</div>', $product, 'content'),
                    'render_data renders a tag inside page markup'
                );

                $linked = apply_filters('bricks/dynamic_data/render_content', 'Title: {fct_product_title:linked}', $product, 'text');
                FcTest::assert(
                    strpos($linked, 'Title: <a href=') === 0
                        && strpos($linked, 'data-fct-field="product-title">Bricks Tag Shoe</a>') !== false,
                    'render_content keeps the :linked argument of a tag inside content'
                );

                FcTest::assertSame(
                    $title,
                    apply_filters('bricks/dynamic_data/render_content', '{fct_product_title}', $product, 'text'),
                    'render_content still renders a tag that is the whole field'
                );
                FcTest::assertSame(
                    $title,
                    apply_filters('bricks/dynamic_data/render_tag', 'fct_product_title', $product, 'text'),
                    'render_tag still renders a single tag'
                );
                FcTest::assertSame(
                    'Keep {fct_product_unknown} as is',
                    apply_filters('bricks/dynamic_data/render_content', 'Keep {fct_product_unknown} as is', $product, 'text'),
                    'an unknown fct_product tag stays raw'
                );
                FcTest::assertSame(
                    'Title: {fct_product_title}',
                    apply_filters('bricks/dynamic_data/render_content', 'Title: {fct_product_title}', $page, 'text'),
                    'content of a non-product post is left unchanged'
                );
            } finally {
                foreach ($before as $hook => $saved) {
                    if ($saved === null) {
                        unset($GLOBALS['wp_filter'][$hook]);
                    } else {
                        $GLOBALS['wp_filter'][$hook] = $saved;
                    }
                }
            }
        },
    ],
];
