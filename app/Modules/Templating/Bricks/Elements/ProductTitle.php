<?php
namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class ProductTitle extends Element {
    public $category = 'fluent_cart_product';
    public $name     = 'fct-product-title';
    public $icon     = 'ti-text';
    public $tag      = 'h1';

    public function get_label() {
        return esc_html__( 'Product title (FluentCart)', 'fluent-cart' );
    }

    public function set_controls() {
        $this->controls['tag'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'HTML tag', 'fluent-cart' ),
            'type'        => 'select',
            'options'     => [
                'h1' => 'h1',
                'h2' => 'h2',
                'h3' => 'h3',
                'h4' => 'h4',
                'h5' => 'h5',
                'h6' => 'h6',
            ],
            'inline'      => true,
            'placeholder' => 'h1',
        ];

        $this->controls['prefix'] = [
            'tab'    => 'content',
            'label'  => esc_html__( 'Prefix', 'fluent-cart' ),
            'type'   => 'text',
            'inline' => true,
        ];

        $this->controls['prefixBlock'] = [
            'tab'      => 'content',
            'label'    => esc_html__( 'Prefix block', 'fluent-cart' ),
            'type'     => 'checkbox',
            'inline'   => true,
            'required' => [ 'prefix', '!=', '' ],
        ];

        $this->controls['suffix'] = [
            'tab'    => 'content',
            'label'  => esc_html__( 'Suffix', 'fluent-cart' ),
            'type'   => 'text',
            'inline' => true,
        ];

        $this->controls['suffixBlock'] = [
            'tab'      => 'content',
            'label'    => esc_html__( 'Suffix block', 'fluent-cart' ),
            'type'     => 'checkbox',
            'inline'   => true,
            'required' => [ 'suffix', '!=', '' ],
        ];

        $this->controls['linkToProduct'] = [
            'tab'   => 'content',
            'label' => esc_html__( 'Link to product', 'fluent-cart' ),
            'type'  => 'checkbox',
        ];
    }

    public function render() {
        $settings = $this->settings;


        $prefix          = ! empty( $settings['prefix'] ) ? $settings['prefix'] : false;
        $suffix          = ! empty( $settings['suffix'] ) ? $settings['suffix'] : false;
        $link_to_product = isset( $settings['linkToProduct'] );
        $output          = '';

        if ( $link_to_product ) {
            $output .= '<a href="' . get_the_permalink( $this->post_id ) . '">';
        }

        if ( $prefix ) {
            $this->set_attribute( 'prefix', 'class', [ 'post-prefix' ] );

            $output .= isset( $settings['prefixBlock'] ) ? "<div {$this->render_attributes( 'prefix' )}>{$prefix}</div>" : "<span {$this->render_attributes( 'prefix' )}>{$prefix}</span>";
        }

        $post = get_post( $this->post_id );

        $output .= $post->post_title;

        if ( $suffix ) {
            $this->set_attribute( 'suffix', 'class', [ 'post-suffix' ] );

            $output .= isset( $settings['suffixBlock'] ) ? "<div {$this->render_attributes( 'suffix' )}>{$suffix}</div>" : "<span {$this->render_attributes( 'suffix' )}>{$suffix}</span>";
        }

        if ( $link_to_product ) {
            $output .= '</a>';
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping, output is escaped
        echo "<" . esc_html($this->tag) . " {$this->render_attributes( '_root' )}>" . wp_kses_post($output) . "</" . esc_html($this->tag) . ">";
    }

    public static function render_builder() { ?>
        <script type="text/x-template" id="tmpl-brxe-product-title">
            <component :is="tag" class="product-title">
                <div v-if="settings.prefix && settings.prefixBlock" class="post-prefix" v-html="settings.prefix"></div>
                <span v-else-if="settings.prefix && !settings.prefixBlock" class="post-prefix" v-html="settings.prefix"></span>

                <span v-html="bricks.wp.post.title"></span>

                <div v-if="settings.suffix && settings.suffixBlock" class="post-suffix" v-html="settings.suffix"></div>
                <span v-else-if="settings.suffix && !settings.suffixBlock" class="post-suffix" v-html="settings.suffix"></span>
            </component>
        </script>
        <?php
    }
}
