<?php
namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Element;
use FluentCart\App\Modules\Templating\Bricks\BricksLoader;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class ProductTitle extends Element {
    use \FluentCart\App\Modules\Templating\Bricks\MovingToBricksAddonNotice;

    public $category = 'fluent-cart';
    public $name     = 'fct-product-title';
    public $icon     = 'ti-text fluent-cart-element-icon';
    public $tag      = 'h1';

    public function get_label() {
        return esc_html__( 'Product Title', 'fluent-cart' );
    }

    public function set_controls() {
        // The product, as the other single-product elements pick it.
        $this->controls['queryType'] = [
            'tab'     => 'content',
            'type'    => 'select',
            'label'   => esc_html__( 'Query Type', 'fluent-cart' ),
            'options' => [
                'default' => esc_html__( 'Default', 'fluent-cart' ),
                'custom'  => esc_html__( 'Custom', 'fluent-cart' ),
            ],
            'default' => 'default',
            'inline'  => true,
        ];

        $this->controls['productId'] = [
            'tab'         => 'content',
            'type'        => 'select',
            'label'       => esc_html__( 'Product', 'fluent-cart' ),
            'options'     => BricksLoader::getProductOptions(),
            'placeholder' => esc_html__( 'Select a product', 'fluent-cart' ),
            'searchable'  => true,
            'rerender'    => true,
            'required'    => [ 'queryType', '=', 'custom' ],
        ];

        $this->controls['manualProductId'] = [
            'tab'         => 'content',
            'type'        => 'text',
            'label'       => esc_html__( 'Manual Product ID', 'fluent-cart' ),
            'description' => esc_html__( 'Use this if the product is not available in dropdown.', 'fluent-cart' ),
            'required'    => [ [ 'queryType', '=', 'custom' ], [ 'productId', '=', '' ] ],
        ];

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
        $postId = $this->post_id;
        if (($settings['queryType'] ?? 'default') === 'custom') {
            $postId = !empty($settings['productId']) ? \intval($settings['productId']) : \intval($settings['manualProductId'] ?? 0);
        }
        $post = get_post($postId);
        if (!$post) {
            return;
        }

        $prefix = !empty($settings['prefix']) ? $settings['prefix'] : false;
        $suffix = !empty($settings['suffix']) ? $settings['suffix'] : false;
        $linkToProduct = isset($settings['linkToProduct']);

        ?>
        <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping internally
            echo "<" . esc_html($this->tag) . " " . $this->render_attributes('_root') . ">";
        ?>
            <?php if ($linkToProduct) : ?>
                <a href="<?php echo esc_url(get_the_permalink($post)); ?>">
            <?php endif; ?>

            <?php if ($prefix) : ?>
                <?php $this->set_attribute('prefix', 'class', ['post-prefix']); ?>
                <?php if (isset($settings['prefixBlock'])) : ?>
                    <div <?php echo $this->render_attributes('prefix'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks render_attributes() escapes each value ?>>
                        <?php echo wp_kses_post($prefix); ?>
                    </div>
                <?php else : ?>
                    <span <?php echo $this->render_attributes('prefix'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks render_attributes() escapes each value ?>>
                        <?php echo wp_kses_post($prefix); ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>

            <span><?php echo esc_html($post->post_title); ?></span>

            <?php if ($suffix) : ?>
                <?php $this->set_attribute('suffix', 'class', ['post-suffix']); ?>
                <?php if (isset($settings['suffixBlock'])) : ?>
                    <div <?php echo $this->render_attributes('suffix'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks render_attributes() escapes each value ?>>
                        <?php echo wp_kses_post($suffix); ?>
                    </div>
                <?php else : ?>
                    <span <?php echo $this->render_attributes('suffix'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks render_attributes() escapes each value ?>>
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
