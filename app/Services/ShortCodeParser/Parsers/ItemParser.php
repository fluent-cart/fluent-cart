<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class ItemParser extends BaseParser
{
    private $item;
    private $order;

    public function __construct($data)
    {
        $this->item = Arr::get($data, 'item', []);
        $this->order = Arr::get($data, 'order');
        parent::__construct($data);
    }

    public function parse($accessor = null, $code = null, $transformer = null): ?string
    {
        $item = $this->item;

        switch ($accessor) {
            case 'sl':
                return (string) ($item['sl'] ?? '');
            case 'name':
                return esc_html(!empty($item['post_title']) ? $item['post_title'] : ($item['title'] ?? ''));
            case 'variant':
                return esc_html($item['title'] ?? '');
            case 'quantity':
                return esc_html($item['quantity'] ?? '');
            case 'price':
                return isset($item['line_total']) ? (string) ($item['line_total'] / 100) : '';
            case 'price_formatted':
                return $item['formatted_total'] ?? '';
            case 'unit_price':
                return isset($item['unit_price']) ? (string) ($item['unit_price'] / 100) : '';
            case 'unit_price_formatted':
                $unitPrice = isset($item['unit_price']) ? Helper::toDecimal($item['unit_price']) : '';
                return esc_html($unitPrice);
            case 'subtotal':
                return isset($item['subtotal']) ? (string) ($item['subtotal'] / 100) : '';
            case 'subtotal_formatted':
                $subtotal = isset($item['subtotal']) ? Helper::toDecimal($item['subtotal']) : '';
                return esc_html($subtotal);
            case 'payment_info':
                return wp_kses_post($item['payment_info'] ?? '');
            case 'payment_type':
                return esc_html($item['payment_type'] ?? '');
            default:
                return Arr::get($item, $accessor, '');
        }
    }
}
