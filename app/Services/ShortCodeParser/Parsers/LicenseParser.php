<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\Framework\Support\Arr;

class LicenseParser extends BaseParser
{
    private $license;

    public function __construct($data)
    {
        $this->license = Arr::get($data, 'license');
        parent::__construct($data);
    }

    public function parse($accessor = null, $code = null, $transformer = null): ?string
    {
        $license = $this->license;

        if (!$license) {
            return '';
        }

        switch ($accessor) {
            case 'sl':
                return (string) (isset($license->sl) ? $license->sl : '');
            case 'key':
                return esc_html($license->license_key);
            case 'status':
                return esc_html($license->status);
            case 'product_name':
                return esc_html($license->product ? $license->product->post_title : '');
            case 'variant':
                return esc_html($license->productVariant ? $license->productVariant->variation_title : '');
            case 'limit':
                return $license->limit ? esc_html($license->limit) : __('Unlimited', 'fluent-cart');
            case 'activation_count':
                return esc_html($license->activation_count);
            case 'expiration_date':
                return $license->expiration_date
                    ? esc_html(date('M j, Y', strtotime($license->expiration_date)))
                    : __('Never', 'fluent-cart');
            default:
                return Arr::get((array) $license, $accessor, '');
        }
    }
}
