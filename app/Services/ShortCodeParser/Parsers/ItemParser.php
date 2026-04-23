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
            case 'package_name':
                return esc_html($this->getFromOtherInfo('package_name'));
            case 'package_type':
                return esc_html($this->getPackageType());
            case 'dimensions':
                return esc_html($this->getFormattedDimensions());
            case 'product_weight':
                return esc_html($this->getFormattedProductWeight());
            case 'shipping_weight':
                return esc_html($this->getFormattedShippingWeight());
            default:
                return Arr::get($item, $accessor, '');
        }
    }

    /**
     * Get the item's other_info array.
     */
    private function getOtherInfo()
    {
        $otherInfo = Arr::get($this->item, 'other_info', []);
        return is_array($otherInfo) ? $otherInfo : [];
    }

    /**
     * Get a value from other_info.
     */
    private function getFromOtherInfo($key, $default = '')
    {
        return Arr::get($this->getOtherInfo(), $key, $default);
    }

    /**
     * Get translated package type from other_info.
     */
    private function getPackageType()
    {
        $type = $this->getFromOtherInfo('package_type', '');
        if (!$type) {
            return '';
        }

        static $typeLabels = null;
        if ($typeLabels === null) {
            $typeLabels = [
                'box'          => __('Box', 'fluent-cart'),
                'envelope'     => __('Envelope', 'fluent-cart'),
                'soft_package' => __('Soft Package', 'fluent-cart'),
            ];
        }

        return isset($typeLabels[$type]) ? $typeLabels[$type] : $type;
    }

    /**
     * Get formatted dimensions from other_info.
     */
    private function getFormattedDimensions()
    {
        $otherInfo = $this->getOtherInfo();
        $length = Arr::get($otherInfo, 'package_length', '');
        $width = Arr::get($otherInfo, 'package_width', '');
        $height = Arr::get($otherInfo, 'package_height', '');
        $dimensionUnit = Arr::get($otherInfo, 'package_dimension_unit', 'cm');

        $parts = array_filter([$length, $width, $height], function ($val) {
            return $val !== '' && $val !== null && $val != 0;
        });

        return $parts ? implode(' × ', $parts) . ' ' . $dimensionUnit : '';
    }

    /**
     * Get formatted product weight from other_info.
     */
    private function getFormattedProductWeight()
    {
        $otherInfo = $this->getOtherInfo();
        $storeWeightUnit = $this->getStoreWeightUnit();
        $productWeight = floatval(Arr::get($otherInfo, 'weight', 0));
        $productWeightUnit = Arr::get($otherInfo, 'weight_unit', $storeWeightUnit);

        if (!$productWeight) {
            return '';
        }

        $convertedWeight = Helper::convertWeight($productWeight, $productWeightUnit, $storeWeightUnit);
        $formatted = rtrim(rtrim(number_format($convertedWeight, 2), '0'), '.');

        return $formatted . ' ' . $storeWeightUnit;
    }

    /**
     * Get formatted shipping weight (product + package) from other_info.
     */
    private function getFormattedShippingWeight()
    {
        $otherInfo = $this->getOtherInfo();
        $storeWeightUnit = $this->getStoreWeightUnit();

        $productWeight = floatval(Arr::get($otherInfo, 'weight', 0));
        $productWeightUnit = Arr::get($otherInfo, 'weight_unit', $storeWeightUnit);
        $convertedProductWeight = Helper::convertWeight($productWeight, $productWeightUnit, $storeWeightUnit);

        $packageWeight = floatval(Arr::get($otherInfo, 'package_weight', 0));
        $packageWeightUnit = Arr::get($otherInfo, 'package_weight_unit', $storeWeightUnit);
        $convertedPackageWeight = Helper::convertWeight($packageWeight, $packageWeightUnit, $storeWeightUnit);

        $totalWeight = $convertedProductWeight + $convertedPackageWeight;
        if (!$totalWeight) {
            return '';
        }

        $formatted = rtrim(rtrim(number_format($totalWeight, 2), '0'), '.');

        return $formatted . ' ' . $storeWeightUnit;
    }

    /**
     * Get the store's weight unit, cached per request.
     */
    private function getStoreWeightUnit()
    {
        static $unit = null;
        if ($unit === null) {
            $unit = Helper::shopConfig('weight_unit') ?: 'kg';
        }
        return $unit;
    }
}
