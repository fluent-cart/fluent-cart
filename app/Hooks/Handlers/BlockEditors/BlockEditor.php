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
            static::$isReactSupportAdded = false;
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

        register_block_type($this->slugPrefix . '/' . static::getEditorName(), [
            'api_version'      => 3,
            'version'          => 3,
            'editor_script'    => $this->getScriptName(),
            'editor_css'       => $this->getStyleName(),
            'render_callback'  => [$this, 'render_block'],
            'provides_context' => $this->provideContext(),
            'uses_context'     => $this->useContext(),
            'supports'         => $this->supports()
        ]);

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

    public function render_block($attributes, $content, $block)
    {
        $attributes = Arr::wrap($attributes);
        return $this->render($attributes, $block, $content);
    }


    protected function getStyles(): array
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
