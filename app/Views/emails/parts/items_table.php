<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var  \FluentCart\App\Models\Order $order
 * @var $cart_image
 * @var $item_count
 */
?>
<?php if (isset($heading)): ?>
    <p style="font-size:16px;font-weight:500;color:rgb(44,62,80);line-height:24px;margin: 0 0 16px;">
        <?php echo esc_html($heading); ?>
    </p>
<?php endif; ?>

<?php

use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;
    $allOrderItems = $order->order_items ? $order->order_items->toArray() : [];
    $orderItems = array_filter($allOrderItems, function ($item) {
        return !in_array($item['payment_type'] ?? '', ['signup_fee', 'fee']);
    });
    $feeItems = array_filter($allOrderItems, function ($item) {
        return ($item['payment_type'] ?? '') === 'fee';
    });
    $transaction = $order->getLatestTransaction();
    $isRefund = $is_refund ?? false;
    $isReversed = $order->isReverseChargeTaxOrder();
    $rcMode = $order->getOrderRcMode();
    $fctEmailDisplayMode = ($order instanceof \FluentCart\App\Models\Order) ? TaxSummaryHelper::getTaxDisplayMode() : 'itemized';

?>

<table role="presentation" style="border-spacing:0;padding: 0; width: 100%;border: none">
    <thead>
    <tr>
        <th style="background-color:rgb(249,250,251); padding-left: 16px">
            <p style="font-size:12px;font-weight:600;color:rgb(55,65,81);text-transform:uppercase;line-height:24px;margin: 0;text-align: left">
                <?php echo esc_html__('Item', 'fluent-cart'); ?>
            </p>
        </th>

        <th style="background-color:rgb(249,250,251); width: 50px"></th>

        <th style="background-color:rgb(249,250,251); padding-right: 16px; width: 200px;">
            <p style="font-size:12px;font-weight:600;color:rgb(55,65,81);text-transform:uppercase;line-height:24px;margin: 0;text-align: right">
                <?php echo esc_html__('Total', 'fluent-cart'); ?>
            </p>
        </th>
    </tr>
    </thead>
    <tbody style="width:100%">
    <?php foreach ($orderItems as $item): ?>
        <tr>
            <td style="padding-left: 16px;padding-top: 8px;padding-bottom: 8px;" colspan="2">
                <p style="font-size: 15px; color: #2F3448; font-weight: 500; overflow: hidden; line-height: 18px; margin-top: 0; margin-bottom: 5px;">

                    <?php echo esc_html($item['post_title']); ?>
                    <?php if ($item['quantity'] > 1): ?>
                        <span style="font-size:12px;font-weight:400;color:rgb(75,85,99)">x <?php echo esc_html($item['quantity']); ?></span>
                    <?php endif; ?>

                <p style="margin: 0; font-size: 14px; color: #758195; font-weight: 400; line-height: 15px;">
                    - <?php echo esc_html(!empty($item['variation_display_title']) ? $item['variation_display_title'] : $item['title']); ?>
                </p>

                <?php if ($item['payment_type'] === 'subscription' && !empty($item['payment_info'])): ?>
                    <p style="font-size:12px;color:rgb(75,85,99);line-height:20px;margin: 3px 0 0 0;">
                        <?php echo wp_kses_post($item['payment_info']) ?>
                    </p>
                <?php endif; ?>



                <?php
                    $otherInfo = is_array($item['other_info'] ?? null) ? $item['other_info'] : [];
                    $packageInfo = Arr::get($item, 'package_info', '');
                    if (!$packageInfo && Arr::get($otherInfo, 'package_name')) {
                        $packageInfo = \FluentCart\App\Services\Renderer\PackageDescriptionRenderer::buildPackageInfoFromOtherInfo($otherInfo);
                    }
                    if ($packageInfo):
                ?>
                    <p style="font-size:12px;color:rgb(107,114,128);line-height:18px;margin: 5px 0 0 0;">
                        <?php echo esc_html($packageInfo); ?>
                    </p>
                <?php endif; ?>
                </p>
            </td>
            <td style="padding-right: 16px;text-align:right">
                <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0;line-height:24px;">
                    <?php echo esc_html($item['formatted_total']); ?>
                </p>
            </td>
        </tr>

        <?php
            $itemRates = TaxSummaryHelper::getItemTaxRates($item);
        ?>
        <?php if ($fctEmailDisplayMode !== 'simplified' && !empty($itemRates)): ?>
            <tr>
                <td colspan="3" style="padding: 0 16px 8px;">
                    <table width="100%" style="border-spacing:0;border-collapse:collapse;">
                        <?php foreach ($itemRates as $rate):
                            $pillBg    = $rate['inclusive'] ? '#EAF3DE' : '#FAEEDA';
                            $pillColor = $rate['inclusive'] ? '#3B6D11' : '#854F0B';
                            $rateIsInclusive = !empty($rate['inclusive']);
                            $reversedAmountStyle = ($isReversed && (!$rateIsInclusive || $rcMode === 'dynamic')) ? 'text-decoration:line-through;opacity:0.6;' : '';
                        ?>
                            <tr>
                                <td style="padding: 2px 0;">
                                    <span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;background:<?php echo esc_attr($pillBg); ?>;color:<?php echo esc_attr($pillColor); ?>;">
                                        <?php
                                            /* translators: %1$s: tax label e.g. "VAT", %2$s: rate percentage e.g. "20" or "7.5" */
                                            echo esc_html(sprintf(__('%1$s (%2$s%%)', 'fluent-cart'), $rate['label'], rtrim(rtrim(number_format((float) $rate['rate_percent'], 3, '.', ''), '0'), '.')));
                                        ?>
                                    </span>
                                </td>
                                <td style="text-align:right;padding: 2px 0;">
                                    <span style="font-size:11px;font-weight:500;color:<?php echo esc_attr($pillColor); ?>;<?php echo esc_attr($reversedAmountStyle); ?>">
                                        <?php if ($rate['inclusive']): ?>
                                            <?php /* translators: %1$s: formatted tax amount e.g. "$3.92" */ ?>
                                            <?php echo esc_html(sprintf(__('incl. %1$s', 'fluent-cart'), \FluentCart\App\Helpers\Helper::toDecimal($rate['tax_amount']))); ?>
                                        <?php else: ?>
                                            + <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($rate['tax_amount'])); ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </td>
            </tr>
        <?php elseif ($fctEmailDisplayMode !== 'simplified' && !empty($item['tax_amount'])): ?>
            <?php
                $itemIsInclusive = TaxSummaryHelper::isPrimaryTaxInclusive($order);
                $reversedAmountStyle = ($isReversed && (!$itemIsInclusive || $rcMode === 'dynamic')) ? 'text-decoration:line-through;opacity:0.6;' : '';
                $pillBg    = $itemIsInclusive ? '#EAF3DE' : '#FAEEDA';
                $pillColor = $itemIsInclusive ? '#3B6D11' : '#854F0B';
                $pillLabel = $itemIsInclusive ? esc_html__('Tax (incl.)', 'fluent-cart') : esc_html__('Tax (excl.)', 'fluent-cart');
            ?>
            <tr>
                <td colspan="3" style="padding: 0 16px 8px;">
                    <table width="100%" style="border-spacing:0;border-collapse:collapse;">
                        <tr>
                            <td style="padding: 2px 0;">
                                <span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;background:<?php echo esc_attr($pillBg); ?>;color:<?php echo esc_attr($pillColor); ?>;">
                                    <?php echo $pillLabel; ?>
                                </span>
                            </td>
                            <td style="text-align:right;padding: 2px 0;">
                                <span style="font-size:11px;font-weight:500;color:<?php echo esc_attr($pillColor); ?>;<?php echo esc_attr($reversedAmountStyle); ?>">
                                    <?php if ($itemIsInclusive): ?>
                                        <?php /* translators: %1$s: formatted tax amount e.g. "$3.92" */ ?>
                                        <?php echo esc_html(sprintf(__('incl. %1$s', 'fluent-cart'), \FluentCart\App\Helpers\Helper::toDecimal((int) $item['tax_amount']))); ?>
                                    <?php else: ?>
                                        + <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal((int) $item['tax_amount'])); ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        <?php endif; ?>

        <?php if (!empty($item['setup_info'])): ?>
            <tr>
                <td colspan="3" style="padding: 0 16px 2px;">
                    <p style="font-size:12px;color:rgb(75,85,99);line-height:20px;margin: 3px 0 0 0;">
                        <?php echo wp_kses_post($item['setup_info']); ?>
                    </p>
                </td>
            </tr>
        <?php endif; ?>

        <?php if (($item['payment_type'] ?? '') === 'subscription'): ?>
            <?php
                $sfSibling = null;
                foreach ($allOrderItems as $sfCandidate) {
                    if (($sfCandidate['payment_type'] ?? '') !== 'signup_fee') {
                        continue;
                    }
                    $parentId = isset($sfCandidate['line_meta']['parent_item_id']) ? (int) $sfCandidate['line_meta']['parent_item_id'] : 0;
                    if ($parentId && $parentId === (int) $item['id']) {
                        $sfSibling = $sfCandidate;
                        break;
                    }
                    if (!$parentId && $sfCandidate['object_id'] === $item['object_id']) {
                        $sfSibling = $sfCandidate;
                        break;
                    }
                }
                $sfRates = $sfSibling ? TaxSummaryHelper::getItemTaxRates($sfSibling) : [];
            ?>
            <?php if ($fctEmailDisplayMode !== 'simplified' && !empty($sfRates)): ?>
                <tr>
                    <td colspan="3" style="padding: 0 16px 8px;">
                        <table width="100%" style="border-spacing:0;border-collapse:collapse;">
                            <?php foreach ($sfRates as $sfRate):
                                $sfBg    = $sfRate['inclusive'] ? '#EAF3DE' : '#FAEEDA';
                                $sfColor = $sfRate['inclusive'] ? '#3B6D11' : '#854F0B';
                                $sfRateIsInclusive = !empty($sfRate['inclusive']);
                                $reversedAmountStyle = ($isReversed && (!$sfRateIsInclusive || $rcMode === 'dynamic')) ? 'text-decoration:line-through;opacity:0.6;' : '';
                            ?>
                                <tr>
                                    <td style="padding: 2px 0;">
                                        <span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;background:<?php echo esc_attr($sfBg); ?>;color:<?php echo esc_attr($sfColor); ?>;">
                                            <?php
                                                /* translators: %1$s: tax label e.g. "VAT", %2$s: rate percentage e.g. "20" or "7.5" */
                                                echo esc_html(sprintf(__('Setup Fee — %1$s (%2$s%%)', 'fluent-cart'), $sfRate['label'], rtrim(rtrim(number_format((float) $sfRate['rate_percent'], 3, '.', ''), '0'), '.')));
                                            ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right;padding: 2px 0;">
                                        <span style="font-size:11px;font-weight:500;color:<?php echo esc_attr($sfColor); ?>;<?php echo esc_attr($reversedAmountStyle); ?>">
                                            <?php if ($sfRate['inclusive']): ?>
                                                <?php /* translators: %1$s: formatted tax amount e.g. "$3.92" */ ?>
                                                <?php echo esc_html(sprintf(__('incl. %1$s', 'fluent-cart'), \FluentCart\App\Helpers\Helper::toDecimal($sfRate['tax_amount']))); ?>
                                            <?php else: ?>
                                                + <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($sfRate['tax_amount'])); ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </td>
                </tr>
            <?php else: ?>
                <?php
                    if ($sfSibling) {
                        $sfTax = (int) ($sfSibling['tax_amount'] ?? 0);
                    } else {
                        $sfOtherInfo = is_array($item['other_info'] ?? null) ? $item['other_info'] : [];
                        $sfTax       = (int) Arr::get($sfOtherInfo, 'signup_fee_tax', 0);
                    }
                ?>
                <?php if ($fctEmailDisplayMode !== 'simplified' && $sfTax > 0): ?>
                    <?php
                        $sfIsInclusive   = TaxSummaryHelper::isPrimaryTaxInclusive($order);
                        $reversedAmountStyle = ($isReversed && (!$sfIsInclusive || $rcMode === 'dynamic')) ? 'text-decoration:line-through;opacity:0.6;' : '';
                        $sfFallbackBg    = $sfIsInclusive ? '#EAF3DE' : '#FAEEDA';
                        $sfFallbackColor = $sfIsInclusive ? '#3B6D11' : '#854F0B';
                    ?>
                    <tr>
                        <td colspan="3" style="padding: 0 16px 8px;">
                            <table width="100%" style="border-spacing:0;border-collapse:collapse;">
                                <tr>
                                    <td style="padding: 2px 0;">
                                        <span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;background:<?php echo esc_attr($sfFallbackBg); ?>;color:<?php echo esc_attr($sfFallbackColor); ?>;">
                                            <?php echo esc_html__('Setup Fee Tax', 'fluent-cart'); ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right;padding: 2px 0;">
                                        <span style="font-size:11px;font-weight:500;color:<?php echo esc_attr($sfFallbackColor); ?>;<?php echo esc_attr($reversedAmountStyle); ?>">
                                            <?php if ($sfIsInclusive): ?>
                                                <?php /* translators: %1$s: formatted tax amount e.g. "$3.92" */ ?>
                                                <?php echo esc_html(sprintf(__('incl. %1$s', 'fluent-cart'), \FluentCart\App\Helpers\Helper::toDecimal($sfTax))); ?>
                                            <?php else: ?>
                                                + <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($sfTax)); ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<table style="border-spacing:0;padding: 0; width: 100%;border: none;margin-top:8px;">
    <tr>
        <td colspan="2" style="border-top:1px solid #e5e7eb;padding-top:12px;font-size:0;line-height:0;">&nbsp;</td>
    </tr>
    <tr>
        <td style="width: 50%;"></td>
        <td style="width: 100%;">
            <table role="presentation" width="400"
                   style="margin: 0 0 0 auto; width: 400px; max-width: 100%; border: none;">
                <tbody>
                <!-- Zone A: plain subtotal / shipping / fees / discount -->
                <?php if ($order->subtotal != $order->total_amount): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                <?php echo esc_html__('Subtotal', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;color:rgb(55,65,81);margin:0;line-height:24px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->subtotal)); ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php
                    // Compute summary early so rcShippingAdjustment is available for the shipping row.
                    $emailTaxSummaryEarly = ($order instanceof \FluentCart\App\Models\Order)
                        ? TaxSummaryHelper::computeTaxSummary($order)
                        : ['shouldRender' => false, 'rcShippingAdjustment' => 0, 'rcTotalAdjustment' => 0];
                    $emailRcShippingAdj = (int) \FluentCart\Framework\Support\Arr::get($emailTaxSummaryEarly, 'rcShippingAdjustment', 0);
                ?>
                <?php if ($order->shipping_total > 0):
                    $emailDisplayShipping = max(0, (int) $order->shipping_total - $emailRcShippingAdj);
                    $emailShippingMethodTitle = (string) \FluentCart\Framework\Support\Arr::get($order->config, 'shipping_method_title', '');
                ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                <?php echo esc_html__('Shipping', 'fluent-cart'); ?>
                            </p>
                            <?php if ($emailShippingMethodTitle): ?>
                                <p style="font-size:12px;color:rgb(107,114,128);line-height:18px;margin:0;">
                                    <?php echo esc_html($emailShippingMethodTitle); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;color:rgb(55,65,81);margin:0;line-height:24px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailDisplayShipping)); ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($feeItems as $feeItem): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                <?php echo esc_html($feeItem['title']); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;color:rgb(55,65,81);margin:0;line-height:24px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($feeItem['subtotal'])); ?>
                            </p>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php
                $prorateCredit = (int) \FluentCart\Framework\Support\Arr::get($order->config, 'prorate_credit', 0);
                $upgradeDiscount = $order->manual_discount_total - $prorateCredit;
                // When the prorate credit IS the whole discount, label the row directly
                // instead of showing "Discount" with a single redundant breakdown row.
                $onlyProrateCredit = $prorateCredit > 0 && $upgradeDiscount <= 0 && $order->coupon_discount_total <= 0;
                ?>
                <?php if ($order->manual_discount_total + $order->coupon_discount_total > 0): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                <?php echo $onlyProrateCredit ? esc_html__('Prorate Credit', 'fluent-cart') : esc_html__('Discount', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                - <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->manual_discount_total + $order->coupon_discount_total)); ?>
                            </p>
                        </td>
                    </tr>
                    <?php if ($prorateCredit > 0 && $upgradeDiscount > 0): ?>
                        <tr style="width:100%">
                            <td style="width:70%;padding-left:12px;">
                                <p style="font-size:12px;color:rgb(107,114,128);line-height:20px;margin: 0;">
                                    <?php echo esc_html__('Upgrade Discount', 'fluent-cart'); ?>
                                </p>
                            </td>
                            <td style="width:30%;text-align:right">
                                <p style="font-size:12px;color:rgb(107,114,128);margin:0;line-height:20px;">
                                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($upgradeDiscount)); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($prorateCredit > 0 && !$onlyProrateCredit): ?>
                        <tr style="width:100%">
                            <td style="width:70%;padding-left:12px;">
                                <p style="font-size:12px;color:rgb(107,114,128);line-height:20px;margin: 0;">
                                    <?php echo esc_html__('Prorate Credit', 'fluent-cart'); ?>
                                </p>
                            </td>
                            <td style="width:30%;text-align:right">
                                <p style="font-size:12px;color:rgb(107,114,128);margin:0;line-height:20px;">
                                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($prorateCredit)); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>

                <?php
                    $emailTaxSummary = isset($emailTaxSummaryEarly) ? $emailTaxSummaryEarly : (($order instanceof \FluentCart\App\Models\Order)
                        ? TaxSummaryHelper::computeTaxSummary($order)
                        : ['shouldRender' => false]);
                    if ($emailTaxSummary['shouldRender']):
                        $emailFoldedRateLines  = Arr::get($emailTaxSummary, 'foldedRateLines', []);
                        $emailIncludedInPrices = (int) Arr::get($emailTaxSummary, 'includedInPrices', 0);
                        $emailPayableTax       = (int) Arr::get($emailTaxSummary, 'payableTax', 0);
                        $emailTotalOrderTax    = (int) Arr::get($emailTaxSummary, 'totalOrderTax', 0);
                ?>
                </tbody>
            </table><!-- /Zone A -->
            <!-- Zone B: tax breakdown inside its own grey box -->
            <table role="presentation" align="right" width="400"
                   style="border-spacing:0;border-collapse:collapse;margin:12px 0 0 auto;width:400px;max-width:100%;border:none;">
                <tbody>
                <tr>
                    <td style="background-color:rgb(249,250,251);padding:16px;border-radius:8px;">
            <table role="presentation" width="100%"
                   style="width:100%;border-spacing:0;border-collapse:collapse;border:none;">
                <tbody>
                <?php if (($emailTaxSummary['displayMode'] ?? '') === 'simplified' && !empty($emailTaxSummary['simpleLine'])): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px; font-weight:700; color:#1e293b; line-height:24px; margin:0;">
                                <?php echo esc_html($emailTaxSummary['simpleLine']['label']); ?>
                            </p>
                        </td>
                        <td style="width:30%; text-align:right">
                            <p style="font-size:14px; font-weight:700; color:#1e293b; margin:0; line-height:24px;">
                                <?php echo esc_html($emailTaxSummary['simpleLine']['value']); ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if (($emailTaxSummary['displayMode'] ?? '') !== 'simplified'): ?>
                <tr style="width:100%">
                    <td colspan="2" style="padding:0 0 4px;">
                        <p style="font-size:10px; font-weight:600; text-transform:uppercase;
                                  letter-spacing:0.06em; color:#94a3b8; margin:0;">
                            <?php if (!empty($emailFoldedRateLines)): ?>
                                <?php echo esc_html__('Tax breakdown by rate', 'fluent-cart'); ?>
                            <?php else: ?>
                                <?php echo esc_html__('TAX', 'fluent-cart'); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <?php
                    $rcReversedTotal    = (int) Arr::get($emailTaxSummary, 'reversedTaxTotal', 0);
                    $rcReversedShipping = (int) Arr::get($emailTaxSummary, 'reversedShippingTax', 0);
                    $rcReversedValue    = $rcReversedTotal > 0
                        ? \FluentCart\App\Helpers\Helper::toDecimal($rcReversedTotal)
                        : __('Charge reversed', 'fluent-cart');
                ?>
                <?php if (!empty($emailFoldedRateLines)): ?>
                    <tr style="width:100%">
                        <td colspan="2" style="padding:0 0 4px;">
                            <table style="width:100%;border-collapse:collapse;border-spacing:0;table-layout:fixed;">
                                <tbody>
                                <tr>
                                    <td style="width:58%;font-size:11px;text-transform:uppercase;color:rgb(107,114,128);font-weight:600;padding:2px 12px 2px 0;border:none;white-space:nowrap;">
                                        <?php echo esc_html__('Rate', 'fluent-cart'); ?>
                                    </td>
                                    <td style="width:24%;font-size:11px;text-transform:uppercase;color:rgb(107,114,128);font-weight:600;padding:2px 12px 2px 0;text-align:right;border:none;white-space:nowrap;">
                                        <?php echo esc_html__('Taxable base', 'fluent-cart'); ?>
                                    </td>
                                    <td style="width:18%;font-size:11px;text-transform:uppercase;color:rgb(107,114,128);font-weight:600;padding:2px 0 2px 0;text-align:right;border:none;white-space:nowrap;">
                                        <?php echo esc_html__('Tax', 'fluent-cart'); ?>
                                    </td>
                                </tr>
                                <?php foreach ($emailFoldedRateLines as $emailFoldedLine):
                                    $emailFoldedColor = !empty($emailFoldedLine['inclusive']) ? '#94a3b8' : '#1e293b';
                                ?>
                                <tr>
                                    <td style="width:58%;font-size:14px;line-height:20px;padding:3px 12px 3px 0;border:none;color:<?php echo esc_attr($emailFoldedColor); ?>;white-space:normal;word-break:break-word;overflow-wrap:break-word;vertical-align:top;">
                                        <?php echo esc_html($emailFoldedLine['label']); ?>
                                    </td>
                                    <td style="width:24%;font-size:13px;line-height:20px;padding:3px 12px 3px 0;text-align:right;border:none;color:#94a3b8;white-space:nowrap;vertical-align:top;">
                                        <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailFoldedLine['base'])); ?>
                                    </td>
                                    <td style="width:18%;font-size:13px;line-height:20px;padding:3px 0 3px 0;text-align:right;border:none;color:<?php echo esc_attr($emailFoldedColor); ?>;white-space:nowrap;vertical-align:top;">
                                        <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailFoldedLine['tax'])); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php if ($emailTaxSummary['isReverseCharge']): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px; font-weight:700; color:#1e293b; line-height:24px; margin:0; border-top:1px solid #e2e8f0; padding-top:4px;">
                                <?php echo esc_html__('VAT reversed', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%; text-align:right">
                            <p style="font-size:14px; font-weight:700; color:#1e293b; margin:0; line-height:24px; border-top:1px solid #e2e8f0; padding-top:4px;">
                                <?php echo esc_html($rcReversedValue); ?>
                            </p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px; font-weight:700; color:#1e293b; line-height:24px; margin:0; border-top:1px solid #e2e8f0; padding-top:4px;">
                                <?php echo esc_html__('Total tax', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%; text-align:right">
                            <p style="font-size:14px; font-weight:700; color:#1e293b; margin:0; line-height:24px; border-top:1px solid #e2e8f0; padding-top:4px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailTotalOrderTax)); ?>
                            </p>
                        </td>
                    </tr>
                    <?php if ($emailIncludedInPrices > 0): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:13px; color:#94a3b8; line-height:22px; margin:0;">
                                <?php echo esc_html__('of which included in prices', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%; text-align:right">
                            <p style="font-size:13px; color:#94a3b8; margin:0; line-height:22px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailIncludedInPrices)); ?>
                            </p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($emailPayableTax > 0 && $emailIncludedInPrices > 0): ?>
                    <tr style="width:100%">
                        <td style="width:70%;border-top:1px solid #e2e8f0;padding-top:6px;margin-top:6px;">
                            <p style="font-size:14px; font-weight:700; color:#1e293b; line-height:24px; margin:0;">
                                <?php echo esc_html__('Payable now (added)', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%; text-align:right;border-top:1px solid #e2e8f0;padding-top:6px;margin-top:6px;">
                            <p style="font-size:14px; font-weight:700; color:#1e293b; margin:0; line-height:24px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailPayableTax)); ?>
                            </p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                <?php elseif ($emailTaxSummary['isReverseCharge']): ?>
                    <?php if ($emailTaxSummary['showRcShippingRow'] && $rcReversedShipping > 0): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px; color:#94a3b8; line-height:24px; margin:0;">
                                <?php echo esc_html__('Added on shipping', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%; text-align:right">
                            <p style="font-size:14px; color:#94a3b8; margin:0; line-height:24px;">
                                <span style="text-decoration:line-through;opacity:0.6;"><?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($rcReversedShipping)); ?></span>
                            </p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px; font-weight:700; color:#1e293b; line-height:24px; margin:0;">
                                <?php echo esc_html__('Tax reversed', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%; text-align:right">
                            <p style="font-size:14px; font-weight:700; color:#1e293b; margin:0; line-height:24px;">
                                <?php echo esc_html($rcReversedValue); ?>
                            </p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                        $emailFeeRowsList    = Arr::get($emailTaxSummary, 'feeTaxLineRows', []);
                        $emailTaxRateLines   = Arr::get($emailTaxSummary, 'taxRateLines', []);
                        $emailShippingLines  = Arr::get($emailTaxSummary, 'shippingTaxLines', []);
                        $productTaxRowCount  = !empty($emailTaxRateLines)
                            ? count($emailTaxRateLines)
                            : (int) ($emailTaxSummary['inclusiveTax'] > 0) + (int) ($emailTaxSummary['exclusiveTax'] > 0);
                        $rowCount            = $productTaxRowCount + count($emailFeeRowsList) + (int) ($emailTaxSummary['shippingTax'] > 0);
                        $shouldShowBreakdown = !empty($emailTaxRateLines)
                            || !empty($emailShippingLines)
                            || $rowCount >= 2
                            || ($rowCount === 1 && !($emailTaxSummary['payableTax'] > 0 || $emailTaxSummary['inclusiveTax'] > 0 || (int) Arr::get($emailTaxSummary, 'inclusiveFeeTax', 0) > 0));
                    ?>
                    <?php if (!empty($emailTaxRateLines) && $shouldShowBreakdown): ?>
                        <?php foreach ($emailTaxRateLines as $emailTaxRateLine):
                            $emailTaxRateColor  = !empty($emailTaxRateLine['inclusive']) ? '#94a3b8' : '#1e293b';
                            $emailTaxRateWeight = !empty($emailTaxRateLine['inclusive']) ? 'normal' : '600'; ?>
                            <tr style="width:100%">
                                <td style="width:70%">
                                    <p style="font-size:14px; font-weight:<?php echo esc_attr($emailTaxRateWeight); ?>; color:<?php echo esc_attr($emailTaxRateColor); ?>; line-height:24px; margin:0;">
                                        <?php echo esc_html($emailTaxRateLine['label']); ?>
                                    </p>
                                </td>
                                <td style="width:30%; text-align:right">
                                    <p style="font-size:14px; font-weight:<?php echo esc_attr($emailTaxRateWeight); ?>; color:<?php echo esc_attr($emailTaxRateColor); ?>; margin:0; line-height:24px;">
                                        <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailTaxRateLine['order_tax'])); ?>
                                    </p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (empty($emailTaxRateLines) && $emailTaxSummary['inclusiveTax'] > 0 && $shouldShowBreakdown): ?>
                        <tr style="width:100%">
                            <td style="width:70%">
                                <p style="font-size:14px; color:#94a3b8; line-height:24px; margin:0;">
                                    <?php echo esc_html__('Included in item prices', 'fluent-cart'); ?>
                                </p>
                            </td>
                            <td style="width:30%; text-align:right">
                                <p style="font-size:14px; color:#94a3b8; margin:0; line-height:24px;">
                                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailTaxSummary['inclusiveTax'])); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if (empty($emailTaxRateLines) && $emailTaxSummary['exclusiveTax'] > 0 && $shouldShowBreakdown): ?>
                        <tr style="width:100%">
                            <td style="width:70%">
                                <p style="font-size:14px; font-weight:600; color:#1e293b; line-height:24px; margin:0;">
                                    <?php echo esc_html__('Added on products', 'fluent-cart'); ?>
                                </p>
                            </td>
                            <td style="width:30%; text-align:right">
                                <p style="font-size:14px; font-weight:600; color:#1e293b; margin:0; line-height:24px;">
                                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailTaxSummary['exclusiveTax'])); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($shouldShowBreakdown) : ?>
                    <?php foreach ($emailFeeRowsList as $emailFeeRow):
                        $emailFeeColor  = $emailFeeRow['inclusive'] ? '#94a3b8' : '#1e293b';
                        $emailFeeWeight = $emailFeeRow['inclusive'] ? 'normal' : '600'; ?>
                        <tr style="width:100%">
                            <td style="width:70%">
                                <p style="font-size:14px; font-weight:<?php echo esc_attr($emailFeeWeight); ?>; color:<?php echo esc_attr($emailFeeColor); ?>; line-height:24px; margin:0;">
                                    <?php echo esc_html($emailFeeRow['display_label']); ?>
                                </p>
                            </td>
                            <td style="width:30%; text-align:right">
                                <p style="font-size:14px; font-weight:<?php echo esc_attr($emailFeeWeight); ?>; color:<?php echo esc_attr($emailFeeColor); ?>; margin:0; line-height:24px;">
                                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailFeeRow['tax_amount'])); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($emailTaxSummary['shippingTax'] > 0 && $shouldShowBreakdown):
                        $isShippingInclusive  = (bool) Arr::get($emailTaxSummary, 'isShippingInclusive', false);
                        $emailShippingColor   = $isShippingInclusive ? '#94a3b8' : '#1e293b';
                        $emailShippingWeight  = $isShippingInclusive ? 'normal' : '600';
                        if (!empty($emailShippingLines)):
                            foreach ($emailShippingLines as $shLine): ?>
                                <tr style="width:100%">
                                    <td style="width:70%">
                                        <p style="font-size:14px; font-weight:<?php echo esc_attr($emailShippingWeight); ?>; color:<?php echo esc_attr($emailShippingColor); ?>; line-height:24px; margin:0;">
                                            <?php echo esc_html($shLine['label']); ?>
                                        </p>
                                    </td>
                                    <td style="width:30%; text-align:right">
                                        <p style="font-size:14px; font-weight:<?php echo esc_attr($emailShippingWeight); ?>; color:<?php echo esc_attr($emailShippingColor); ?>; margin:0; line-height:24px;">
                                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($shLine['shipping_tax'])); ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endforeach;
                        else: ?>
                            <tr style="width:100%">
                                <td style="width:70%">
                                    <p style="font-size:14px; font-weight:<?php echo esc_attr($emailShippingWeight); ?>; color:<?php echo esc_attr($emailShippingColor); ?>; line-height:24px; margin:0;">
                                        <?php echo esc_html($isShippingInclusive ? __('Included in shipping prices', 'fluent-cart') : __('Added on shipping', 'fluent-cart')); ?>
                                    </p>
                                </td>
                                <td style="width:30%; text-align:right">
                                    <p style="font-size:14px; font-weight:<?php echo esc_attr($emailShippingWeight); ?>; color:<?php echo esc_attr($emailShippingColor); ?>; margin:0; line-height:24px;">
                                        <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailTaxSummary['shippingTax'])); ?>
                                    </p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($emailTaxSummary['payableTax'] > 0): ?>
                        <tr style="width:100%">
                            <td style="width:70%">
                                <p style="font-size:14px; font-weight:700; color:#1e293b; line-height:24px; margin:0; border-top:1px solid #e2e8f0; padding-top:4px;">
                                    <?php echo esc_html__('Total payable tax', 'fluent-cart'); ?>
                                </p>
                            </td>
                            <td style="width:30%; text-align:right">
                                <p style="font-size:14px; font-weight:700; color:#1e293b; margin:0; line-height:24px; border-top:1px solid #e2e8f0; padding-top:4px;">
                                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailTaxSummary['payableTax'])); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($emailTaxSummary['inclusiveTax'] > 0 || $emailTaxSummary['inclusiveFeeTax'] > 0): ?>
                        <tr style="width:100%">
                            <td style="width:70%">
                                <p style="font-size:14px; font-weight:normal; color:#94a3b8; line-height:24px; margin:0;">
                                    <?php echo esc_html__('Total tax in this order', 'fluent-cart'); ?>
                                </p>
                            </td>
                            <td style="width:30%; text-align:right">
                                <p style="font-size:14px; font-weight:normal; color:#94a3b8; margin:0; line-height:24px;">
                                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($emailTaxSummary['totalOrderTax'])); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
                </tbody>
            </table>
                    </td>
                </tr>
                </tbody>
            </table><!-- /Zone B: grey tax box -->
            <table role="presentation" width="400"
                   style="margin:12px 0 0 auto; width: 400px; max-width: 100%; border: none;">
                <tbody>
                <!-- Zone C: plain refund / total / payment -->
                <?php endif; ?>

                <?php if ($order->total_refund > 0 && $isRefund): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                <?php echo esc_html__('Refund', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;color:rgb(55,65,81);margin:0;line-height:24px;">
                                - <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->total_refund)); ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php
                    $emailDisplayTotal = max(0, (int) $order->total_amount - (int) $order->total_refund
                        - (int) \FluentCart\Framework\Support\Arr::get($emailTaxSummaryEarly ?? [], 'rcTotalAdjustment', 0));
                ?>
                <tr style="width:100%">
                    <td style="width:70%"><p
                                style="font-size:16px;font-weight:700;color:rgb(17,24,39);line-height:24px;margin: 0;">
                            <?php echo esc_html__('Total', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);line-height:24px;margin: 0;">
                            <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($emailDisplayTotal, null, false, true, true)); ?>
                        </p>
                    </td>
                </tr>

                <?php if ($order->total_refund > 0 && !$isRefund): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                <?php echo esc_html__('Refund', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;color:rgb(55,65,81);margin:0;line-height:24px;">
                                - <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->total_refund)); ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <tr style="width:100%">
                    <td style="width:70%">
                        <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                            <?php echo esc_html__('Payment Method', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="text-transform:uppercase;font-size:13px;color:rgb(55,65,81);margin:0;line-height:24px;">
                            <?php echo esc_html($transaction ? $transaction->getPaymentMethodText() : ''); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
            <?php
                if($order->isReverseChargeTaxOrder()): ?>
                <div style="text-align: right; font-size: 14px; margin-top: 10px;">
                    <?php echo '*' . esc_html(TaxModule::getReverseChargeNoticeText()); ?>
                </div>

            <?php endif ?>
        </td>
    </tr>
</table>
