<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\Framework\Support\Arr;

class DownloadParser extends BaseParser
{
    private $download;

    public function __construct($data)
    {
        $this->download = Arr::get($data, 'download', []);
        parent::__construct($data);
    }

    public function parse($accessor = null, $code = null, $transformer = null): ?string
    {
        $download = $this->download;

        switch ($accessor) {
            case 'sl':
                return (string) (isset($download['sl']) ? $download['sl'] : '');
            case 'title':
                return esc_html(isset($download['title']) ? $download['title'] : '');
            case 'product_name':
                return esc_html(isset($download['product_name']) ? $download['product_name'] : '');
            case 'url':
                return esc_url(isset($download['download_url']) ? $download['download_url'] : '');
            case 'file_size':
                return esc_html(isset($download['formatted_file_size']) ? $download['formatted_file_size'] : '');
            default:
                return Arr::get($download, $accessor, '');
        }
    }
}
