<?php

namespace FluentCart\App\Services\Libs\Emogrifier;

class Emogrifier
{
    private $html = '';

    public function __construct($html)
    {
        $this->html = (string) $html;
    }

    public function emogrify()
    {
        if (!class_exists('\FluentEmogrifier\Vendor\TijsVerkoyen\CssToInlineStyles\CssToInlineStyles')) {
            require_once __DIR__ . '/scoped-vendor/autoload.php';
        }

        return $this->handleTijsVerkoyen();
    }

    private function handleTijsVerkoyen()
    {
        $css = '';
        $html = $this->html;

        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $html, $matches)) {
            $css = implode("\n", $matches[1]);
            $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        }

        // Preserve @media queries — TijsVerkoyen strips them during CSS processing
        // but email clients like Apple Mail and Gmail support them for responsive layouts.
        // Regex handles both spaced (@media screen) and minified (@media(max-width:600px)) forms.
        $mediaBlocks = '';
        if (preg_match_all('/@media\s*[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/s', $css, $mediaMatches)) {
            $mediaBlocks = implode("\n", $mediaMatches[0]);
        }

        $inliner = new \FluentEmogrifier\Vendor\TijsVerkoyen\CssToInlineStyles\CssToInlineStyles();
        $result = $inliner->convert($html, $css);

        // Re-inject @media blocks for responsive email support
        if ($mediaBlocks) {
            $styleTag = '<style type="text/css">' . $mediaBlocks . '</style>';

            if (stripos($result, '</head>') !== false) {
                $result = str_ireplace('</head>', $styleTag . '</head>', $result);
            } elseif (stripos($result, '<body') !== false) {
                $result = preg_replace('/<body/i', $styleTag . '<body', $result, 1);
            } else {
                $result = $styleTag . $result;
            }
        }

        return $result;
    }

}
