<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php if (isset($heading)): ?>
    <p style="font-size:16px;font-weight:500;color:rgb(44,62,80);margin:0px;margin-bottom:16px;line-height:24px;margin-top:0px;margin-left:0px;margin-right:0px">
        <?php echo esc_html($heading); ?>
    </p>
<?php endif; ?>

<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
       style="border-width:1px;border-color:rgb(229,231,235);border-radius:8px;overflow:hidden;margin-bottom:16px;">
    <tbody>
    <tr>
        <td>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                   style="background-color:rgb(249,250,251);padding-left:16px;padding-right:16px;padding-top:0px;padding-bottom:0px;border-bottom-width:1px;border-color:rgb(229,231,235)">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="width:80%">
                        <p style="font-size:12px;font-weight:600;color:rgb(55,65,81);text-transform:uppercase;margin:0px;line-height:24px;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php esc_html_e('Subscription', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td data-id="__react-email-column" style="width:20%;text-align:right">
                        <p style="font-size:12px;font-weight:600;color:rgb(55,65,81);text-transform:uppercase;margin:0px;line-height:24px;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php esc_html_e('Price', 'fluent-cart'); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                   style="padding-left:16px;padding-right:16px;padding-top:0px;padding-bottom:0px;border-bottom-width:1px;border-color:rgb(243,244,246)">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="width:80%">
                        <p style="font-size:14px;font-weight:600;color:rgb(17,24,39);margin-bottom:2px;line-height:24px;margin-top:16px">
                            <?php echo esc_html($subscription->display_item_name); ?>
                        </p>
                    </td>
                    <td style="width:20%;text-align:right">
                        <p style="font-size:14px;color:rgb(17,24,39);margin:0px;line-height:24px;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($transaction->total)); ?>
                            (<?php echo esc_html($subscription->billing_interval); ?>)
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
        </td>
    </tr>
    </tbody>
</table>
<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
       style="background-color:rgb(249,250,251);padding:16px;border-radius:8px;margin-bottom:24px;border-width:1px;border-color:rgb(229,231,235)">
    <tbody>
    <tr>
        <td>
            <?php
            $subTaxSummary = ($order instanceof \FluentCart\App\Models\Order)
                ? \FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper::computeTaxSummary($order)
                : ['shouldRender' => false];
            $isReverseCharge = $order && $order->isReverseChargeTaxOrder();
            $subShowSubtotal = $order && $order->subtotal != $order->total_amount;
            ?>
            <?php if ($subShowSubtotal || !empty($subTaxSummary['shouldRender'])): ?>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tbody style="width:100%">
                <?php if ($subShowSubtotal): ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;color:rgb(55,65,81);margin:0px;line-height:24px;">
                            <?php esc_html_e('Subtotal', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;color:rgb(55,65,81);margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->subtotal)); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if (!empty($subTaxSummary['shouldRender'])):
                    $subFoldedRateLines  = \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'foldedRateLines', []);
                    $subIncludedInPrices = (int) \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'includedInPrices', 0);
                    $subPayableTax       = (int) \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'payableTax', 0);
                    $subTotalOrderTax    = (int) \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'totalOrderTax', 0);
                    $subIsReverseCharge  = !empty($subTaxSummary['isReverseCharge']);
                    $subDisplayMode      = \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'displayMode', '');
                    $subSimpleLine       = \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'simpleLine');
                ?>
                <?php if ($subDisplayMode === 'simplified' && !empty($subSimpleLine)): ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php echo esc_html($subSimpleLine['label']); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php echo esc_html($subSimpleLine['value']); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if ($subDisplayMode !== 'simplified'): ?>
                <?php
                    $subRcReversedTotal    = (int) \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'reversedTaxTotal', 0);
                    $subRcReversedShipping = (int) \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'reversedShippingTax', 0);
                    $subRcReversedValue    = $subRcReversedTotal > 0
                        ? \FluentCart\App\Helpers\Helper::toDecimal($subRcReversedTotal)
                        : __('Charge reversed', 'fluent-cart');
                ?>
                <?php if (!empty($subFoldedRateLines)): ?>
                <tr style="width:100%">
                    <td colspan="2" style="padding:4px 0;">
                        <table width="100%" style="border-spacing:0;border-collapse:collapse;table-layout:fixed;">
                            <tbody>
                            <tr>
                                <td style="width:58%;font-size:11px;text-transform:uppercase;color:rgb(107,114,128);font-weight:600;padding:2px 12px 2px 0;border:none;white-space:nowrap;">
                                    <?php esc_html_e('Rate', 'fluent-cart'); ?>
                                </td>
                                <td style="width:24%;font-size:11px;text-transform:uppercase;color:rgb(107,114,128);font-weight:600;padding:2px 12px 2px 0;text-align:right;border:none;white-space:nowrap;">
                                    <?php esc_html_e('Taxable base', 'fluent-cart'); ?>
                                </td>
                                <td style="width:18%;font-size:11px;text-transform:uppercase;color:rgb(107,114,128);font-weight:600;padding:2px 0;text-align:right;border:none;white-space:nowrap;">
                                    <?php esc_html_e('Tax', 'fluent-cart'); ?>
                                </td>
                            </tr>
                            <?php foreach ($subFoldedRateLines as $subFoldedLine):
                                $subFoldedColor = !empty($subFoldedLine['inclusive']) ? 'rgb(107,114,128)' : 'rgb(17,24,39)';
                            ?>
                            <tr>
                                <td style="width:58%;font-size:14px;line-height:20px;padding:3px 12px 3px 0;border:none;color:<?php echo esc_attr($subFoldedColor); ?>;white-space:normal;word-break:break-word;overflow-wrap:break-word;vertical-align:top;">
                                    <?php echo esc_html($subFoldedLine['label']); ?>
                                </td>
                                <td style="width:24%;font-size:13px;line-height:20px;padding:3px 12px 3px 0;text-align:right;border:none;color:rgb(107,114,128);white-space:nowrap;vertical-align:top;">
                                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subFoldedLine['base'])); ?>
                                </td>
                                <td style="width:18%;font-size:13px;line-height:20px;padding:3px 0;text-align:right;border:none;color:<?php echo esc_attr($subFoldedColor); ?>;white-space:nowrap;vertical-align:top;">
                                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subFoldedLine['tax'])); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php if ($subIsReverseCharge): ?>
                <tr style="width:100%">
                    <td style="width:70%;border-top:1px solid rgb(229,231,235);padding-top:4px;">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php esc_html_e('VAT reversed', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right;border-top:1px solid rgb(229,231,235);padding-top:4px;">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php echo esc_html($subRcReversedValue); ?>
                        </p>
                    </td>
                </tr>
                <?php else: ?>
                <tr style="width:100%">
                    <td style="width:70%;border-top:1px solid rgb(229,231,235);padding-top:4px;">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php esc_html_e('Total tax', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right;border-top:1px solid rgb(229,231,235);padding-top:4px;">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subTotalOrderTax)); ?>
                        </p>
                    </td>
                </tr>
                <?php if ($subIncludedInPrices > 0): ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:13px;color:rgb(107,114,128);margin:0px;line-height:22px;">
                            <?php esc_html_e('of which included in prices', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:13px;color:rgb(107,114,128);margin:0px;line-height:22px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subIncludedInPrices)); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if ($subPayableTax > 0 && $subIncludedInPrices > 0): ?>
                <tr style="width:100%">
                    <td style="width:70%;border-top:1px solid rgb(229,231,235);padding-top:4px;">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php esc_html_e('Payable now (added)', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right;border-top:1px solid rgb(229,231,235);padding-top:4px;">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subPayableTax)); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endif; ?>
                <?php elseif ($subIsReverseCharge): ?>
                <?php if (!empty($subTaxSummary['showRcShippingRow']) && $subRcReversedShipping > 0): ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;color:rgb(107,114,128);margin:0px;line-height:24px;">
                            <?php esc_html_e('Added on shipping', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;color:rgb(107,114,128);margin:0px;line-height:24px;">
                            <span style="text-decoration:line-through;opacity:0.6;"><?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subRcReversedShipping)); ?></span>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php esc_html_e('Tax reversed', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php echo esc_html($subRcReversedValue); ?>
                        </p>
                    </td>
                </tr>
                <?php else: ?>
                <?php
                    $subFeeRowsList   = \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'feeTaxLineRows', []);
                    $subTaxRateLines  = \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'taxRateLines', []);
                    $subShippingLines = \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'shippingTaxLines', []);
                    $subProductTaxRowCount = !empty($subTaxRateLines)
                        ? count($subTaxRateLines)
                        : (int) ($subTaxSummary['inclusiveTax'] > 0) + (int) ($subTaxSummary['exclusiveTax'] > 0);
                    $subRowCount = $subProductTaxRowCount + count($subFeeRowsList) + (int) ($subTaxSummary['shippingTax'] > 0);
                    $subShouldShowBreakdown = !empty($subTaxRateLines)
                        || !empty($subShippingLines)
                        || $subRowCount >= 2
                        || ($subRowCount === 1 && !($subTaxSummary['payableTax'] > 0 || $subTaxSummary['inclusiveTax'] > 0 || (int) \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'inclusiveFeeTax', 0) > 0));
                ?>
                <?php if (!empty($subTaxRateLines) && $subShouldShowBreakdown): ?>
                    <?php foreach ($subTaxRateLines as $subTaxRateLine):
                        $subTaxRateColor  = !empty($subTaxRateLine['inclusive']) ? 'rgb(107,114,128)' : 'rgb(17,24,39)';
                        $subTaxRateWeight = !empty($subTaxRateLine['inclusive']) ? 'normal' : '600';
                    ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;font-weight:<?php echo esc_attr($subTaxRateWeight); ?>;color:<?php echo esc_attr($subTaxRateColor); ?>;margin:0px;line-height:24px;">
                                <?php echo esc_html($subTaxRateLine['label']); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;font-weight:<?php echo esc_attr($subTaxRateWeight); ?>;color:<?php echo esc_attr($subTaxRateColor); ?>;margin:0px;line-height:24px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subTaxRateLine['order_tax'])); ?>
                            </p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (empty($subTaxRateLines) && $subTaxSummary['inclusiveTax'] > 0 && $subShouldShowBreakdown): ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;color:rgb(107,114,128);margin:0px;line-height:24px;">
                            <?php esc_html_e('Included in item prices', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;color:rgb(107,114,128);margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subTaxSummary['inclusiveTax'])); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if (empty($subTaxRateLines) && $subTaxSummary['exclusiveTax'] > 0 && $subShouldShowBreakdown): ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;font-weight:600;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php esc_html_e('Added on products', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;font-weight:600;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subTaxSummary['exclusiveTax'])); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if ($subShouldShowBreakdown): ?>
                    <?php foreach ($subFeeRowsList as $subFeeRow):
                        $subFeeColor  = $subFeeRow['inclusive'] ? 'rgb(107,114,128)' : 'rgb(17,24,39)';
                        $subFeeWeight = $subFeeRow['inclusive'] ? 'normal' : '600';
                    ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;font-weight:<?php echo esc_attr($subFeeWeight); ?>;color:<?php echo esc_attr($subFeeColor); ?>;margin:0px;line-height:24px;">
                                <?php echo esc_html($subFeeRow['display_label']); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;font-weight:<?php echo esc_attr($subFeeWeight); ?>;color:<?php echo esc_attr($subFeeColor); ?>;margin:0px;line-height:24px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subFeeRow['tax_amount'])); ?>
                            </p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($subTaxSummary['shippingTax'] > 0 && $subShouldShowBreakdown):
                    $subIsShippingInclusive = (bool) \FluentCart\Framework\Support\Arr::get($subTaxSummary, 'isShippingInclusive', false);
                    $subShippingColor  = $subIsShippingInclusive ? 'rgb(107,114,128)' : 'rgb(17,24,39)';
                    $subShippingWeight = $subIsShippingInclusive ? 'normal' : '600';
                    if (!empty($subShippingLines)):
                        foreach ($subShippingLines as $subShLine): ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;font-weight:<?php echo esc_attr($subShippingWeight); ?>;color:<?php echo esc_attr($subShippingColor); ?>;margin:0px;line-height:24px;">
                            <?php echo esc_html($subShLine['label']); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;font-weight:<?php echo esc_attr($subShippingWeight); ?>;color:<?php echo esc_attr($subShippingColor); ?>;margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subShLine['shipping_tax'])); ?>
                        </p>
                    </td>
                </tr>
                        <?php endforeach;
                    else: ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;font-weight:<?php echo esc_attr($subShippingWeight); ?>;color:<?php echo esc_attr($subShippingColor); ?>;margin:0px;line-height:24px;">
                            <?php echo esc_html($subIsShippingInclusive ? __('Included in shipping prices', 'fluent-cart') : __('Added on shipping', 'fluent-cart')); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;font-weight:<?php echo esc_attr($subShippingWeight); ?>;color:<?php echo esc_attr($subShippingColor); ?>;margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subTaxSummary['shippingTax'])); ?>
                        </p>
                    </td>
                </tr>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($subTaxSummary['payableTax'] > 0): ?>
                <tr style="width:100%">
                    <td style="width:70%;border-top:1px solid rgb(229,231,235);padding-top:4px;">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php esc_html_e('Total payable tax', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right;border-top:1px solid rgb(229,231,235);padding-top:4px;">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subTaxSummary['payableTax'])); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if ($subTaxSummary['inclusiveTax'] > 0 || $subTaxSummary['inclusiveFeeTax'] > 0): ?>
                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;font-weight:normal;color:rgb(107,114,128);margin:0px;line-height:24px;">
                            <?php esc_html_e('Total tax in this order', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;font-weight:normal;color:rgb(107,114,128);margin:0px;line-height:24px;">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($subTaxSummary['totalOrderTax'])); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($isReverseCharge): ?>
                <div style="font-size:12px;color:rgb(55,65,81);margin-top:4px;margin-bottom:4px;">
                    <?php echo '* ' . esc_html(\FluentCart\App\Modules\Tax\TaxModule::getReverseChargeNoticeText()); ?>
                </div>
            <?php endif; ?>
            <hr style="border-color:rgb(209,213,219);margin-top:8px;margin-bottom:8px;width:100%;border:none;border-top:1px solid #eaeaea">
            <?php endif; ?>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="width:70%"><p
                                style="font-size:16px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php esc_html_e('Total', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:16px;font-weight:700;color:rgb(17,24,39);margin:0px;line-height:24px;margin-top:0px;margin-bottom:0px;margin-left:0px;margin-right:0px">
                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($transaction->total)); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
            <hr style="border-color:rgb(209,213,219);margin-top:8px;margin-bottom:8px;width:100%;border:none;border-top:1px solid #eaeaea">
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="width:70%">
                        <p>
                            <?php esc_html_e('Payment Method', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p>
                            <?php echo esc_html($transaction->getPaymentMethodText()); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
        </td>
    </tr>
    </tbody>
</table>
