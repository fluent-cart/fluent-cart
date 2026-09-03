<?php

namespace FluentCart\App\Modules\Templating\Bricks;

use Bricks\Frontend;
use FluentCart\Framework\Support\Arr;

class BricksHelper
{

    static public $forcedPost = null;

    public static function getFormCurrentPost()
    {
        return self::$forcedPost;
    }

    public static function setFormCurrentPost($post)
    {
        self::$forcedPost = $post;
    }

    public static function getCategoriesOptions()
    {
        $categories = get_terms(array(
            'taxonomy'   => 'product-categories',
            'hide_empty' => false,
            'orderby'    => 'name'
        ));

        $options = [];
        if (!is_wp_error($categories) && !empty($categories)) {
            foreach ($categories as $category) {
                $options[$category->term_id] = $category->name;
            }
        }

        return $options;
    }

    public static function renderCollectionCard($settings, $post, $post_index = 1, $uid = '')
    {
        $content = Frontend::get_content_wrapper($settings, Arr::get($settings, 'fields', []), $post);

        if ($post_index === 1) {
            echo "<div data-fluent-client-id='" . esc_attr($uid) . "' data-template-provider='bricks' data-fct-product-card class='fct-product-card repeater-item'>";
        } else {
            echo "<div data-fct-product-card class='fct-product-card repeater-item'>";
        }

        $linkedProduct = isset($settings['linkProduct']) ? $settings['linkProduct'] : false;

        if ($linkedProduct && strpos($content, '<a ') === false) {
            echo '<a href="' . esc_attr(get_the_permalink($post)) . '">';
        }

        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if ($linkedProduct && strpos($content, '<a ') === false) {
            echo '</a>';
        }

        echo '</div>';
    }

    public static function isTemplate()
    {
         $is_template = false;

        if (isset($_GET['bricks']) && $_GET['bricks'] === 'run') {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $is_template = strpos($request_uri, '/template/') !== false;
        }

        return $is_template;
    }

    public static function getAllowedHtmlForContent()
    {
        $allowed_html = wp_kses_allowed_html('post');
        $allowed_html['iframe'] = [
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'frameborder'     => true,
            'allow'           => true,
            'allowfullscreen' => true,
            'title'           => true,
            'referrerpolicy'  => true,
        ];
        return $allowed_html;
    }
}
