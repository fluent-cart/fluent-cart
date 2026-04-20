<?php

namespace FluentCart\App\Services\PDF;

use FluentPdf\Classes\Controller\AvailableOptions;

class PdfGeneratorService
{
    private $workingDir = '';
    private $tempDir = '';
    private $pdfCacheDir = '';
    private $fontDir = '';

    public function __construct()
    {
        if (!defined('FLUENT_PDF')) {
            return;
        }

        $dirStructure = AvailableOptions::getDirStructure();

        $this->workingDir = $dirStructure['workingDir'];
        $this->tempDir = $dirStructure['tempDir'];
        $this->pdfCacheDir = $dirStructure['pdfCacheDir'];
        $this->fontDir = $dirStructure['fontDir'];
    }

    public function getGenerator(array $mpdfConfig = []): \FluentPdf\Vendor\Mpdf\Mpdf
    {
        $uploadDir = wp_upload_dir();
        $fluentPdfFontDir = $uploadDir['basedir'] . '/FLUENT_PDF_TEMPLATES/fonts';

        $fontDirs = [$this->fontDir];
        if (is_dir($fluentPdfFontDir)) {
            $fontDirs[] = $fluentPdfFontDir;
        }

        $defaults = [
            'fontDir'                 => $fontDirs,
            'tempDir'                 => $this->tempDir,
            'curlCaCertificate'       => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
            'curlFollowLocation'      => true,
            'allow_output_buffering'  => true,
            'autoLangToFont'          => true,
            'autoScriptToLang'        => true,
            'useSubstitutions'        => true,
            'ignore_invalid_utf8'     => true,
            'setAutoTopMargin'        => 'stretch',
            'setAutoBottomMargin'     => 'stretch',
            'enableImports'           => true,
            'use_kwt'                 => true,
            'keepColumns'             => true,
            'biDirectional'           => true,
            'showWatermarkText'       => true,
            'showWatermarkImage'      => true,
            'default_font'            => 'dejavusans',
        ];

        $mpdfConfig = wp_parse_args($mpdfConfig, $defaults);

        $mpdfConfig = apply_filters('fluent_cart/pdf_templates/mpdf_config', $mpdfConfig);

        return new \FluentPdf\Vendor\Mpdf\Mpdf($mpdfConfig);
    }

    public function getTempDirectory(): string
    {
        return $this->tempDir;
    }

    /**
     * Resolve same-site image URLs in HTML to local filesystem paths for mPDF.
     *
     * @param string $html
     * @return string
     */
    public function prepareHtmlForPdf(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        // Remove <img> tags with empty src to prevent mPDF errors
        $html = preg_replace('/<img\s[^>]*\bsrc\s*=\s*["\']["\'][^>]*>/i', '', $html) ?? $html;

        $upload_dir = wp_upload_dir();
        $content_url = content_url();
        $content_dir = WP_CONTENT_DIR;
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

        $resolveUrl = function ($url) use ($upload_dir, $content_url, $content_dir, $home_host) {
            $url = trim($url);
            if ($url === '' || strpos($url, 'data:') === 0) {
                return null;
            }
            $url_host = wp_parse_url($url, PHP_URL_HOST);
            if ($url_host !== null && $url_host !== $home_host) {
                return null;
            }
            $path_part = wp_parse_url($url, PHP_URL_PATH);
            if ($path_part === null || $path_part === '') {
                return null;
            }
            if (!empty($upload_dir['baseurl']) && strpos($url, $upload_dir['baseurl']) === 0) {
                $local_path = $upload_dir['basedir'] . substr($path_part, strlen(wp_parse_url($upload_dir['baseurl'], PHP_URL_PATH)));
                $local_path = wp_normalize_path($local_path);
                if (file_exists($local_path) && is_readable($local_path)) {
                    return $local_path;
                }
                return null;
            }
            if (!empty($content_url) && strpos($url, $content_url) === 0) {
                $content_path = wp_parse_url($content_url, PHP_URL_PATH);
                $local_path = $content_dir . substr($path_part, strlen($content_path));
                $local_path = wp_normalize_path($local_path);
                if (file_exists($local_path) && is_readable($local_path)) {
                    return $local_path;
                }
                return null;
            }
            return null;
        };

        $html = preg_replace_callback(
            '/(<img\s[^>]*\bsrc\s*=\s*["\'])([^"\']+)(["\'][^>]*>)|\b(url\s*\(\s*["\']?)([^"\')\s]+)(["\']?\s*\))/i',
            function ($m) use ($resolveUrl) {
                if ($m[1] !== '') {
                    $resolved = $resolveUrl($m[2]);
                    if ($resolved === null) {
                        return $m[0];
                    }
                    $dataUri = $this->flattenPngTransparency($resolved);
                    return $dataUri !== null ? $m[1] . $dataUri . $m[3] : $m[1] . $resolved . $m[3];
                }
                $resolved = $resolveUrl($m[5]);
                return $resolved !== null
                    ? 'url(\'' . str_replace(["\\", "'"], ["\\\\", "\\'"], $resolved) . '\')'
                    : $m[0];
            },
            $html
        );

        return $html;
    }

    /**
     * If $path is a PNG with an alpha channel, composite it onto a white
     * canvas and return a base64 JPEG data URI.
     *
     * @param string $path
     * @return string|null
     */
    private function flattenPngTransparency(string $path): ?string
    {
        if (!function_exists('imagecreatefrompng') || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'png') {
            return null;
        }

        $src = @imagecreatefrompng($path);
        if (!$src) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);

        $hasAlpha = false;
        for ($x = 0; $x < $w && !$hasAlpha; $x++) {
            for ($y = 0; $y < $h && !$hasAlpha; $y++) {
                if ((imagecolorat($src, $x, $y) >> 24) & 0x7F) {
                    $hasAlpha = true;
                }
            }
        }

        if (!$hasAlpha) {
            imagedestroy($src);
            return null;
        }

        $canvas = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        ob_start();
        imagejpeg($canvas, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($canvas);

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }
}
