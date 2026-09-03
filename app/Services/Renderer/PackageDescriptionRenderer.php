<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;

class PackageDescriptionRenderer
{
    protected $product;

    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    /**
     * Build package info string from snapshotted other_info fields.
     *
     * @param array $otherInfo Order item's other_info array
     * @return string e.g. "Gift (Box) · 30 × 20 × 15 cm · Wt: 2 kg · Shipping: 2.5 kg"
     */
    public static function buildPackageInfoFromOtherInfo($otherInfo)
    {
        $storeWeightUnit = Helper::shopConfig('weight_unit') ?: 'kg';
        $parts = [];

        // Package name
        $name = Arr::get($otherInfo, 'package_name', '');
        if ($name) {
            $parts[] = $name;
        }

        // Dimensions
        $length = Arr::get($otherInfo, 'package_length', '');
        $width = Arr::get($otherInfo, 'package_width', '');
        $height = Arr::get($otherInfo, 'package_height', '');
        $dimensionUnit = Arr::get($otherInfo, 'package_dimension_unit', 'cm');
        $dimParts = array_filter([$length, $width, $height], function ($val) {
            return $val !== '' && $val !== null && $val != 0;
        });
        if ($dimParts) {
            $parts[] = implode(' × ', $dimParts) . ' ' . $dimensionUnit;
        }

        // Product weight
        $productWeight = floatval(Arr::get($otherInfo, 'weight', 0));
        $productWeightUnit = Arr::get($otherInfo, 'weight_unit', $storeWeightUnit);
        $convertedProductWeight = Helper::convertWeight($productWeight, $productWeightUnit, $storeWeightUnit);
        if ($convertedProductWeight) {
            $formatted = rtrim(rtrim(number_format($convertedProductWeight, 2), '0'), '.');
            $parts[] = sprintf(
                /* translators: %1$s: formatted product weight, %2$s: weight unit */
                __('Wt: %1$s %2$s', 'fluent-cart'),
                $formatted,
                $storeWeightUnit
            );
        }

        // Shipping weight (product + package)
        $packageWeight = floatval(Arr::get($otherInfo, 'package_weight', 0));
        $packageWeightUnit = Arr::get($otherInfo, 'package_weight_unit', $storeWeightUnit);
        $convertedPackageWeight = Helper::convertWeight($packageWeight, $packageWeightUnit, $storeWeightUnit);
        $totalWeight = $convertedProductWeight + $convertedPackageWeight;
        if ($totalWeight && $convertedPackageWeight) {
            $formatted = rtrim(rtrim(number_format($totalWeight, 2), '0'), '.');
            $parts[] = sprintf(
                /* translators: %1$s: formatted shipping weight, %2$s: weight unit */
                __('Shipping wt: %1$s %2$s', 'fluent-cart'),
                $formatted,
                $storeWeightUnit
            );
        }

        $info = implode(' · ', $parts);

        if (!$info) {
            return '';
        }

        return sprintf(
            /* translators: %1$s: package info summary */
            __('Package: %1$s', 'fluent-cart'),
            $info
        );
    }

    public function renderPackageDescription(
        $wrapper_attributes = '',
        $showName = true,
        $showDimensions = true,
        $showProductWeight = true,
        $showTotalWeight = true,
        $variant = null,
        $defaultVariant = null
    ) {
        if ($variant) {
            if (!$wrapper_attributes) {
                $wrapper_attributes = 'class="fct-package-description" data-fluent-cart-package-description';
            }

            $this->renderPackageDescriptionForVariant($variant, $wrapper_attributes, $showName, $showDimensions, $showProductWeight, $showTotalWeight);
            return;
        }

        $variants = $this->product->variants;

        if ($variants->isEmpty()) {
            return;
        }

        $defaultVariant = $defaultVariant ?: $this->resolveDefaultVariant();

        foreach ($variants as $v) {
            $isHidden = ($defaultVariant && $defaultVariant->id != $v->id) ? ' is-hidden' : '';
            $variantExtraAttributes = ' data-variation-id="' . esc_attr($v->id) . '"';

            if ($wrapper_attributes) {
                $variantWrapper = preg_replace(
                    '/class="([^"]*)"/',
                    'class="$1 fluent-cart-product-variation-content' . $isHidden . '"',
                    $wrapper_attributes,
                    1
                ) . $variantExtraAttributes;
            } else {
                $variantWrapper = 'class="fct-package-description fluent-cart-product-variation-content' . $isHidden . '" data-fluent-cart-package-description' . $variantExtraAttributes;
            }

            $this->renderPackageDescriptionForVariant($v, $variantWrapper, $showName, $showDimensions, $showProductWeight, $showTotalWeight);
        }
    }

    /**
     * Query-free default-variant resolution mirroring ProductRenderer's
     * constructor logic, so package-description rendering doesn't need to
     * construct the heavyweight ProductRenderer just to know which variant
     * should render visible initially.
     */
    private function resolveDefaultVariant()
    {
        $variants = $this->product->variants;
        $defaultVariationId = Arr::get($this->product->detail, 'default_variation_id');

        $isAdvanced = $this->product->detail
            && Arr::get($this->product->detail, 'variation_type') === Helper::PRODUCT_TYPE_ADVANCE_VARIATION;
        $eligibleVariants = $isAdvanced
            ? $variants->where('item_status', 'active')
            : $variants;

        if ($eligibleVariants->isEmpty()) {
            $eligibleVariants = $variants;
        }

        $eligibleIds = $eligibleVariants->pluck('id')->toArray();

        if (!$defaultVariationId || !in_array($defaultVariationId, $eligibleIds)) {
            $firstBySerial = $eligibleVariants
                ->sortBy(function ($variant) {
                    return is_null($variant->serial_index) ? PHP_INT_MAX : (int) $variant->serial_index;
                })
                ->first();
            $defaultVariationId = $firstBySerial ? $firstBySerial->id : Arr::get($eligibleIds, '0');
        }

        $resolved = $variants->first(function ($v) use ($defaultVariationId) {
            return $v->id == $defaultVariationId;
        });

        return $resolved ?: $variants->first();
    }

    private function renderPackageDescriptionForVariant(
        ProductVariation $variant,
        $wrapper_attributes,
        $showName,
        $showDimensions,
        $showProductWeight,
        $showTotalWeight
    ) {
        if ($variant->fulfillment_type !== 'physical') {
            return;
        }

        $packageSlug = Arr::get($variant->other_info, 'package_slug', '');
        $package = Helper::getPackageBySlug($packageSlug);

        if (!$package) {
            return;
        }

        $name = Arr::get($package, 'name', '');
        $length = Arr::get($package, 'length', '');
        $width = Arr::get($package, 'width', '');
        $height = Arr::get($package, 'height', '');
        $dimensionUnit = Arr::get($package, 'dimension_unit', 'cm');
        $packageWeight = floatval(Arr::get($package, 'weight', 0));
        $packageWeightUnit = Arr::get($package, 'weight_unit', 'kg');

        $storeWeightUnit = Helper::shopConfig('weight_unit') ?: 'kg';
        $otherInfo = $variant->other_info ?: [];
        $productWeight = floatval(Arr::get($otherInfo, 'weight', 0));
        $productWeightUnit = Arr::get($otherInfo, 'weight_unit', $storeWeightUnit);

        $convertedProductWeight = Helper::convertWeight($productWeight, $productWeightUnit, $storeWeightUnit);
        $convertedPackageWeight = Helper::convertWeight($packageWeight, $packageWeightUnit, $storeWeightUnit);
        $totalWeight = $convertedProductWeight + $convertedPackageWeight;

        $hasVisibleContent = ($showName && $name)
            || ($showDimensions && ($length || $width || $height))
            || ($showProductWeight && $convertedProductWeight)
            || ($showTotalWeight && $totalWeight);

        if (!$hasVisibleContent) {
            return;
        }

        echo sprintf('<div %s>', $wrapper_attributes); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<table class="fct-package-description__table" role="presentation"><tbody>';

        if ($showName && $name) {
            echo sprintf(
                '<tr><th>%s</th><td>%s</td></tr>',
                esc_html__('Package', 'fluent-cart'),
                esc_html($name)
            );
        }

        if ($showDimensions && ($length || $width || $height)) {
            $dimensionParts = array_filter([$length, $width, $height], function ($val) {
                return $val !== '' && $val !== null;
            });
            if ($dimensionParts) {
                $formattedDimensions = implode(' × ', $dimensionParts) . ' ' . $dimensionUnit;
                echo sprintf(
                    '<tr><th>%s</th><td>%s</td></tr>',
                    esc_html__('Dimensions', 'fluent-cart'),
                    esc_html($formattedDimensions)
                );
            }
        }

        if ($showProductWeight && $convertedProductWeight) {
            $formattedProductWeight = rtrim(rtrim(number_format($convertedProductWeight, 2), '0'), '.');
            echo sprintf(
                '<tr><th>%s</th><td>%s</td></tr>',
                esc_html__('Weight', 'fluent-cart'),
                esc_html($formattedProductWeight . ' ' . $storeWeightUnit)
            );
        }

        if ($showTotalWeight && $totalWeight && $convertedPackageWeight) {
            $formattedTotalWeight = rtrim(rtrim(number_format($totalWeight, 2), '0'), '.');
            echo sprintf(
                '<tr><th>%s</th><td>%s</td></tr>',
                esc_html__('Shipping Weight', 'fluent-cart'),
                esc_html($formattedTotalWeight . ' ' . $storeWeightUnit)
            );
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}
