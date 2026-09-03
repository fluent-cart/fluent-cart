<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;

class TaxRenderer
{
    protected $module;

    public function __construct(TaxModule $module)
    {
        $this->module = $module;
    }

    public function renderTaxRow($cart, $atts = ''): void
    {
        if ($this->getCheckoutTaxBreakdownDisplayMode() === 'simplified') {
            return;
        }

        $taxAmount         = (int) Arr::get($cart->checkout_data, 'tax_data.tax_total', 0);
        $reversedTaxAmount = (int) Arr::get($cart->checkout_data, 'tax_data.reverse_charge_tax_total', 0);
        $isReverseCharge   = $this->module->isReverseChargeCheckout($cart->checkout_data);
        $taxLines = Arr::get($cart->checkout_data, 'tax_data.tax_lines', []);
        $taxCountry = Arr::get($cart->checkout_data, 'tax_data.tax_country', '');
        $taxLabel = Arr::get($taxLines, '0.label', '');

        if (!$taxLabel) {
            $taxLabel = $taxCountry ? TaxModule::getCountryTaxTitle($taxCountry) : __('Tax', 'fluent-cart');
        }
        ?>
        <li <?php echo $atts; ?>>
            <span class="fct_summary_label">
                <?php echo esc_html($taxLabel); ?>
            </span>
            <span class="fct_summary_value">
                <?php if ($isReverseCharge && $taxAmount === 0) : ?>
                    <?php
                    /* translators: %1$s: formatted reversed tax amount */
                    echo esc_html(sprintf(__('Tax reversed: %1$s', 'fluent-cart'), Helper::toDecimal($reversedTaxAmount)));
                    ?>
                <?php else : ?>
                    <?php echo esc_html(Helper::toDecimal($taxAmount)); ?>
                <?php endif; ?>
            </span>
        </li>
        <?php
    }

    public function renderShippingTaxRow($cart, $atts = ''): string
    {
        if ($this->getCheckoutTaxBreakdownDisplayMode() === 'simplified') {
            return '';
        }

        $shippingTax     = (int) Arr::get($cart->checkout_data, 'tax_data.shipping_tax', 0);
        $isReverseCharge = $this->module->isReverseChargeCheckout($cart->checkout_data);
        $rcShippingTax   = (int) Arr::get($cart->checkout_data, 'tax_data.reverse_charge_shipping_tax', 0);

        if ($shippingTax <= 0 && (!$isReverseCharge || $rcShippingTax <= 0)) {
            return '';
        }

        $displayAmount = $isReverseCharge ? $rcShippingTax : $shippingTax;
        $storeBehavior = (int) Arr::get($cart->checkout_data, 'tax_data.store_tax_behavior',
            Arr::get($cart->checkout_data, 'tax_data.tax_behavior', 2));
        $isInclusive = $storeBehavior === 2;
        ?>
        <li data-fct-shipping-tax-row <?php echo $atts; ?>>
            <span class="fct_summary_label">
                <?php if ($isInclusive) : ?>
                    <?php echo esc_html__('Shipping Tax (Included)', 'fluent-cart'); ?>
                <?php else : ?>
                    <?php echo esc_html__('Shipping Tax (Excluded)', 'fluent-cart'); ?>
                <?php endif; ?>
            </span>
            <span class="fct_summary_value"<?php echo $isReverseCharge ? ' style="text-decoration:line-through;opacity:0.6;"' : ''; ?>>
                <?php echo esc_html(Helper::toDecimal($displayAmount)); ?>
            </span>
        </li>
        <?php
        return '';
    }

    public function renderTaxSummaryBox($cart): void
    {
        $taxData           = Arr::get($cart->checkout_data, 'tax_data', []);
        $taxTotal          = (int) Arr::get($taxData, 'tax_total', 0);
        $exclusiveTaxTotal = (int) Arr::get($taxData, 'exclusive_tax_total', $taxTotal);
        $feeTaxLines       = (array) Arr::get($taxData, 'fee_tax_lines', []);
        $shippingTax       = (int) Arr::get($taxData, 'shipping_tax', 0);
        $isReverseCharge         = $this->module->isReverseChargeCheckout($cart->checkout_data);
        $reversedTaxTotalDisplay = (int) Arr::get($taxData, 'reverse_charge_tax_total', 0);

        $taxRateLines     = (array) Arr::get($taxData, 'tax_lines', []);
        $shippingTaxLines = (array) Arr::get($taxData, 'shipping_tax_lines', []);

        $inclusiveFeeTax = 0;
        $exclusiveFeeTax = 0;
        foreach ($feeTaxLines as $feeTaxLine) {
            if (!empty($feeTaxLine['inclusive'])) {
                $inclusiveFeeTax += (int) Arr::get($feeTaxLine, 'tax_amount', 0);
            } else {
                $exclusiveFeeTax += (int) Arr::get($feeTaxLine, 'tax_amount', 0);
            }
        }
        // Fallback: if no fee_tax_lines (old cart data), use aggregate fee_tax as exclusive
        if (empty($feeTaxLines)) {
            $exclusiveFeeTax = (int) Arr::get($taxData, 'fee_tax', 0);
        }

        $inclusiveTax        = max(0, $taxTotal - $exclusiveTaxTotal - $exclusiveFeeTax - $inclusiveFeeTax);
        $productExclusiveTax = max(0, $exclusiveTaxTotal);

        $isShippingInclusive = TaxSummaryHelper::isShippingTaxInclusiveFromTaxData(
            is_array($taxData) ? $taxData : []
        );

        $payableTax    = $productExclusiveTax + $exclusiveFeeTax + ($isShippingInclusive ? 0 : $shippingTax);
        $totalOrderTax = $inclusiveTax + $inclusiveFeeTax + ($isShippingInclusive ? $shippingTax : 0) + $payableTax;

        $feeRows  = TaxSummaryHelper::buildFeeTaxLineRows($feeTaxLines);
        $feeCount = count($feeRows);
        if (empty($feeTaxLines) && (int) Arr::get($taxData, 'fee_tax', 0) > 0) {
            $feeCount = 1;
        }
        $productTaxRowCount = !empty($taxRateLines)
            ? count($taxRateLines)
            : (int) ($inclusiveTax > 0) + (int) ($productExclusiveTax > 0);
        $rowCount = $productTaxRowCount + $feeCount + (int) ($shippingTax > 0);
        $shouldShowBreakdown = !empty($taxRateLines)
            || !empty($shippingTaxLines)
            || $rowCount >= 2
            || ($rowCount === 1 && !($payableTax > 0 || $inclusiveTax > 0 || $inclusiveFeeTax > 0));

        // Build folded per-rate rows via shared helper. Cart tax_lines carry only `label`
        // (no percent), so we pre-augment each line with `rate_label` = "Label (X%)" so the
        // helper picks it up and produces the same display as the old inline block.
        $foldedSource = [];
        foreach ($taxRateLines as $rateKey => $rateLine) {
            $ratePercent = (float) Arr::get($rateLine, 'rate_percent', 0);
            $rateLine['rate_label'] = (string) Arr::get($rateLine, 'label', '');
            if ($ratePercent > 0) {
                $rateLine['rate_label'] .= ' (' . Helper::formatTaxRatePercent($ratePercent) . '%)';
            }
            $foldedSource[$rateKey] = $rateLine;
        }
        // Fixed-mode reverse charge leaves tax-inclusive prices (and their embedded VAT)
        // untouched — only dynamic mode reverses the inclusive portion. Exclude inclusive
        // lines from the map in fixed mode so the rate rows sum to the reversed total.
        $rcNonDynamic = $isReverseCharge
            && Arr::get($taxData, 'reverse_charge_price_mode', 'fixed') !== 'dynamic';
        $rateBaseMap  = TaxSummaryHelper::computeRateBaseMap((array) ($cart->cart_data ?: []), $rcNonDynamic);
        if ($isReverseCharge) {
            // Under reverse charge the stored tax_lines amounts are zeroed at calc time,
            // but the original per-rate amounts survive in item line_meta. Restore them
            // (and the pre-zeroing shipping lines) so the box renders the same
            // breakdown-by-rate table as a normal order.
            foreach ($foldedSource as $rateKey => &$foldedLine) {
                $rid = (int) Arr::get($foldedLine, 'rate_id', $rateKey);
                if (isset($rateBaseMap[$rid])) {
                    $foldedLine['tax_amount'] = (int) round($rateBaseMap[$rid]['tax']);
                }
            }
            unset($foldedLine);
            if ($rcNonDynamic) {
                // Rates present only on inclusive lines have nothing reversible in
                // fixed mode — drop them instead of rendering a zero row.
                foreach (array_keys($foldedSource) as $rateKey) {
                    $rid = (int) Arr::get($foldedSource[$rateKey], 'rate_id', $rateKey);
                    if (!isset($rateBaseMap[$rid])) {
                        unset($foldedSource[$rateKey]);
                    }
                }
            }
            $shippingTaxLines = (array) Arr::get($taxData, 'reverse_charge_shipping_tax_lines', []);
        }
        $checkoutRateRows = TaxSummaryHelper::buildFoldedRateRows(
            $foldedSource, $shippingTaxLines, 'tax_amount', $isShippingInclusive, $rateBaseMap
        );
        // Inclusive shipping tax follows the store global tax mode — when shipping is
        // priced inclusive its tax is baked into the shipping price, so it counts as
        // "of which included in prices". Keeps includedInPrices + payableTax === totalOrderTax.
        $includedInPrices = $inclusiveTax + $inclusiveFeeTax + ($isShippingInclusive ? $shippingTax : 0);

        $displayMode = $this->getCheckoutTaxBreakdownDisplayMode();
        $isSimplified = $displayMode === 'simplified';
        $simpleLabel  = $this->getTaxDisplayLabel();
        if ($isReverseCharge) {
            $simpleValue = __('Reverse charge', 'fluent-cart');
        } elseif ($payableTax === 0 && $totalOrderTax > 0) {
            $suffix = (string) Arr::get($this->module->getSettings(), 'price_suffix_included', '');
            if ($suffix === '') {
                $suffix = __('(incl.)', 'fluent-cart');
            }
            /* translators: %1$s: formatted tax amount, %2$s: inclusive suffix */
            $simpleValue = sprintf(__('%1$s %2$s', 'fluent-cart'), html_entity_decode(Helper::toDecimal($totalOrderTax), ENT_QUOTES, 'UTF-8'), $suffix);
        } else {
            $simpleValue = html_entity_decode(Helper::toDecimal($payableTax), ENT_QUOTES, 'UTF-8');
        }

        $tooltipId = 'fct-tax-summary-tooltip-' . Helper::getUidSerial();
        ?>
        <li class="fct_tax_summary_li" data-fct-tax-summary>
<?php if ($isSimplified) : ?>
            <style>
                .fct_tax_simple_details > summary { list-style: none; cursor: pointer; }
                .fct_tax_simple_details > summary::-webkit-details-marker { display: none; }
                .fct_tax_simple_toggle { color: #2563eb; text-decoration: none; font-size: 11px; }
                .fct_tax_simple_label { color: inherit; }
            </style>
            <details class="fct_tax_simple_details">
                <summary class="fct_tax_simple_summary" style="display:flex;justify-content:space-between;align-items:center;gap:8px;cursor:pointer;list-style:none;">
                    <span class="fct_tax_simple_left">
                        <span class="fct_tax_simple_label"><?php echo esc_html($simpleLabel); ?></span>
                        <span class="fct_tax_simple_toggle"><?php esc_html_e('See details', 'fluent-cart'); ?> &#9662;</span>
                    </span>
                    <span class="fct_tax_simple_value"><?php echo esc_html($simpleValue); ?></span>
                </summary>
<?php endif; ?>
            <div class="fct_tax_summary_box">
                <div class="fct_tax_summary_header">
                    <span class="fct_tax_summary_heading">
                        <?php echo !empty($checkoutRateRows)
                            ? esc_html__('Tax breakdown by rate', 'fluent-cart')
                            : esc_html__('TAX', 'fluent-cart'); ?>
                    </span>
                    <div class="fct_item_tax_hint">
                        <button
                            type="button"
                            class="fct_item_tax_hint_button"
                            aria-label="<?php esc_attr_e('Tax information', 'fluent-cart'); ?>"
                            aria-describedby="<?php echo esc_attr($tooltipId); ?>"
                        >
                            <span aria-hidden="true">i</span>
                        </button>
                        <div class="fct_item_tax_tooltip" id="<?php echo esc_attr($tooltipId); ?>" role="tooltip">
                            <span class="fct_item_tax_tooltip_heading">
                                <?php esc_html_e('About your tax', 'fluent-cart'); ?>
                            </span>
                            <?php if ($isReverseCharge) : ?>
                            <span class="fct_item_tax_tooltip_line">
                                <?php esc_html_e('Tax has been reversed for this order.', 'fluent-cart'); ?>
                            </span>
                            <?php else : ?>
                                <?php if ($payableTax > 0) : ?>
                                <span class="fct_item_tax_tooltip_line">
                                    <?php esc_html_e('"Total payable tax" is added on top of listed prices.', 'fluent-cart'); ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($inclusiveTax > 0) : ?>
                                <span class="fct_item_tax_tooltip_line">
                                    <?php esc_html_e('"Included in item prices" is already built into product prices.', 'fluent-cart'); ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($payableTax === 0 && $inclusiveTax === 0) : ?>
                                <span class="fct_item_tax_tooltip_line">
                                    <?php esc_html_e('No tax applies to this order.', 'fluent-cart'); ?>
                                </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="fct_tax_summary_rows">
                    <?php if (!empty($checkoutRateRows)) : ?>
                    <div class="fct_tax_summary_row fct_tax_summary_row--head" style="display:flex;justify-content:space-between;gap:8px;">
                        <span style="flex:1;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:#94a3b8;">
                            <?php esc_html_e('Rate', 'fluent-cart'); ?>
                        </span>
                        <span style="min-width:88px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:#94a3b8;">
                            <?php esc_html_e('Taxable base', 'fluent-cart'); ?>
                        </span>
                        <span style="min-width:64px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:#94a3b8;">
                            <?php esc_html_e('Tax', 'fluent-cart'); ?>
                        </span>
                    </div>
                    <?php foreach ($checkoutRateRows as $checkoutRateRow) : ?>
                    <div class="fct_tax_summary_row<?php echo !empty($checkoutRateRow['inclusive']) ? ' fct_tax_summary_row--muted' : ''; ?>" style="display:flex;justify-content:space-between;gap:8px;">
                        <span class="fct_tax_summary_row_label" style="flex:1;">
                            <?php echo esc_html($checkoutRateRow['label']); ?>
                        </span>
                        <span style="min-width:88px;text-align:right;color:#94a3b8;">
                            <?php echo esc_html(Helper::toDecimal((int) $checkoutRateRow['base'])); ?>
                        </span>
                        <span class="fct_tax_summary_row_amount" style="min-width:64px;text-align:right;">
                            <?php echo esc_html(Helper::toDecimal((int) $checkoutRateRow['tax'])); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($isReverseCharge) : ?>
                        <?php if (empty($checkoutRateRows)) : ?>
                            <?php
                                $rcShippingDisplay  = (int) Arr::get($taxData, 'reverse_charge_shipping_tax', 0);
                                $rcInclusiveAdj     = (int) Arr::get($taxData, 'reverse_charge_inclusive_adjustment', 0);
                                $rcExclusiveNonShip = max(0, $reversedTaxTotalDisplay - $rcShippingDisplay - $rcInclusiveAdj);
                                $rcBreakdownCount   = (int) ($rcInclusiveAdj > 0) + (int) ($rcExclusiveNonShip > 0) + (int) ($rcShippingDisplay > 0);
                            ?>
                            <?php if ($rcBreakdownCount >= 2) : ?>
                                <?php if ($rcInclusiveAdj > 0) : ?>
                                <div class="fct_tax_summary_row fct_tax_summary_row--muted">
                                    <span class="fct_tax_summary_row_label">
                                        <?php esc_html_e('Included in item prices', 'fluent-cart'); ?>
                                    </span>
                                    <span class="fct_tax_summary_row_amount" style="text-decoration:line-through;opacity:0.6;">
                                        <?php echo esc_html(Helper::toDecimal($rcInclusiveAdj)); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php if ($rcExclusiveNonShip > 0) : ?>
                                <div class="fct_tax_summary_row">
                                    <span class="fct_tax_summary_row_label">
                                        <?php esc_html_e('Added on products', 'fluent-cart'); ?>
                                    </span>
                                    <span class="fct_tax_summary_row_amount" style="text-decoration:line-through;opacity:0.6;">
                                        <?php echo esc_html(Helper::toDecimal($rcExclusiveNonShip)); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php if ($rcShippingDisplay > 0) : ?>
                                <div class="fct_tax_summary_row<?php echo $isShippingInclusive ? ' fct_tax_summary_row--muted' : ''; ?>">
                                    <span class="fct_tax_summary_row_label">
                                        <?php echo $isShippingInclusive ? esc_html__('Included in shipping prices', 'fluent-cart') : esc_html__('Added on shipping', 'fluent-cart'); ?>
                                    </span>
                                    <span class="fct_tax_summary_row_amount" style="text-decoration:line-through;opacity:0.6;">
                                        <?php echo esc_html(Helper::toDecimal($rcShippingDisplay)); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            <?php elseif ($rcShippingDisplay > 0) : ?>
                            <div class="fct_tax_summary_row fct_tax_summary_row--muted">
                                <span class="fct_tax_summary_row_label">
                                    <?php echo $isShippingInclusive ? esc_html__('Included in shipping prices', 'fluent-cart') : esc_html__('Added on shipping', 'fluent-cart'); ?>
                                </span>
                                <span class="fct_tax_summary_row_amount" style="text-decoration:line-through;opacity:0.6;">
                                    <?php echo esc_html(Helper::toDecimal($rcShippingDisplay)); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="fct_tax_summary_row fct_tax_summary_row--total">
                            <span class="fct_tax_summary_row_label">
                                <?php esc_html_e('VAT reversed', 'fluent-cart'); ?>
                            </span>
                            <span class="fct_tax_summary_row_amount">
                                <?php echo esc_html(Helper::toDecimal($reversedTaxTotalDisplay)); ?>
                            </span>
                        </div>
                    <?php else : ?>
                        <?php if (empty($checkoutRateRows) && $inclusiveTax > 0 && $shouldShowBreakdown) : ?>
                        <div class="fct_tax_summary_row fct_tax_summary_row--muted">
                            <span class="fct_tax_summary_row_label">
                                <?php esc_html_e('Included in item prices', 'fluent-cart'); ?>
                            </span>
                            <span class="fct_tax_summary_row_amount">
                                <?php echo esc_html(Helper::toDecimal($inclusiveTax)); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if (empty($checkoutRateRows) && $productExclusiveTax > 0 && $shouldShowBreakdown) : ?>
                        <div class="fct_tax_summary_row">
                            <span class="fct_tax_summary_row_label">
                                <?php esc_html_e('Added on products', 'fluent-cart'); ?>
                            </span>
                            <span class="fct_tax_summary_row_amount">
                                <?php echo esc_html(Helper::toDecimal($productExclusiveTax)); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if (empty($checkoutRateRows) && $shouldShowBreakdown) : ?>
                        <?php foreach ($feeRows as $feeRow) : ?>
                        <div class="fct_tax_summary_row<?php echo $feeRow['inclusive'] ? ' fct_tax_summary_row--muted' : ''; ?>">
                            <span class="fct_tax_summary_row_label">
                                <?php echo esc_html($feeRow['display_label']); ?>
                            </span>
                            <span class="fct_tax_summary_row_amount">
                                <?php echo esc_html(Helper::toDecimal($feeRow['tax_amount'])); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (empty($checkoutRateRows) && empty($feeTaxLines) && (int) Arr::get($taxData, 'fee_tax', 0) > 0 && $shouldShowBreakdown) : ?>
                        <div class="fct_tax_summary_row">
                            <span class="fct_tax_summary_row_label">
                                <?php esc_html_e('Added on fees', 'fluent-cart'); ?>
                            </span>
                            <span class="fct_tax_summary_row_amount">
                                <?php echo esc_html(Helper::toDecimal((int) Arr::get($taxData, 'fee_tax', 0))); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if (empty($checkoutRateRows) && $shippingTax > 0 && $shouldShowBreakdown) : ?>
                        <?php if (!empty($shippingTaxLines)) : ?>
                        <?php foreach ($shippingTaxLines as $shippingTaxLine) : ?>
                        <div class="fct_tax_summary_row<?php echo $isShippingInclusive ? ' fct_tax_summary_row--muted' : ''; ?>">
                            <span class="fct_tax_summary_row_label">
                                <?php echo esc_html($shippingTaxLine['label']); ?>
                            </span>
                            <span class="fct_tax_summary_row_amount">
                                <?php echo esc_html(Helper::toDecimal((int) Arr::get($shippingTaxLine, 'shipping_tax', 0))); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                        <?php else : ?>
                        <div class="fct_tax_summary_row<?php echo $isShippingInclusive ? ' fct_tax_summary_row--muted' : ''; ?>">
                            <span class="fct_tax_summary_row_label">
                                <?php if ($isShippingInclusive) : ?>
                                    <?php esc_html_e('Included in shipping prices', 'fluent-cart'); ?>
                                <?php else : ?>
                                    <?php esc_html_e('Added on shipping', 'fluent-cart'); ?>
                                <?php endif; ?>
                            </span>
                            <span class="fct_tax_summary_row_amount">
                                <?php echo esc_html(Helper::toDecimal($shippingTax)); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($checkoutRateRows)) : ?>
                            <div class="fct_tax_summary_row fct_tax_summary_row--total">
                                <span class="fct_tax_summary_row_label"><?php esc_html_e('Total tax', 'fluent-cart'); ?></span>
                                <span class="fct_tax_summary_row_amount"><?php echo esc_html(Helper::toDecimal($totalOrderTax)); ?></span>
                            </div>
                            <?php if ($includedInPrices > 0) : ?>
                            <div class="fct_tax_summary_row fct_tax_summary_row--muted">
                                <span class="fct_tax_summary_row_label"><?php esc_html_e('of which included in prices', 'fluent-cart'); ?></span>
                                <span class="fct_tax_summary_row_amount"><?php echo esc_html(Helper::toDecimal($includedInPrices)); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($payableTax > 0 && $includedInPrices > 0) : ?>
                            <div class="fct_tax_summary_row fct_tax_summary_row--total">
                                <span class="fct_tax_summary_row_label"><?php esc_html_e('Payable now (added)', 'fluent-cart'); ?></span>
                                <span class="fct_tax_summary_row_amount"><?php echo esc_html(Helper::toDecimal($payableTax)); ?></span>
                            </div>
                            <?php endif; ?>
                        <?php else : ?>
                            <?php if ($payableTax > 0) : ?>
                            <div class="fct_tax_summary_row fct_tax_summary_row--total">
                                <span class="fct_tax_summary_row_label"><?php esc_html_e('Total payable tax', 'fluent-cart'); ?></span>
                                <span class="fct_tax_summary_row_amount"><?php echo esc_html(Helper::toDecimal($payableTax)); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($inclusiveTax > 0 || $inclusiveFeeTax > 0) : ?>
                            <div class="fct_tax_summary_row fct_tax_summary_row--muted">
                                <span class="fct_tax_summary_row_label"><?php esc_html_e('Total tax in this order', 'fluent-cart'); ?></span>
                                <span class="fct_tax_summary_row_amount"><?php echo esc_html(Helper::toDecimal($totalOrderTax)); ?></span>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
<?php if ($isSimplified) : ?>
            </details>
<?php endif; ?>
        </li>
        <?php
    }

    public function renderCheckoutLineItemTaxLabel($data): void
    {
        $mode = $this->getCheckoutTaxBreakdownDisplayMode();
        if ($mode === 'simplified') {
            return;
        }

        $item = Arr::get($data, 'item', []);
        $rates = $this->normalizeCheckoutLineTaxRates($item);
        if (empty($rates)) {
            return;
        }
        $cart = CartHelper::getCart();
        $isReversed = $cart ? $this->module->isReverseChargeCheckout($cart->checkout_data) : false;
        $rcMode = $this->module->getEffectiveRcMode();
        $this->renderTaxBadges($rates, $isReversed, $rcMode);
    }

    public function renderCheckoutSetupFeeTaxLabel($data): void
    {
        $mode = $this->getCheckoutTaxBreakdownDisplayMode();
        if ($mode === 'simplified') {
            return;
        }

        $item = Arr::get($data, 'item', []);
        $signupFeeTaxConfig = Arr::get($item, 'signup_fee_tax_config', []);
        $rates = Arr::get($signupFeeTaxConfig, 'rates', []);
        if (empty($rates) || !is_array($rates)) {
            return;
        }

        $isInclusive = (bool) Arr::get($signupFeeTaxConfig, 'inclusive', false);
        $normalizedRates = [];
        foreach ($rates as $rate) {
            $taxAmount = (int) Arr::get($rate, 'tax_amount', 0);
            $ratePercent = (float) Arr::get($rate, 'rate_percent', 0);
            if ($taxAmount <= 0 && $ratePercent <= 0) {
                continue;
            }
            $normalizedRates[] = [
                'short_label'    => $this->getCheckoutLineTaxShortLabel((string) Arr::get($rate, 'label', '')),
                'formatted_rate' => Helper::formatTaxRatePercent((float) $ratePercent),
                'inclusive'      => $isInclusive,
                'tax_amount'     => $taxAmount,
            ];
        }

        if (empty($normalizedRates)) {
            return;
        }
        $cart = CartHelper::getCart();
        $isReversed = $cart ? $this->module->isReverseChargeCheckout($cart->checkout_data) : false;
        $rcMode = $this->module->getEffectiveRcMode();
        $this->renderTaxBadges($normalizedRates, $isReversed, $rcMode);
    }

    private function renderTaxBadges(array $normalizedRates, $isReversed = false, $rcMode = 'fixed'): void
    {
        // Fixed-mode reverse charge: inclusive-priced lines keep their gross price and
        // no VAT is charged, so an "incl. X" badge would be misleading — hide those rates.
        if ($isReversed && $rcMode !== 'dynamic') {
            $normalizedRates = array_values(array_filter($normalizedRates, function ($rate) {
                return empty($rate['inclusive']);
            }));
        }
        if (empty($normalizedRates)) {
            return;
        }
        ?>
        <div class="fct_item_tax_badges" aria-label="<?php esc_attr_e('Tax breakdown', 'fluent-cart'); ?>">
            <?php foreach ($normalizedRates as $rate) : ?>
                <div class="fct_item_tax_badge_row">
                    <span class="fct_item_tax_badge <?php echo esc_attr($rate['inclusive'] ? 'is-inclusive' : 'is-exclusive'); ?>">
                        <span>
                            <?php
                            $badgeText = sprintf(
                            /* translators: %1$s: tax label, %2$s: tax rate percent */
                                    __('%1$s (%2$s%%)', 'fluent-cart'),
                                    $rate['short_label'],
                                    $rate['formatted_rate']
                            );
                            echo esc_html($badgeText);
                            ?>
                        </span>
                    </span>
                    <?php if (!empty($rate['tax_amount']) && (int) $rate['tax_amount'] > 0) : ?>
                        <?php $rateReversedClass = $this->shouldRateStrikethrough($isReversed, $rate['inclusive'], $rcMode) ? ' is-reversed' : ''; ?>
                        <span class="fct_item_tax_badge_amount <?php echo esc_attr($rate['inclusive'] ? 'is-inclusive' : 'is-exclusive'); ?><?php echo esc_attr($rateReversedClass); ?>">
                            <?php
                            $amountText = $rate['inclusive']
                                ? sprintf(
                                    /* translators: %1$s: formatted tax amount */
                                    __('incl. %1$s', 'fluent-cart'),
                                    Helper::toDecimal((int) $rate['tax_amount'])
                                )
                                : sprintf(
                                    /* translators: %1$s: formatted tax amount */
                                    __('+ %1$s', 'fluent-cart'),
                                    Helper::toDecimal((int) $rate['tax_amount'])
                                );
                            echo esc_html($amountText);
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function getSetupFeeTaxData($item): ?array
    {
        $signupFeeTaxConfig = Arr::get($item, 'signup_fee_tax_config', []);
        $rawRates = Arr::get($signupFeeTaxConfig, 'rates', []);
        if (empty($rawRates) || !is_array($rawRates)) {
            return null;
        }

        $isInclusive = (bool) Arr::get($signupFeeTaxConfig, 'inclusive', false);
        $rates = [];
        foreach ($rawRates as $rate) {
            $taxAmount   = (int) Arr::get($rate, 'tax_amount', 0);
            $ratePercent = (float) Arr::get($rate, 'rate_percent', 0);
            if ($taxAmount <= 0 && $ratePercent <= 0) {
                continue;
            }
            $taxableAmount = (int) Arr::get($rate, 'taxable_amount', 0);
            $rates[] = [
                'short_label'    => $this->getCheckoutLineTaxShortLabel((string) Arr::get($rate, 'label', '')),
                'formatted_rate' => Helper::formatTaxRatePercent((float) $ratePercent),
                'tax_amount'     => $taxAmount,
                'display_base'   => $isInclusive ? max(0, $taxableAmount - $taxAmount) : $taxableAmount,
            ];
        }

        if (empty($rates)) {
            return null;
        }

        $setupFee = (int) Arr::get($item, 'other_info.signup_fee', 0);
        $totalTax = array_sum(array_map(function ($rate) {
            return (int) $rate['tax_amount'];
        }, $rates));

        return [
            'rates'         => $rates,
            'is_inclusive'  => $isInclusive,
            'total_tax'     => $totalTax,
            'display_total' => $isInclusive ? $setupFee : $setupFee + $totalTax,
            'primary_label' => Arr::get($rates, '0.short_label', __('Tax', 'fluent-cart')),
        ];
    }

    public function renderCheckoutSetupFeeTaxTooltip($data): void
    {
        $mode = $this->getCheckoutTaxBreakdownDisplayMode();
        if ($mode === 'simplified') {
            return;
        }

        $item    = Arr::get($data, 'item', []);
        $taxData = $this->getSetupFeeTaxData($item);
        if (!$taxData) {
            return;
        }

        $cart = CartHelper::getCart();
        $isReversed = $cart ? $this->module->isReverseChargeCheckout($cart->checkout_data) : false;
        $rcMode = $this->module->getEffectiveRcMode();

        $rates        = $taxData['rates'];
        $isInclusive  = $taxData['is_inclusive'];
        $displayTotal = $taxData['display_total'];
        if ($isReversed) {
            if ($taxData['is_inclusive'] && $rcMode === 'dynamic') {
                $displayTotal = $taxData['display_total'] - $taxData['total_tax'];
            } else {
                $displayTotal = (int) Arr::get($data, 'item.other_info.signup_fee', 0);
            }
        }
        $tooltipId    = 'fct-item-tax-tooltip-' . Helper::getUidSerial();
        ?>
        <div class="fct_item_tax_hint">
            <button
                type="button"
                class="fct_item_tax_hint_button"
                aria-label="<?php esc_attr_e('View tax breakdown for this item', 'fluent-cart'); ?>"
                aria-describedby="<?php echo esc_attr($tooltipId); ?>"
            >
                <span aria-hidden="true">i</span>
            </button>
            <div class="fct_item_tax_tooltip" id="<?php echo esc_attr($tooltipId); ?>" role="tooltip">
                <span class="fct_item_tax_tooltip_heading">
                    <?php echo esc_html($isInclusive ? __('Tax-inclusive price', 'fluent-cart') : __('Tax-exclusive price', 'fluent-cart')); ?>
                </span>
                <?php foreach ($rates as $rate) : ?>
                    <?php $lineReversedClass = $this->shouldRateStrikethrough($isReversed, isset($rate['inclusive']) ? (bool)$rate['inclusive'] : $taxData['is_inclusive'], $rcMode) ? ' is-reversed' : ''; ?>
                    <span class="fct_item_tax_tooltip_line<?php echo esc_attr($lineReversedClass); ?>">
                        <?php
                        $lineText = sprintf(
                            /* translators: %1$s: tax base amount, %2$s: tax label, %3$s: tax rate percent, %4$s: tax amount */
                            __('Base %1$s + %2$s %3$s%% %4$s', 'fluent-cart'),
                            Helper::toDecimal($rate['display_base']),
                            $rate['short_label'],
                            $rate['formatted_rate'],
                            Helper::toDecimal($rate['tax_amount'])
                        );
                        echo esc_html($lineText);
                        ?>
                    </span>
                <?php endforeach; ?>
                <span class="fct_item_tax_tooltip_line is-total">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %1$s: line total amount */
                        __('Total %1$s', 'fluent-cart'),
                        Helper::toDecimal($displayTotal)
                    ));
                    ?>
                </span>
            </div>
        </div>
        <?php
    }

    public function renderCheckoutSetupFeeTaxInfo($data): void
    {
        $mode = $this->getCheckoutTaxBreakdownDisplayMode();
        if ($mode === 'simplified') {
            return;
        }

        $item    = Arr::get($data, 'item', []);
        $taxData = $this->getSetupFeeTaxData($item);
        if (!$taxData) {
            return;
        }

        $isInclusive  = $taxData['is_inclusive'];
        $totalTax     = $taxData['total_tax'];
        $primaryLabel = $taxData['primary_label'];

        $priceNote = $isInclusive
            ? sprintf(
                /* translators: %1$s: tax label */
                __('%1$s incl.', 'fluent-cart'),
                $primaryLabel
            )
            : sprintf(
                /* translators: %1$s: tax amount, %2$s: tax label */
                __('+ %1$s %2$s', 'fluent-cart'),
                Helper::toDecimal($totalTax),
                strtolower($primaryLabel)
            );
        ?>
        <span class="fct_setup_fee_price_note"><?php echo esc_html($priceNote); ?></span>
        <?php
    }

    public function renderCheckoutLineItemTaxTooltip($data): void
    {
        $mode = $this->getCheckoutTaxBreakdownDisplayMode();
        if ($mode === 'simplified') {
            return;
        }

        $item  = Arr::get($data, 'item', []);
        $rates = $this->normalizeCheckoutLineTaxRates($item);
        if (empty($rates)) {
            return;
        }

        $cart = CartHelper::getCart();
        $isReversed = $cart ? $this->module->isReverseChargeCheckout($cart->checkout_data) : false;
        $rcMode = $this->module->getEffectiveRcMode();

        $isInclusive   = (bool) Arr::get($rates, '0.inclusive', false);
        $itemSubtotal  = (int) Arr::get($item, 'line_total', Arr::get($item, 'subtotal', 0));
        $itemTaxAmount = array_sum(array_map(function ($rate) {
            return (int) Arr::get($rate, 'tax_amount', 0);
        }, $rates));
        if ($isReversed) {
            if ($isInclusive && $rcMode === 'dynamic') {
                $displayTotal = $itemSubtotal - $itemTaxAmount;
            } else {
                $displayTotal = $itemSubtotal;
            }
        } else {
            $displayTotal = $isInclusive ? $itemSubtotal : $itemSubtotal + $itemTaxAmount;
        }
        $tooltipId    = 'fct-item-tax-tooltip-' . Helper::getUidSerial();
        ?>
        <div class="fct_item_tax_hint">
            <button
                type="button"
                class="fct_item_tax_hint_button"
                aria-label="<?php esc_attr_e('View tax breakdown for this item', 'fluent-cart'); ?>"
                aria-describedby="<?php echo esc_attr($tooltipId); ?>"
            >
                <span aria-hidden="true">i</span>
            </button>
            <div class="fct_item_tax_tooltip" id="<?php echo esc_attr($tooltipId); ?>" role="tooltip">
                <span class="fct_item_tax_tooltip_heading">
                    <?php echo esc_html($isInclusive ? __('Tax-inclusive price', 'fluent-cart') : __('Tax-exclusive price', 'fluent-cart')); ?>
                </span>
                <?php foreach ($rates as $rate) : ?>
                    <?php $lineReversedClass = $this->shouldRateStrikethrough($isReversed, $rate['inclusive'], $rcMode) ? ' is-reversed' : ''; ?>
                    <span class="fct_item_tax_tooltip_line<?php echo esc_attr($lineReversedClass); ?>">
                        <?php
                        $lineText = sprintf(
                            /* translators: %1$s: tax base amount, %2$s: tax label, %3$s: tax rate percent, %4$s: tax amount */
                            __('Base %1$s + %2$s %3$s%% %4$s', 'fluent-cart'),
                            Helper::toDecimal($rate['display_base']),
                            $rate['short_label'],
                            $rate['formatted_rate'],
                            Helper::toDecimal($rate['tax_amount'])
                        );
                        echo esc_html($lineText);
                        ?>
                    </span>
                <?php endforeach; ?>
                <span class="fct_item_tax_tooltip_line is-total">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %1$s: line total amount */
                        __('Total %1$s', 'fluent-cart'),
                        Helper::toDecimal($displayTotal)
                    ));
                    ?>
                </span>
            </div>
        </div>
        <?php
    }

    public function renderCheckoutLineItemTaxInfo($data): void
    {
        $mode = $this->getCheckoutTaxBreakdownDisplayMode();
        if ($mode === 'simplified') {
            return;
        }

        $item  = Arr::get($data, 'item', []);
        $rates = $this->normalizeCheckoutLineTaxRates($item);
        if (empty($rates)) {
            return;
        }

        $isInclusive   = (bool) Arr::get($rates, '0.inclusive', false);
        $itemTaxAmount = array_sum(array_map(function ($rate) {
            return (int) Arr::get($rate, 'tax_amount', 0);
        }, $rates));
        $primaryLabel = Arr::get($rates, '0.short_label', __('Tax', 'fluent-cart'));

        $priceNote = $isInclusive
            ? sprintf(
                /* translators: %1$s: tax label */
                __('%1$s incl.', 'fluent-cart'),
                $primaryLabel
            )
            : sprintf(
                /* translators: %1$s: tax amount, %2$s: tax label */
                __('+ %1$s %2$s', 'fluent-cart'),
                Helper::toDecimal($itemTaxAmount),
                strtolower($primaryLabel)
            );
        ?>
        <div class="fct_item_tax_price_note"><?php echo esc_html($priceNote); ?></div>
        <?php
    }

    public function renderUnitPriceRoundingTooltip($data): void
    {
        $item      = Arr::get($data, 'item', []);
        $quantity  = (int) Arr::get($item, 'quantity', 1);

        if ($quantity < 2) {
            return;
        }

        $unitPrice = (int) Arr::get($item, 'unit_price', 0);
        $subtotal  = (int) Arr::get($item, 'subtotal', 0);

        if ($unitPrice <= 0) {
            return;
        }

        $shouldShow = false;

        // Case 1 — TaxModule RC dynamic: the applied per-unit tax adjustment is rounded,
        // so unit_price * qty may differ from the exact net by up to (qty - 1) cents.
        $rcAdjustment = (int) Arr::get($item, 'line_meta.reverse_charge_adjustment', 0);
        if ($rcAdjustment > 0) {
            $rates          = (array) Arr::get($item, 'line_meta.tax_config.rates', []);
            $actualTaxTotal = (int) array_sum(array_map('intval', array_column($rates, 'tax_amount')));
            $diff           = abs($rcAdjustment - $actualTaxTotal);
            if ($diff > 0 && $diff <= $quantity) {
                $shouldShow = true;
            }
        }

        // Case 2 — generic: unit_price * qty already doesn't match subtotal
        // (future dynamic pricing or any other module that stores a rounded unit_price)
        if (!$shouldShow && $subtotal > 0) {
            $diff = abs(($unitPrice * $quantity) - $subtotal);
            if ($diff > 0 && $diff <= $quantity) {
                $shouldShow = true;
            }
        }

        if (!$shouldShow) {
            return;
        }

        $tooltipId = 'fct-unit-price-rounding-' . Helper::getUidSerial();
        ?>
        <div class="fct_item_tax_hint">
            <button
                type="button"
                class="fct_item_tax_hint_button"
                aria-label="<?php esc_attr_e('Unit price rounding information', 'fluent-cart'); ?>"
                aria-describedby="<?php echo esc_attr($tooltipId); ?>"
            >
                <span aria-hidden="true">i</span>
            </button>
            <div class="fct_item_tax_tooltip fct_unit_price_rounding_tooltip" id="<?php echo esc_attr($tooltipId); ?>" role="tooltip">
                <?php esc_html_e('Unit price is rounded for display. The line total is calculated at full precision, so it always reconciles exactly.', 'fluent-cart'); ?>
            </div>
        </div>
        <?php
    }

    protected function getCheckoutTaxBreakdownDisplayMode(): string
    {
        // Backward compat: legacy stored values ('both', 'label', 'tooltip', or anything
        // else) all collapse to 'itemized'. Only an explicit 'simplified' stays simplified.
        $mode = Arr::get($this->module->getSettings(), 'checkout_tax_breakdown_display', 'itemized');

        return $mode === 'simplified' ? 'simplified' : 'itemized';
    }

    protected function getTaxDisplayLabel(): string
    {
        $label = trim((string) Arr::get($this->module->getSettings(), 'tax_display_label', ''));
        return $label !== '' ? $label : __('Tax', 'fluent-cart');
    }

    protected function normalizeCheckoutLineTaxRates($item): array
    {
        $rates = Arr::get($item, 'line_meta.tax_config.rates', []);
        if (empty($rates) || !is_array($rates)) {
            return [];
        }

        $isInclusive = (bool) Arr::get($item, 'line_meta.tax_config.inclusive', false);
        $normalizedRates = [];

        foreach ($rates as $rate) {
            $taxAmount = (int) Arr::get($rate, 'tax_amount', 0);
            $ratePercent = (float) Arr::get($rate, 'rate_percent', 0);
            $taxableAmount = (int) Arr::get($rate, 'taxable_amount', 0);

            if ($taxAmount <= 0 && $ratePercent <= 0) {
                continue;
            }

            $normalizedRates[] = [
                'label'          => (string) Arr::get($rate, 'label', __('Tax', 'fluent-cart')),
                'short_label'    => $this->getCheckoutLineTaxShortLabel((string) Arr::get($rate, 'label', '')),
                'formatted_rate' => Helper::formatTaxRatePercent((float) $ratePercent),
                'tax_amount'     => $taxAmount,
                'display_base'   => $isInclusive ? max(0, $taxableAmount - $taxAmount) : $taxableAmount,
                'inclusive'      => $isInclusive,
            ];
        }

        return $normalizedRates;
    }

    protected function getCheckoutLineTaxShortLabel($label): string
    {
        $label = trim((string) $label);

        if (!$label) {
            return __('Tax', 'fluent-cart');
        }

        return $label;
    }

    private function shouldRateStrikethrough($isReversed, $rateIsInclusive, $rcMode): bool
    {
        if (!$isReversed) {
            return false;
        }
        return !$rateIsInclusive || $rcMode === 'dynamic';
    }
}
