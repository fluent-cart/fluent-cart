<?php

namespace FluentCart\App\Services;

use FluentCart\App\App;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Vite;

class FrontendView
{
    public static function make($title, $content = '', $params = [])
    {
        $params['title'] = $title;
        $params['content'] = $content;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in view template
        echo App::view()->make('frontend/index.php')->with($params);
    }

    public static function makeForBlock($title, $view)
    {
        $blocks = parse_blocks($view);

        $content = '';
        foreach ($blocks as $block) {
            $renderedBlock = render_block($block);
            $content .= do_shortcode($renderedBlock);
        }

        echo wp_kses_post(
            App::view()->make('frontend/index.php')->with([
                'title'   => esc_html($title),
                'content' => $content,
            ])
        );
    }

    //    public static function loadView(string $path, $data = [])
//    {
//        return App::view()->make('frontend/' . $path)->with($data);
//    }

    public static function loadView(string $pageTitle, array $data, string $filePath = '')
    {
        $view = App::view()->make('frontend/' . $filePath)->with($data);

        return self::make($pageTitle, $view, $data);
    }


    public static function enqueueNotFoundPageAssets(): void
    {
        Vite::enqueueStyle(
            'fluent-cart-not-found-style',
            'public/not-found.css',
        );
    }

    public static function renderNotFoundPage($pageTitle = null, $title = null, $text = null, $buttonText = null, $notFoundImg = null, $buttonUrl = null)
    {
        $pageTitle = $pageTitle ?? __('404 - Page not found', 'fluent-cart');
        $title = $title ?? __('404 - Page not found', 'fluent-cart');
        $text = $text ?? __('The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.', 'fluent-cart');
        $buttonText = $buttonText ?? __('Go Back to Home Page', 'fluent-cart');
        $notFoundImg = empty($notFoundImg) ? Vite::getAssetUrl() . 'images/404.svg': $notFoundImg;

        $data = [
            'title'       => $title,
            'text'        => $text,
            'buttonText'  => $buttonText,
            'notFoundImg' => $notFoundImg,
            'buttonUrl'   => $buttonUrl
        ];

        self::enqueueNotFoundPageAssets();

        return self::loadView($pageTitle, $data, 'not-found.php');
    }

    public static function renderFileNotFoundPage()
    {
        return static::renderNotFoundPage(
            __('File Not Found', 'fluent-cart'),
            __('404 - File Not Found', 'fluent-cart'),
            __('The requested file is unavailable or the download link has expired.', 'fluent-cart')
        );
    }

    public static function renderForPrint($content, $params = [])
    {

        $params['content'] = $content;
        $app = fluentCart();
        $slug = $app->config->get('app.slug');
        Vite::enqueueStaticScript(
            $slug . '-fluentcart-print-this-plugin',
            'public/lib/printThis-2.0.0.min.js',
            []
        );

        Vite::enqueueScript(
            $slug . '-fluentcart-print-js',
            'public/print/Print.js',
            []
        );

        return self::loadView('', $params, 'print/index.php');
    }



}
