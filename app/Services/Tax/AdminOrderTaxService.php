<?php

namespace FluentCart\App\Services\Tax;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\Tax\TaxCalculator;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\Framework\Support\Arr;

/**
 * AdminOrderTaxService
 *
 * Calculates tax for admin-created or admin-edited orders.
 * Accepts plain items + address arrays instead of a live cart session.
 * Mirrors the config-building logic in TaxModule::calculateCartTax().
 *
 * @since 1.3.11
 */
class AdminOrderTaxService
{
    /**
     * Calculate tax for a set of admin order items and a billing/shipping address.
     *
     * @param array $items   Each item: ['post_id', 'object_id', 'qty', 'subtotal', 'discount_total']
     * @param array $address ['country', 'state', 'city', 'postcode']
     *
     * @return array{tax_total: int, shipping_tax: int, tax_lines: array, tax_behavior: int, tax_country: string}|null
     *   Returns null when tax is disabled or country is missing.
     */
    /**
     * Pick the correct tax address based on the store's tax_calculation_basis setting.
     * Falls back: shipping → billing when no shipping country; store → store config.
     *
     * @param string     $basis           'billing' | 'shipping' | 'store'
     * @param array|null $billingAddress  ['country', 'state', 'city', 'postcode']
     * @param array|null $shippingAddress ['country', 'state', 'city', 'postcode']
     * @return array
     */
    public static function resolveAddressForBasis($basis, $billingAddress, $shippingAddress = null)
    {
        if ($basis === 'store') {
            $s = new StoreSettings();
            return [
                'country'  => $s->get('store_country', ''),
                'state'    => $s->get('store_state', ''),
                'city'     => $s->get('store_city', ''),
                'postcode' => $s->get('store_postcode', ''),
            ];
        }

        if ($basis === 'shipping' && !empty($shippingAddress) && !empty($shippingAddress['country'])) {
            return $shippingAddress;
        }

        return $billingAddress ?: [];
    }

    public static function calculate($items, $address, $taxSettings = null)
    {
        if (!TaxModule::isTaxEnabled()) {
            return null;
        }

        $country  = Arr::get($address, 'country', '');
        $state    = Arr::get($address, 'state', '');
        $city     = Arr::get($address, 'city', '');
        $postCode = Arr::get($address, 'postcode', '');

        if (empty($country)) {
            return null;
        }

        // Build line-item array in the shape TaxCalculator expects:
        // key fields: post_id, object_id, subtotal, discount_total, shipping_charge, quantity.
        // Fee lines (is_fee) mirror Cart::buildFeeCartItem(): post_id/object_id 0,
        // quantity 1 — TaxCalculator applies the first product's rates to them.
        $lineItems = [];
        foreach ($items as $item) {
            $isFee         = !empty($item['is_fee']);
            $subtotal      = (int) Arr::get($item, 'subtotal', 0);
            $discountTotal = (int) Arr::get($item, 'discount_total', 0);
            $qty           = (int) Arr::get($item, 'qty', Arr::get($item, 'quantity', 1));

            $lineItems[] = [
                // Order-item id, when the caller has one. Custom lines all share
                // post_id/object_id 0:0, so the id is the only per-line identity
                // the patch step can match on.
                'id'              => $isFee ? 0 : (int) Arr::get($item, 'id', 0),
                'post_id'         => $isFee ? 0 : (int) Arr::get($item, 'post_id', Arr::get($item, 'product_id', 0)),
                'object_id'       => $isFee ? 0 : (int) Arr::get($item, 'object_id', Arr::get($item, 'variation_id', 0)),
                'subtotal'        => $subtotal,
                'discount_total'  => $discountTotal,
                'shipping_charge' => (int) Arr::get($item, 'shipping_charge', 0),
                'quantity'        => $isFee ? 1 : $qty,
                'is_fee'          => $isFee,
                'title'           => (string) Arr::get($item, 'title', ''),
                'other_info'      => Arr::get($item, 'other_info', []),
            ];
        }

        if (empty($lineItems)) {
            return null;
        }

        try {
            if ($taxSettings === null) {
                $taxSettings = (new TaxModule())->getSettings();
            }
            $taxCalculator = new TaxCalculator($lineItems, [
                'inclusive'    => false,
                'country'      => $country,
                'state'        => $state,
                'city'         => $city,
                'postcode'     => $postCode,
                'tax_rounding' => Arr::get($taxSettings, 'tax_rounding', 'item'),
            ]);

            $taxedLines = $taxCalculator->getTaxedLines();

            // Split fee lines out of the taxed lines — same convention as
            // TaxModule::calculateCartTax(). Fee lines never enter line_items
            // (no post_id to patch); their tax is reported via fee_tax /
            // fee_tax_lines. Note: getTotalTax() and getTaxLinesByRates()
            // already include the fee portion; getExclusiveTaxTotal() excludes
            // fee lines — identical to the aggregates checkout stores.
            $productLines = [];
            $feeTax       = 0;
            $feeTaxLines  = [];
            foreach ($taxedLines as $taxedLine) {
                if (!empty($taxedLine['is_fee'])) {
                    $feeLineTax = (int) Arr::get($taxedLine, 'tax_amount', 0);
                    $feeTax += $feeLineTax;
                    $feeTaxLines[] = [
                        'label'      => Arr::get($taxedLine, 'title', ''),
                        'tax_amount' => $feeLineTax,
                        'inclusive'  => (bool) Arr::get($taxedLine, 'line_meta.tax_config.inclusive', false),
                    ];
                } else {
                    $productLines[] = $taxedLine;
                }
            }

            $lineItemsResult = array_values(array_map(function ($lineItem) {
                return [
                    'id'                    => (int) Arr::get($lineItem, 'id', 0),
                    'post_id'               => (int) Arr::get($lineItem, 'post_id', 0),
                    'object_id'             => (int) Arr::get($lineItem, 'object_id', 0),
                    'tax_amount'            => (int) Arr::get($lineItem, 'tax_amount', 0),
                    'line_meta'             => Arr::get($lineItem, 'line_meta', []),
                    'recurring_tax'         => (int) Arr::get($lineItem, 'other_info.recurring_tax', 0),
                    'signup_fee_tax'        => (int) Arr::get($lineItem, 'other_info.signup_fee_tax', 0),
                    'signup_fee_tax_config' => Arr::get($lineItem, 'signup_fee_tax_config', []),
                ];
            }, $productLines));

            return [
                'tax_total'           => $taxCalculator->getTotalTax(),
                'exclusive_tax_total' => $taxCalculator->getExclusiveTaxTotal(),
                'shipping_tax'        => $taxCalculator->getShippingTax(),
                'shipping_tax_lines'  => $taxCalculator->getShippingTaxByRates(),
                'fee_tax'             => $feeTax,
                'fee_tax_lines'       => $feeTaxLines,
                'tax_lines'           => $taxCalculator->getTaxLinesByRates(),
                'tax_behavior'        => $taxCalculator->getTaxBehaviorValue(),
                'store_tax_behavior'  => $taxCalculator->getStoreTaxBehaviorValue(),
                'tax_country'         => $taxCalculator->getTaxCountry(),
                'line_items'          => $lineItemsResult,
            ];
        } catch (\Exception $e) {
            // Log but never block order creation
            fluent_cart_warning_log(
                'AdminOrderTaxService: calculation failed',
                get_class($e) . ': ' . wp_strip_all_tags($e->getMessage()),
                ['module_name' => 'tax', 'log_type' => 'api']
            );
            return null;
        }
    }
}
