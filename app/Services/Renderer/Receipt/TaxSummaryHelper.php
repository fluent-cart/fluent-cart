<?php

namespace FluentCart\App\Services\Renderer\Receipt;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Support\Arr;

class TaxSummaryHelper
{
    /**
     * Compute inclusive/exclusive tax split from order data.
     *
     * Returns ['shouldRender' => false] when there is nothing to show.
     * Otherwise returns shouldRender, isReverseCharge, inclusiveTax, exclusiveTax,
     * shippingTax, payableTax, totalOrderTax — all amounts in cents.
     */
    public static function computeTaxSummary(Order $order)
    {
        $order->loadMissing(['orderTaxRates']);

        $isReverseCharge = $order->isReverseChargeTaxOrder();
        $inclusiveTax    = 0;
        $exclusiveTax    = 0;

        // Per-fee tax breakdown — stored at order placement, empty for old/fee-less orders.
        $feeTaxLines     = (array) $order->getMeta('fee_tax_lines', []);
        $inclusiveFeeTax = 0;
        $exclusiveFeeTax = 0;
        foreach ($feeTaxLines as $ftl) {
            if (!empty($ftl['inclusive'])) {
                $inclusiveFeeTax += (int) Arr::get($ftl, 'tax_amount', 0);
            } else {
                $exclusiveFeeTax += (int) Arr::get($ftl, 'tax_amount', 0);
            }
        }
        // Backward-compat: old orders persist fee_tax as a scalar with no fee_tax_lines array.
        if (empty($feeTaxLines)) {
            $legacyFeeTax = (int) $order->getMeta('fee_tax', 0);
            if ($legacyFeeTax > 0) {
                $exclusiveFeeTax = $legacyFeeTax;
                $feeTaxLines     = [
                    [
                        'label'      => __('Fee', 'fluent-cart'),
                        'tax_amount' => $legacyFeeTax,
                        'inclusive'  => false,
                    ],
                ];
            }
        }
        $totalFeeTax = $inclusiveFeeTax + $exclusiveFeeTax;

        if ($order->orderTaxRates && $order->orderTaxRates->count()) {
            foreach ($order->orderTaxRates as $rate) {
                $meta             = is_array($rate->meta) ? $rate->meta : [];
                $isMixedInclusive = (bool) Arr::get($meta, 'is_mixed_inclusive', false);
                if ($isMixedInclusive) {
                    list($rateIncl, $rateExcl) = self::splitMixedRateTax($order, (int) $rate->tax_rate_id);
                    if ($rateIncl === 0 && $rateExcl === 0 && (int) $rate->order_tax > 0) {
                        // No per-item breakdown (legacy order) — fall back to rate meta or tax_behavior.
                        if (isset($meta['inclusive'])) {
                            if ((bool) $meta['inclusive']) {
                                $inclusiveTax += (int) $rate->order_tax;
                            } else {
                                $exclusiveTax += (int) $rate->order_tax;
                            }
                        } elseif ((int) $order->tax_behavior === 2) {
                            $inclusiveTax += (int) $rate->order_tax;
                        } else {
                            $exclusiveTax += (int) $rate->order_tax;
                        }
                    } else {
                        $inclusiveTax += $rateIncl;
                        $exclusiveTax += $rateExcl;
                    }
                } elseif (isset($meta['inclusive'])) {
                    if ((bool) $meta['inclusive']) {
                        $inclusiveTax += (int) $rate->order_tax;
                    } else {
                        $exclusiveTax += (int) $rate->order_tax;
                    }
                } else {
                    if ((int) $order->tax_behavior === 2) {
                        $inclusiveTax += (int) $rate->order_tax;
                    } else {
                        $exclusiveTax += (int) $rate->order_tax;
                    }
                }
            }

            // Guard for orders where fct_order_tax_rate.order_tax was stored
            // incorrectly by an old bug. order.tax_total is the authoritative
            // value; if the rate-row sum differs, reset so the item-level
            // fallback below sums from order_items.tax_amount instead.
            // For mixed-inclusive orders, splitMixedRateTax() returns product-only
            // tax (fee items have empty line_meta and are skipped). Accept the sum
            // as valid when it equals order.tax_total minus the known fee tax.
            $orderTaxTotal       = (int) $order->tax_total;
            $productTaxFromRates = $inclusiveTax + $exclusiveTax;
            $productTaxExpected  = $orderTaxTotal - $totalFeeTax;
            if ($orderTaxTotal > 0
                && $productTaxFromRates !== $orderTaxTotal
                && $productTaxFromRates !== $productTaxExpected
            ) {
                $inclusiveTax = 0;
                $exclusiveTax = 0;
            }
            // For non-mixed rate rows the full order_tax (product+fee) is in exclusiveTax
            // or inclusiveTax — strip the fee portion so product tax is isolated.
            if ($exclusiveFeeTax > 0 && $exclusiveTax >= $exclusiveFeeTax) {
                $exclusiveTax -= $exclusiveFeeTax;
            }
            if ($inclusiveFeeTax > 0 && $inclusiveTax >= $inclusiveFeeTax) {
                $inclusiveTax -= $inclusiveFeeTax;
            }
        } else {
            $isInclusive   = (int) $order->tax_behavior === 2;
            $orderTaxTotal = max(0, (int) $order->tax_total - $totalFeeTax);
            $inclusiveTax  = $isInclusive ? $orderTaxTotal : 0;
            $exclusiveTax  = $isInclusive ? 0 : $orderTaxTotal;
        }

        // Fallback: sum item-level tax_amount when order.tax_total was never written (e.g. admin-created orders).
        if ($inclusiveTax === 0 && $exclusiveTax === 0) {
            $order->loadMissing(['order_items']);
            if ($order->order_items) {
                $taxBehavior = (int) $order->tax_behavior;
                foreach ($order->order_items as $item) {
                    if ($item->payment_type === 'fee') {
                        continue;
                    }
                    $itemTax = (int) round($item->tax_amount);
                    if ($itemTax <= 0) {
                        continue;
                    }
                    if ($taxBehavior === 3) {
                        $lineMeta      = $item->line_meta;
                        $lineInclusive = (bool) Arr::get($lineMeta, 'tax_config.inclusive', false);
                        if ($lineInclusive) {
                            $inclusiveTax += $itemTax;
                        } else {
                            $exclusiveTax += $itemTax;
                        }
                    } elseif ($taxBehavior === 1) {
                        $exclusiveTax += $itemTax;
                    } else {
                        $inclusiveTax += $itemTax;
                    }
                }
            }
        }

        $shippingTax         = (int) $order->shipping_tax;
        $isShippingInclusive = self::isShippingTaxInclusive($order);
        $payableTax          = $exclusiveTax + $exclusiveFeeTax + ($isShippingInclusive ? 0 : $shippingTax);
        $totalOrderTax       = $inclusiveTax + $inclusiveFeeTax + ($isShippingInclusive ? $shippingTax : 0) + $payableTax;
        $taxRateLines        = $order->getDisplayTaxLines();
        $shippingTaxLines    = $order->getDisplayShippingTaxLines();

        if ($inclusiveTax === 0 && $inclusiveFeeTax === 0 && $payableTax === 0 && $shippingTax === 0 && empty($taxRateLines) && !$isReverseCharge) {
            return [
                'shouldRender'     => false,
                'taxRateLines'     => $taxRateLines,
                'shippingTaxLines' => $shippingTaxLines,
                'foldedRateLines'  => [],
                'includedInPrices' => 0,
                'displayMode'      => self::getTaxDisplayMode(),
                'simpleLine'       => null,
            ];
        }

        $shouldRender = apply_filters('fluent_cart/tax_summary_should_render', true, $order);

        $reversedTaxTotal    = 0;
        $reversedShippingTax = 0;
        $rcPriceMode          = '';
        $rcShippingAdjustment = 0;
        $rcTotalAdjustment    = 0;
        $shippingNetStored    = false;
        if ($isReverseCharge) {
            $primaryRate = $order->orderTaxRates ? $order->orderTaxRates->first() : null;
            if ($primaryRate) {
                $meta = is_array($primaryRate->meta) ? $primaryRate->meta : [];
                $reversedTaxTotal    = (int) Arr::get($meta, 'reverse_charge_original_tax_total', 0);
                $reversedShippingTax = (int) Arr::get($meta, 'reverse_charge_original_shipping_tax', 0);
                $rcPriceMode         = (string) Arr::get($meta, 'reverse_charge_price_mode', 'fixed');
                $shippingNetStored   = !empty($meta['shipping_net_stored']);
            } else {
                // No orderTaxRate row at all — a MoR gateway (Paddle, or any future one)
                // applied the reverse charge and never ran the tax module, so there's no
                // rate row to read. order.total_amount is still the gross catalog price
                // (never lowered — see PaddleReconciler::coverReverseCharge), so the full
                // removed VAT has to come from business_info instead of the shipping-only
                // adjustment used for core-handled reverse charge orders below.
                $businessInfo     = $order->getBusinessInfo();
                $reversedTaxTotal = (int) Arr::get($businessInfo, 'mor_vat_removed', 0);
                $rcTotalAdjustment = $reversedTaxTotal;
            }
            // Only apply the display adjustment for orders where shipping_total in DB is still gross.
            // New orders (shipping_net_stored = true) already have net shipping in DB — no adjustment.
            if ($rcPriceMode === 'dynamic' && $isShippingInclusive && $reversedShippingTax > 0 && !$shippingNetStored) {
                $rcShippingAdjustment = $reversedShippingTax;
            }
        }
        // Show RC shipping strikethrough row only for exclusive shipping tax that was reversed.
        // For inclusive shipping it either already reduced the price (dynamic) or didn't change
        // it at all (fixed), so the strikethrough is misleading in both cases.
        $showRcShippingRow = $isReverseCharge && !$isShippingInclusive && $reversedShippingTax > 0;

        $summary = [
            'shouldRender'        => (bool) $shouldRender,
            'isReverseCharge'     => $isReverseCharge,
            'inclusiveTax'        => $inclusiveTax,
            'exclusiveTax'        => $exclusiveTax,
            'taxRateLines'        => $taxRateLines,
            'feeTaxLines'         => $feeTaxLines,
            'feeTaxLineRows'      => self::buildFeeTaxLineRows($feeTaxLines),
            'inclusiveFeeTax'     => $inclusiveFeeTax,
            'shippingTax'         => $shippingTax,
            'shippingTaxLines'    => $shippingTaxLines,
            'payableTax'          => $payableTax,
            'totalOrderTax'       => $totalOrderTax,
            'isShippingInclusive' => $isShippingInclusive,
            'reversedTaxTotal'     => $reversedTaxTotal,
            'reversedShippingTax'  => $reversedShippingTax,
            'rcPriceMode'          => $rcPriceMode,
            'rcShippingAdjustment' => $rcShippingAdjustment,
            'rcTotalAdjustment'    => $rcTotalAdjustment ?: $rcShippingAdjustment,
            'showRcShippingRow'    => $showRcShippingRow,
            // Under reverse charge the stored rate/shipping lines are zeroed, so the
            // per-rate rows are rebuilt from item-level line_meta instead. Empty rows
            // there mean "not recoverable" — surfaces fall back to the simplified box.
            'foldedRateLines'     => $isReverseCharge
                ? self::buildReverseChargeRateRows($order)
                : self::buildFoldedRateRows(
                    $taxRateLines,
                    $shippingTaxLines,
                    'order_tax',
                    $isShippingInclusive,
                    self::computeRateBaseMap(self::getOrderItemsForBaseMap($order))
                ),
            // Inclusive shipping tax follows the store global tax mode: when shipping is
            // priced inclusive its tax is already baked into the shipping price, so it
            // belongs in "of which included in prices". This keeps
            // includedInPrices + payableTax === totalOrderTax on every surface.
            'includedInPrices'    => $inclusiveTax + $inclusiveFeeTax + ($isShippingInclusive ? $shippingTax : 0),
        ];

        $summary['displayMode'] = self::getTaxDisplayMode();
        $summary['simpleLine']  = self::buildSimpleLine($summary);

        return $summary;
    }

    /**
     * Order items used to build the per-rate base map for the tax breakdown table.
     */
    private static function getOrderItemsForBaseMap(Order $order)
    {
        $order->loadMissing(['order_items']);

        return $order->order_items ? $order->order_items->all() : [];
    }

    /**
     * For a mixed-inclusive rate (same rate used inclusively on some items and exclusively on others),
     * read each order item's line_meta to produce the correct inclusive/exclusive split.
     * Handles both item shapes:
     *   - Current (all item types): line_meta.tax_config.rates[] + line_meta.tax_config.inclusive
     *   - Legacy signup-fee: line_meta.rates[] + line_meta.inclusive (no tax_config wrapper)
     * Returns [inclusiveTax, exclusiveTax] in cents.
     */
    private static function splitMixedRateTax(Order $order, $rateId)
    {
        $order->loadMissing(['order_items']);
        $incl = 0;
        $excl = 0;
        if (!$order->order_items) {
            return [0, 0];
        }
        foreach ($order->order_items as $item) {
            $lineMeta  = $item->line_meta;
            $taxConfig = Arr::get($lineMeta, 'tax_config');
            if (is_array($taxConfig)) {
                $rates         = Arr::get($taxConfig, 'rates', []);
                $lineInclusive = (bool) Arr::get($taxConfig, 'inclusive', false);
            } else {
                $rates         = Arr::get($lineMeta, 'rates', []);
                $lineInclusive = (bool) Arr::get($lineMeta, 'inclusive', false);
            }
            foreach ($rates as $rate) {
                if ((int) Arr::get($rate, 'rate_id', 0) !== $rateId) {
                    continue;
                }
                $taxAmount = (int) Arr::get($rate, 'tax_amount', 0);
                if ($taxAmount <= 0) {
                    continue;
                }
                if ($lineInclusive) {
                    $incl += $taxAmount;
                } else {
                    $excl += $taxAmount;
                }
            }
        }
        return [$incl, $excl];
    }

    /**
     * Extract per-rate tax breakdown from a single order item.
     *
     * Reads `line_meta.tax_config.rates`, filters out zero-amount entries, and
     * returns a flat array of rate rows. Returns [] for old items without
     * tax_config — callers must check for empty before looping.
     */
    public static function getItemTaxRates(array $item)
    {
        // Current items (all types): line_meta.tax_config.rates
        // Legacy signup_fee items: line_meta.rates (no tax_config wrapper — set directly from signup_fee_tax_config)
        $taxConfig = Arr::get($item, 'line_meta.tax_config');
        if (is_array($taxConfig)) {
            $rates     = Arr::get($taxConfig, 'rates', []);
            $inclusive = (bool) Arr::get($taxConfig, 'inclusive', false);
        } else {
            $rates     = Arr::get($item, 'line_meta.rates', []);
            $inclusive = (bool) Arr::get($item, 'line_meta.inclusive', false);
        }

        if (empty($rates)) {
            return [];
        }

        $result = [];
        foreach ($rates as $rate) {
            $taxAmount = (int) Arr::get($rate, 'tax_amount', 0);
            if ($taxAmount <= 0) {
                continue;
            }
            $result[] = [
                'label'        => Arr::get($rate, 'label') ?: __('Tax', 'fluent-cart'),
                'tax_amount'   => $taxAmount,
                'rate_percent' => max(0.0, (float) Arr::get($rate, 'rate_percent', 0)),
                'inclusive'    => $inclusive,
            ];
        }

        return $result;
    }

    /**
     * Determine whether the primary tax type for an order is inclusive.
     * Used for per-item pill display (fct_order_tax_rate has no order_item_id).
     */
    public static function isPrimaryTaxInclusive(Order $order)
    {
        $order->loadMissing(['orderTaxRates']);

        if ($order->orderTaxRates && $order->orderTaxRates->count() === 1) {
            $rate = $order->orderTaxRates->first();
            $meta = is_array($rate->meta) ? $rate->meta : [];
            if (isset($meta['inclusive'])) {
                return (bool) $meta['inclusive'];
            }
        }

        return (int) $order->tax_behavior === 2;
    }

    /**
     * Determine whether the shipping tax on an order was charged inclusive of the
     * shipping price (vs. added on top). Reads `meta.shipping_inclusive` (written
     * from the store-level tax mode at order placement) from each rate row that
     * contributed shipping_tax. Falls back to $order->tax_behavior === 2.
     *
     * Shipping always follows the store-level tax mode — per-product `meta.inclusive`
     * is intentionally NOT consulted here.
     */
    public static function isShippingTaxInclusive(Order $order)
    {
        $order->loadMissing(['orderTaxRates']);

        if ($order->orderTaxRates && $order->orderTaxRates->count()) {
            $shippingRatesInclusive = null;
            foreach ($order->orderTaxRates as $rate) {
                $meta = is_array($rate->meta) ? $rate->meta : [];
                // On reverse-charge orders shipping_tax is zeroed; detect via the
                // pre-zeroed snapshot stored in meta before falling back.
                $hasShippingContrib = (int) $rate->shipping_tax > 0
                    || (int) Arr::get($meta, 'reverse_charge_original_shipping_tax', 0) > 0;
                if (!$hasShippingContrib) {
                    continue;
                }
                if (!isset($meta['shipping_inclusive'])) {
                    continue;
                }
                $rateInclusive = (bool) $meta['shipping_inclusive'];
                if ($shippingRatesInclusive === null) {
                    $shippingRatesInclusive = $rateInclusive;
                } elseif ($shippingRatesInclusive !== $rateInclusive) {
                    return (int) $order->tax_behavior === 2;
                }
            }
            if ($shippingRatesInclusive !== null) {
                return $shippingRatesInclusive;
            }
        }

        return (int) $order->tax_behavior === 2;
    }

    /**
     * Returns display-ready fee tax line rows, filtering out zero-amount entries
     * and pre-computing the translated label for each surface to render.
     * Each entry: ['label' => string, 'tax_amount' => int, 'inclusive' => bool, 'display_label' => string]
     */
    public static function buildFeeTaxLineRows(array $feeTaxLines)
    {
        $rows = [];
        foreach ($feeTaxLines as $ftl) {
            $taxAmount = (int) Arr::get($ftl, 'tax_amount', 0);
            if ($taxAmount <= 0) {
                continue;
            }
            $inclusive  = !empty($ftl['inclusive']);
            $feeLabel   = Arr::get($ftl, 'label', __('fee', 'fluent-cart'));
            /* translators: %1$s: fee label */
            $displayLabel = $inclusive
                ? sprintf(__('Included in %1$s', 'fluent-cart'), $feeLabel)
                : sprintf(__('Added on %1$s', 'fluent-cart'), $feeLabel);
            $rows[] = [
                'label'         => $feeLabel,
                'tax_amount'    => $taxAmount,
                'inclusive'     => $inclusive,
                'display_label' => $displayLabel,
            ];
        }
        return $rows;
    }

    /**
     * Aggregate the real taxable base per rate from item-level tax data.
     *
     * For inclusive lines the stored taxable_amount is gross (base + tax), so the
     * rate's own tax is subtracted to get the net base. Amounts can be fractional
     * cents when the store uses subtotal tax rounding — callers round for display.
     *
     * @param iterable $items Order items (models or arrays) or cart line arrays,
     *                        each carrying line_meta.tax_config.
     * @return array [rate_id => ['base' => float, 'tax' => float]]
     */
    public static function computeRateBaseMap($items, $excludeInclusive = false)
    {
        $map = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $lineMeta = Arr::get($item, 'line_meta', []);
            } else {
                $lineMeta = is_object($item) ? $item->line_meta : [];
            }
            if (!is_array($lineMeta)) {
                continue;
            }
            $taxConfig = Arr::get($lineMeta, 'tax_config');
            if (is_array($taxConfig)) {
                $rates     = Arr::get($taxConfig, 'rates', []);
                $inclusive = (bool) Arr::get($taxConfig, 'inclusive', false);
            } else {
                // Legacy signup-fee shape: rates at the line_meta root.
                $rates     = Arr::get($lineMeta, 'rates', []);
                $inclusive = (bool) Arr::get($lineMeta, 'inclusive', false);
            }
            if (!$rates || !is_array($rates)) {
                continue;
            }
            // Fixed-mode reverse charge keeps tax-inclusive prices untouched — those
            // lines carry no reversible VAT, so callers can exclude them from the map.
            if ($excludeInclusive && $inclusive) {
                continue;
            }
            foreach ($rates as $rate) {
                $rateId  = (int) Arr::get($rate, 'rate_id', 0);
                $tax     = (float) Arr::get($rate, 'tax_amount', 0);
                $taxable = (float) Arr::get($rate, 'taxable_amount', 0);
                if (!isset($map[$rateId])) {
                    $map[$rateId] = ['base' => 0.0, 'tax' => 0.0];
                }
                $map[$rateId]['base'] += $inclusive ? max(0, $taxable - $tax) : $taxable;
                $map[$rateId]['tax']  += $tax;
            }
        }
        return $map;
    }

    /**
     * Build a folded per-rate row array for the 3-column tax breakdown table.
     *
     * Merges order-tax rate lines with shipping-tax lines by rate_id so each rate
     * appears once with its combined tax and computed taxable base.
     *
     * @param array  $rateLines          Output of Order::getDisplayTaxLines().
     * @param array  $shippingLines      Output of Order::getDisplayShippingTaxLines().
     * @param string $taxAmountKey       Key holding the order tax amount in each $rateLine ('order_tax').
     * @param bool   $isShippingInclusive Whether shipping tax is inclusive.
     * @param array  $rateBaseMap        Output of computeRateBaseMap() — exact per-rate bases
     *                                   from item data. A rate entry is only trusted when its
     *                                   item tax sum matches the rate row's tax (guards fee
     *                                   items and legacy rows missing from the item data).
     * @return array Each row: ['label'=>string,'base'=>int,'tax'=>int,'inclusive'=>bool]
     */
    public static function buildFoldedRateRows($rateLines, $shippingLines, $taxAmountKey, $isShippingInclusive, $rateBaseMap = [])
    {
        $rateLines     = is_array($rateLines) ? $rateLines : [];
        $shippingLines = is_array($shippingLines) ? $shippingLines : [];
        if (empty($rateLines) && empty($shippingLines)) {
            return [];
        }
        $shippingByRate = [];
        foreach ($shippingLines as $shLine) {
            $shippingByRate[(int) Arr::get($shLine, 'rate_id', 0)] = (int) Arr::get($shLine, 'shipping_tax', 0);
        }
        $rows = [];
        foreach ($rateLines as $rateKey => $rateLine) {
            $rid         = (int) Arr::get($rateLine, 'rate_id', $rateKey);
            $ratePercent = (float) Arr::get($rateLine, 'rate_percent', 0);
            $shipForRate = isset($shippingByRate[$rid]) ? (int) $shippingByRate[$rid] : 0;
            unset($shippingByRate[$rid]);
            $productTax  = (int) Arr::get($rateLine, $taxAmountKey, 0);
            $combinedTax = $productTax + $shipForRate;
            $mapEntry    = isset($rateBaseMap[$rid]) ? $rateBaseMap[$rid] : null;
            if ($mapEntry && abs($mapEntry['tax'] - $productTax) <= 1) {
                // Exact net base from item-level data. Shipping has no stored per-rate
                // base, so its (small) contribution is still derived from its tax amount.
                $base = (int) round($mapEntry['base']);
                if ($shipForRate > 0 && $ratePercent > 0) {
                    $base += (int) round($shipForRate * 100 / $ratePercent);
                }
            } elseif ($ratePercent > 0) {
                // No trustworthy item data (legacy order / fee tax folded into the row):
                // approximate the base from the rounded tax.
                $base = (int) round($combinedTax * 100 / $ratePercent);
            } else {
                $base = (int) Arr::get($rateLine, 'taxable_amount', 0);
            }
            $label = (string) Arr::get($rateLine, 'rate_label', Arr::get($rateLine, 'label', ''));
            $rows[] = [
                'label'     => $label,
                'base'      => $base,
                'tax'       => $combinedTax,
                'inclusive' => !empty($rateLine['inclusive']),
            ];
        }
        foreach ($shippingByRate as $sid => $shAmount) {       // shipping-only rates
            if ($shAmount <= 0) {
                continue;
            }
            $shLine = null;
            foreach ($shippingLines as $cand) {
                if ((int) Arr::get($cand, 'rate_id', 0) === (int) $sid) {
                    $shLine = $cand;
                    break;
                }
            }
            $ratePercent = (float) Arr::get($shLine, 'rate_percent', 0);
            $base        = $ratePercent > 0 ? (int) round($shAmount * 100 / $ratePercent) : 0;
            $rows[] = [
                'label'     => (string) Arr::get($shLine, 'rate_label', Arr::get($shLine, 'label', '')),
                'base'      => $base,
                'tax'       => $shAmount,
                'inclusive' => (bool) $isShippingInclusive,
            ];
        }
        return $rows;
    }

    /**
     * Rebuild the per-rate breakdown rows for a reverse-charge order.
     *
     * Under reverse charge the stored tax lines (and shipping tax lines) are zeroed
     * at order placement, but the original per-rate amounts survive in item-level
     * `line_meta.tax_config.rates`, and the pre-zeroing shipping lines survive in the
     * rate-meta snapshot (`vat_reverse.reverse_charge_shipping_tax_lines`). This
     * restores both and folds shipping into the rate rows so post-order surfaces can
     * render the same "Tax breakdown by rate" table as a normal order.
     *
     * Returns [] when no per-rate data is recoverable (old orders without line-level
     * tax data) — callers must fall back to the simplified reverse-charge box.
     *
     * @return array Same row shape as buildFoldedRateRows().
     */
    public static function buildReverseChargeRateRows(Order $order)
    {
        $order->loadMissing(['orderTaxRates', 'order_items']);

        $primaryRate = $order->orderTaxRates ? $order->orderTaxRates->first() : null;
        $meta        = ($primaryRate && is_array($primaryRate->meta)) ? $primaryRate->meta : [];

        // Fixed-mode reverse charge leaves tax-inclusive prices (and their embedded
        // VAT) untouched — only dynamic mode reverses the inclusive portion. Exclude
        // inclusive lines in fixed mode so the rate rows sum to the reversed total.
        $rcNonDynamic = Arr::get($meta, 'reverse_charge_price_mode', 'fixed') !== 'dynamic';

        $items = [];
        if ($order->order_items) {
            foreach ($order->order_items as $item) {
                if ($item->payment_type === 'fee') {
                    continue;
                }
                $items[] = $item;
            }
        }
        $rateBaseMap = self::computeRateBaseMap($items, $rcNonDynamic);

        // Pre-zeroing shipping tax lines snapshot (may be [] on older orders).
        $shippingLines = (array) Arr::get($meta, 'vat_reverse.reverse_charge_shipping_tax_lines', []);

        // Stored rate lines are zeroed under reverse charge — restore each rate's
        // amount from the item-level map. Rates without a map entry have nothing
        // reversible (fixed-mode inclusive-only rates / legacy rows) and are dropped.
        $restoredLines = [];
        foreach ($order->getDisplayTaxLines() as $rateKey => $rateLine) {
            $rid = (int) Arr::get($rateLine, 'rate_id', $rateKey);
            if (!isset($rateBaseMap[$rid])) {
                continue;
            }
            $rateLine['order_tax'] = (int) round($rateBaseMap[$rid]['tax']);
            $restoredLines[]       = $rateLine;
        }

        if (empty($restoredLines) && empty($shippingLines)) {
            return [];
        }

        return self::buildFoldedRateRows(
            $restoredLines,
            $shippingLines,
            'order_tax',
            self::isShippingTaxInclusive($order),
            $rateBaseMap
        );
    }

    /**
     * Checkout-side variant: determines whether the shipping tax is inclusive of the
     * shipping price. Shipping always follows the store-level tax mode, so this returns
     * true only when store_tax_behavior === 2 (inclusive). Per-product inclusive flags
     * are intentionally NOT consulted — they do not govern shipping.
     */
    public static function isShippingTaxInclusiveFromTaxData(array $taxData)
    {
        $storeBehavior = (int) Arr::get($taxData, 'store_tax_behavior', Arr::get($taxData, 'tax_behavior', 2));

        return $storeBehavior === 2;
    }

    protected static function getTaxSettings(): array
    {
        return (array) get_option('fluent_cart_tax_configuration_settings', []);
    }

    public static function getTaxDisplayMode(): string
    {
        // Backward compat: legacy stored values ('both', 'label', 'tooltip', or anything
        // else) all collapse to 'itemized'. Only an explicit 'simplified' stays simplified.
        $mode = Arr::get(self::getTaxSettings(), 'checkout_tax_breakdown_display', 'itemized');
        return $mode === 'simplified' ? 'simplified' : 'itemized';
    }

    protected static function getTaxDisplayLabel(): string
    {
        $label = trim((string) Arr::get(self::getTaxSettings(), 'tax_display_label', ''));
        return $label !== '' ? $label : __('Tax', 'fluent-cart');
    }

    protected static function getPriceSuffixIncluded(): string
    {
        return (string) Arr::get(self::getTaxSettings(), 'price_suffix_included', '');
    }

    public static function buildSimpleLine(array $summary): array
    {
        $label   = self::getTaxDisplayLabel();
        $isRc    = !empty($summary['isReverseCharge']);
        $total   = (int) Arr::get($summary, 'totalOrderTax', 0);
        $payable = (int) Arr::get($summary, 'payableTax', 0);
        $folded  = (array) Arr::get($summary, 'foldedRateLines', []);
        $hasDetails = !empty($folded) || $total > 0 || $isRc;

        if ($isRc) {
            $valueType = 'reverse_charge';
            $value     = __('Reverse charge', 'fluent-cart');
        } elseif ($payable === 0 && $total > 0) {
            $valueType = 'included';
            $suffix    = self::getPriceSuffixIncluded();
            if ($suffix === '') {
                $suffix = __('(incl.)', 'fluent-cart');
            }
            /* translators: %1$s: formatted tax amount, %2$s: inclusive suffix */
            $value = sprintf(__('%1$s %2$s', 'fluent-cart'), html_entity_decode(Helper::toDecimal($total), ENT_QUOTES, 'UTF-8'), $suffix);
        } else {
            $valueType = 'amount';
            $value     = html_entity_decode(Helper::toDecimal($payable), ENT_QUOTES, 'UTF-8');
        }

        return [
            'label'      => $label,
            'value'      => $value,
            'valueType'  => $valueType,
            'hasDetails' => $hasDetails,
        ];
    }
}
