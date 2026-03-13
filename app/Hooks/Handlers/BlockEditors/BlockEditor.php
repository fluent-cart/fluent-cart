<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\Api\Contracts\CanEnqueue;
use FluentCart\App\App;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

abstract class BlockEditor
{
    use CanEnqueue;

    const PARENT_BLOCK_DATA_NAME = 'fluent_cart_parent_block_data';

    protected static string $editorName;
    private static bool $isReactSupportAdded = false;
    private static bool $blockSupportStylesEnqueued = false;

    public function __construct()
    {
        $this->slugPrefix = App::config()->get('app.slug');
    }

    private function addReactSupport()
    {
        if (!static::$isReactSupportAdded) {
            if (Vite::underDevelopment()) {
                Vite::enqueueScript(
                    'react-support',
                    'admin/BlockEditor/ReactSupport.js',
                    ['wp-blocks', 'wp-components']
                );
            }
            static::$isReactSupportAdded = true;
        }
    }

    abstract public function render(array $shortCodeAttribute, $block = null);

    protected function generateEnqueueSlug(): string
    {
        return Str::of(
            $this->slugPrefix . '_' . static::getEditorName()
        )->snake('')->replace('-', '_')->toString();
    }

    public static function getEditorName(): string
    {
        return static::$editorName;
    }

    public static function register()
    {
        add_action('init', [static::make(), 'init']);
    }

    private function enqueueAsset()
    {
        $this->addReactSupport();
        $this->enqueueScripts();
        $this->enqueueStyles();
        $this->enqueueGlobalStyles();
    }

    private function enqueueGlobalStyles()
    {
        Vite::enqueueStyle(
            'fluent-cart-global-block-editor',
            'admin/BlockEditor/Components/style/fct-global-block-editor.scss',
        );
    }

    public function init(): void
    {
        add_action('enqueue_block_editor_assets', function () {
            $this->enqueueAsset(); // Enqueue block editor-specific JS/CSS here
        });
        add_action('enqueue_block_assets', function () {
            if($this->isBlockEditor()) {
                $this->enqueueStyles();
            }
        });

        $blockArgs = [
            'api_version'      => 3,
            'version'          => 3,
            'category'         => 'fluent-cart',
            'editor_script'    => $this->getScriptName(),
            'editor_css'       => $this->getStyleName(),
            'render_callback'  => [$this, 'render_block'],
            'provides_context' => $this->provideContext(),
            'uses_context'     => $this->useContext(),
            'supports'         => $this->supports()
        ];

        $attributes = $this->blockAttributes();
        if (!empty($attributes)) {
            $blockArgs['attributes'] = $attributes;
        }

        if ($this->skipInnerBlocks()) {
            $blockArgs['skip_inner_blocks'] = true;
        }

        register_block_type($this->slugPrefix . '/' . static::getEditorName(), $blockArgs);

    }

    public function blockAttributes(): array
    {
        return [];
    }

    /**
     * Register just the block type (render callback, attributes, supports)
     * without editor scripts/styles. Used for email-only blocks that handle
     * asset enqueuing through the email editor handler instead of globally.
     */
    public function registerBlockType(): void
    {
        $blockArgs = [
            'api_version'     => 3,
            'category'        => 'fluent-cart',
            'render_callback' => [$this, 'render_block'],
            'supports'        => $this->supports(),
        ];

        $attributes = $this->blockAttributes();
        if (!empty($attributes)) {
            $blockArgs['attributes'] = $attributes;
        }

        register_block_type($this->slugPrefix . '/' . static::getEditorName(), $blockArgs);
    }

    public function supports(): array
    {
        return [
            'renaming'    => false,
            'innerBlocks' => true,
            'align'       => true,
        ];
    }

    public function provideContext()
    {
        return null;
    }

    public function useContext()
    {
        return null;
    }

    /**
     * Whether to skip automatic inner block rendering in WP_Block::render().
     *
     * Override to return true in blocks that manually render their inner blocks
     * in the render callback. This prevents WordPress from auto-rendering inner
     * blocks before the callback runs (which causes double rendering and can
     * trigger WP 6.x's empty-block script dequeue mechanism).
     */
    protected function skipInnerBlocks(): bool
    {
        return false;
    }

    public function render_block($attributes, $content, $block)
    {
        $prefix = '';
        if (!self::$blockSupportStylesEnqueued && !is_admin()) {
            $prefix = self::getBlockSupportFallbackStyles();
            self::$blockSupportStylesEnqueued = true;
        }

        $attributes = Arr::wrap($attributes);
        return $prefix . $this->render($attributes, $block, $content);
    }

    /**
     * Ensure WordPress global styles (preset colors, typography, spacing) are available
     * on the frontend. Some themes (e.g., Bricks) strip the global-styles stylesheet,
     * which breaks block support classes like .has-vivid-red-color.
     *
     * This outputs a minimal inline fallback only if global styles aren't already loaded.
     */
    private static function getBlockSupportFallbackStyles(): string
    {
        // Skip if global styles are already loaded by the theme
        if (wp_style_is('global-styles', 'done') || wp_style_is('global-styles', 'enqueued')) {
            return '';
        }

        // Use WordPress API to get preset variables and class rules
        if (function_exists('wp_get_global_stylesheet')) {
            $css = wp_get_global_stylesheet(['variables', 'presets']);
            if (!empty($css)) {
                return '<style id="fluent-cart-block-supports-css">' . wp_strip_all_tags($css) . '</style>';
            }
        }

        return '';
    }


    public function getStyles(): array
    {
        return [];
    }


    public static function make(): BlockEditor
    {
        return new static();
    }

    protected function isBlockEditor(): bool
    {
        if (!function_exists('get_current_screen')) {
            require_once ABSPATH . '/wp-admin/includes/screen.php';
        }
        $current_screen = \get_current_screen();

        // Check for regular block editor (posts/pages)
        if ($current_screen instanceof \WP_Screen && $current_screen->is_block_editor()) {
            return true;
        }

        // Check for site editor (theme templates)
        $path = wp_unslash(App::request()->get('path'));
        if (is_admin() && isset($path) && strpos($path, '/wp_template') !== false) {
            return true;
        }

        return false;
    }
}
