<?php

namespace FluentCart\App\Services\Tax;

use FluentCart\App\Models\Order;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Services\Money;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;
use FluentCart\Framework\Support\Arr;

/**
 * The tax rate rows a renewal charges, built from the parent order.
 */
class RenewalTaxRates
{
    /**
     * Each row carries the subscription's recurring tax for its rate, plus the
     * shipping tax when the subscription itself ships. Rates the parent had only
     * for one-time items, fees or the signup fee are left out. Subscriptions
     * bought before recurring tax was stored per rate keep the parent's rows.
     *
     * @param Order       $parentOrder
     * @param object|null $parentOrderItem The parent's subscription item.
     * @param int         $recurringTax    The renewal's item tax; the rows are fitted to it.
     * @param string|null $currency        The renewal's currency.
     * @return object[] Each: tax_rate_id, order_tax, shipping_tax, total_tax, meta, from_recurring.
     */
    public static function fromParent(Order $parentOrder, $parentOrderItem, $recurringTax, $currency = null)
    {
        $itemRates = [];
        $lineMeta = $parentOrderItem ? (array) $parentOrderItem->line_meta : [];
        foreach ((array) Arr::get($lineMeta, 'tax_config.rates', []) as $rate) {
            if (is_array($rate) && array_key_exists('recurring_tax', $rate)) {
                $itemRates[(int) Arr::get($rate, 'rate_id', 0)] = $rate;
            }
        }
        $ships = $parentOrderItem && $parentOrderItem->fulfillment_type === 'physical';

        // An empty rate list means no recurring tax; rates without recurring_tax are older data.
        $configRates = Arr::get($lineMeta, 'tax_config.rates');
        $useRecurring = is_array($configRates) && (!$configRates || $itemRates);
        // Reverse charge keeps recurring_tax on the item but charges no tax.
        if ($parentOrder->isReverseChargeTaxOrder()) {
            $useRecurring = false;
        }

        $rows = [];
        $firstMeta = null;
        foreach ($parentOrder->orderTaxRates as $taxRate) {
            if ($firstMeta === null) {
                $firstMeta = (array) $taxRate->meta;
            }
            $row = (object) [
                'tax_rate_id'  => $taxRate->tax_rate_id,
                'order_tax'    => (int) $taxRate->order_tax,
                'shipping_tax' => (int) $taxRate->shipping_tax,
                'meta'         => $taxRate->meta,
                'from_recurring' => $useRecurring,
            ];

            if ($useRecurring) {
                $rate = Arr::get($itemRates, (int) $taxRate->tax_rate_id);
                $row->order_tax = $rate ? Helper::roundCent(Arr::get($rate, 'recurring_tax', 0)) : 0;
                $meta = is_array($row->meta) ? $row->meta : [];
                if (!$ships) {
                    $row->shipping_tax = 0;
                    unset($meta['shipping_taxable_amount']);
                }
                // The renewal bills only the subscription item, never a mix.
                $meta['inclusive'] = (bool) Arr::get($lineMeta, 'tax_config.inclusive', false);
                $meta['is_mixed_inclusive'] = false;
                if ($rate && isset($rate['recurring_taxable_amount'])) {
                    $meta['taxable_amount'] = Helper::roundCent($rate['recurring_taxable_amount']);
                } elseif (!self::baseMatchesTax($meta, $row->order_tax, $currency)) {
                    // Older subscriptions: the first order's base may cover other items.
                    unset($meta['taxable_amount']);
                }
                $row->meta = $meta;
                if ($row->order_tax + $row->shipping_tax <= 0) {
                    continue;
                }
            }

            $row->total_tax = $row->order_tax + $row->shipping_tax;
            $rows[] = $row;
        }

        if ($useRecurring) {
            self::fitItemTax($rows, (int) $recurringTax, $currency);
            $rows = array_values(array_filter($rows, function ($row) {
                return $row->total_tax > 0;
            }));
        }

        if ($useRecurring && !$rows && $firstMeta !== null) {
            // The zero-tax row records that tax ran for this order.
            $rows[] = (object) [
                'tax_rate_id'  => 0,
                'order_tax'    => 0,
                'shipping_tax' => 0,
                'total_tax'    => 0,
                'from_recurring' => true,
                'meta'         => Arr::only($firstMeta, ['tax_country', 'store_vat_number', 'reverse_charge_applied']),
            ];
        }

        return $rows;
    }

    /**
     * Makes a renewal's columns add up like its tax rows, without changing its
     * total. Refunds rely on the line and shipping charged amounts adding up to
     * that total, and shipping tax follows the store setting: added tax is
     * charged as shipping, included tax stays inside the line.
     *
     * @param Order $order The renewal, after its tax rows and item are written.
     */
    public static function reconcileOrder(Order $order)
    {
        $rows = $order->orderTaxRates()->get();
        $itemTax = (int) $rows->sum('order_tax');
        $shippingTax = (int) $rows->sum('shipping_tax');
        $taxTotal = (int) $order->tax_total;
        // Rows copied from an older first order do not describe this renewal.
        if ($shippingTax <= 0 || ($itemTax !== $taxTotal && $itemTax + $shippingTax !== $taxTotal)) {
            return;
        }

        $order->tax_total = $itemTax;
        $order->shipping_tax = $shippingTax;

        $item = OrderItem::query()->where('order_id', $order->id)->first();
        $inclusive = TaxSummaryHelper::isShippingTaxInclusive($order);
        $lineTotal = (int) $order->total_amount - ($inclusive ? 0 : $shippingTax);
        $order->subtotal = $lineTotal + (int) $item->discount_total - $itemTax;
        $order->save();

        $item->subtotal = $order->subtotal;
        $item->tax_amount = $itemTax;
        $item->line_total = $lineTotal;
        $item->save();
    }

    /**
     * Refits a pending renewal's rows after an admin changes its amounts.
     *
     * @param Order $order The renewal, after its columns and item are updated.
     */
    public static function refreshAfterEdit(Order $order)
    {
        $rows = $order->orderTaxRates()->get()->all();
        self::fitItemTax($rows, (int) $order->tax_total, $order->currency);
        foreach ($rows as $row) {
            $row->save();
        }
        self::reconcileOrder($order);
    }

    /**
     * Makes the rows' item tax add up to the renewal's. Rounding goes to the last
     * taxed rows; a larger gap means the rates are stale (the price changed after
     * purchase), so the tax and its base scale together. Shipping tax stays as is.
     */
    private static function fitItemTax(array $rows, $target, $currency)
    {
        $current = array_sum(array_map(function ($row) {
            return (int) $row->order_tax;
        }, $rows));
        $unit = $currency && Money::getDecimals($currency) === 0 ? 100 : 1;

        if ($current > 0 && abs($target - $current) > count($rows) * $unit) {
            foreach ($rows as $row) {
                $share = (int) $row->order_tax;
                $meta = $row->meta;
                if (is_array($meta) && isset($meta['taxable_amount'])) {
                    $meta['taxable_amount'] = (int) round($meta['taxable_amount'] * $target / $current);
                    $row->meta = $meta;
                }
                $row->order_tax = (int) round($share * $target / $current);
                $row->total_tax = (int) $row->order_tax + (int) $row->shipping_tax;
            }
        }

        $diff = $target - array_sum(array_map(function ($row) {
            return (int) $row->order_tax;
        }, $rows));
        if ($diff === 0) {
            return;
        }
        foreach (array_reverse($rows) as $row) {
            $tax = (int) $row->order_tax;
            if ($tax <= 0) {
                continue;
            }
            $applied = max($diff, -$tax);
            $row->order_tax = $tax + $applied;
            $row->total_tax = (int) $row->order_tax + (int) $row->shipping_tax;
            $diff -= $applied;
            if ($diff === 0) {
                return;
            }
        }
    }

    private static function baseMatchesTax(array $meta, $tax, $currency)
    {
        $taxable = (int) Arr::get($meta, 'taxable_amount', 0);
        $net = !empty($meta['inclusive']) ? $taxable - (int) $tax : $taxable;

        return TaxSummaryHelper::baseMatchesTax($net, (float) Arr::get($meta, 'rate_percent', 0), $tax, $currency);
    }
}
