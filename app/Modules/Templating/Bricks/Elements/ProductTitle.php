<?php
namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class ProductTitle extends Element {
    public $category = 'fluent-cart';
    public $name     = 'fct-product-title';
    public $icon     = 'ti-text fluent-cart-element-icon';
    public $tag      = 'h1';

    public function get_label() {
        return esc_html__( 'Product Title', 'fluent-cart' );
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

    public function render()
    {
        $settings = $this->settings;
        $post = get_post($this->post_id);

        $prefix = !empty($settings['prefix']) ? $settings['prefix'] : false;
        $suffix = !empty($settings['suffix']) ? $settings['suffix'] : false;
        $linkToProduct = isset($settings['linkToProduct']);

        ?>
        <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping internally
            echo "<" . esc_html($this->tag) . " " . $this->render_attributes('_root') . ">";
        ?>
            <?php if ($linkToProduct) : ?>
                <a href="<?php echo esc_url(get_the_permalink($this->post_id)); ?>">
            <?php endif; ?>

            <?php if ($prefix) : ?>
                <?php $this->set_attribute('prefix', 'class', ['post-prefix']); ?>
                <?php if (isset($settings['prefixBlock'])) : ?>
                    <div <?php echo $this->render_attributes('prefix'); ?>>
                        <?php echo wp_kses_post($prefix); ?>
                    </div>
                <?php else : ?>
                    <span <?php echo $this->render_attributes('prefix'); ?>>
                        <?php echo wp_kses_post($prefix); ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>

            <span><?php echo esc_html($post->post_title); ?></span>

            <?php if ($suffix) : ?>
                <?php $this->set_attribute('suffix', 'class', ['post-suffix']); ?>
                <?php if (isset($settings['suffixBlock'])) : ?>
                    <div <?php echo $this->render_attributes('suffix'); ?>>
                        <?php echo wp_kses_post($suffix); ?>
                    </div>
                <?php else : ?>
                    <span <?php echo $this->render_attributes('suffix'); ?>>
                        <?php echo wp_kses_post($suffix); ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($linkToProduct) : ?>
                </a>
            <?php endif; ?>
        <?php echo "</" . esc_html($this->tag) . ">"; ?>
        <?php
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
