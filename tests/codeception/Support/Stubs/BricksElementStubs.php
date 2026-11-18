<?php
/**
 * The Bricks classes a FluentCart element needs to register its controls and render.
 * The test install never loads Bricks; nothing in the plugin detects Bricks by these names.
 */

namespace Bricks {
    // The card's markup (the Fields card) needs only the content wrapper; the rest of Bricks is not loaded here.
    if (!class_exists('Bricks\\Frontend', false)) {
        class Frontend
        {
            public static function get_content_wrapper($settings, $fields, $post)
            {
                return '';
            }
        }
    }
    if (!class_exists('Bricks\\Custom_Render_Element', false)) {
        class Custom_Render_Element
        {
            public $controls = [];
            public $control_groups = [];
            public $settings = [];

            public function end_iteration()
            {
            }

            // Bricks' card field repeater; elements only edit its fields and default.
            public function get_post_fields()
            {
                return ['fields' => ['type' => 'repeater', 'fields' => []]];
            }
        }
    }

    // The base of the single-product elements: settings, the current post and the root attributes.
    if (!class_exists('Bricks\\Element', false)) {
        class Element
        {
            public $controls = [];
            public $control_groups = [];
            public $settings = [];
            public $post_id = 0;
            public $tag = 'div';
            public $attributes = [];
            public $is_frontend = true;

            // Bricks' init() ends by calling render().
            public function init()
            {
                $this->render();
            }

            public function set_attribute($key, $attribute, $value)
            {
                $this->attributes[$key][$attribute] = $value;
            }

            public function render_attributes($key)
            {
                $out = '';
                foreach ((array) ($this->attributes[$key] ?? []) as $attribute => $value) {
                    $out .= ' ' . $attribute . '="' . esc_attr(implode(' ', (array) $value)) . '"';
                }
                return trim($out);
            }

            public function render_dynamic_data($content)
            {
                return $content;
            }
        }
    }

    if (!class_exists('Bricks\\Helpers', false)) {
        class Helpers
        {
            public static function generate_random_id($echo = true)
            {
                return substr(md5(uniqid('', true)), 0, 6);
            }

            public static function parse_editor_content($content)
            {
                return wpautop($content);
            }
        }
    }
}
