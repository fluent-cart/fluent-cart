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
    $orderItems = $order->order_items->toArray();
    $transaction = $order->getLatestTransaction();
    $isRefund = $is_refund ?? false;
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
                    - <?php echo esc_html($item['title']); ?>
                </p>

                <?php if ($item['payment_type'] === 'subscription' && !empty($item['payment_info'])): ?>
                    <p style="font-size:12px;color:rgb(75,85,99);line-height:20px;margin: 3px 0 0 0;">
                        <?php echo wp_kses_post($item['payment_info']) ?>
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
    <?php endforeach; ?>
    </tbody>
</table>
<table style="border-spacing:0;padding: 0; width: 100%;border: none;">
    <tr>
        <td style="width: 50%;"></td>
        <td style="width: 100%;">
            <table role="presentation"
                   style="background-color:rgb(249,250,251);padding:16px;border-radius:8px;margin: 0; width: 100%;border: none;">
                <tbody>
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

                <?php if ($order->shipping_total > 0): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                <?php echo esc_html__('Shipping', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;color:rgb(55,65,81);margin:0;line-height:24px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->shipping_total)); ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($order->shipping_tax > 0): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                <?php echo esc_html__('Shipping Tax', 'fluent-cart'); ?>
                                <?php echo esc_html($order->tax_behavior == 2 ? __('(Included)', 'fluent-cart') : __('(Excluded)', 'fluent-cart')); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;color:rgb(55,65,81);margin:0;line-height:24px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->shipping_tax)); ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($order->manual_discount_total + $order->coupon_discount_total > 0): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                <?php echo esc_html__('Discount', 'fluent-cart'); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                - <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->manual_discount_total + $order->coupon_discount_total)); ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($order->tax_total > 0): ?>
                    <tr style="width:100%">
                        <td style="width:70%">
                            <p style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                <?php echo esc_html__('Tax', 'fluent-cart'); ?>
                                <?php echo esc_html($order->tax_behavior == 2 ? __('(Included)', 'fluent-cart') : __('(Excluded)', 'fluent-cart')); ?>
                            </p>
                        </td>
                        <td style="width:30%;text-align:right">
                            <p style="font-size:14px;color=rgb(55,65,81);margin:0;line-height:24px;">
                                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->tax_total)); ?>
                            </p>
                        </td>
                    </tr>
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

                <tr style="width:100%">
                    <td style="width:70%"><p
                                style="font-size:16px;font-weight:700;color:rgb(17,24,39);line-height:24px;margin: 0;">
                            <?php echo esc_html__('Total', 'fluent-cart'); ?>
                        </p>
                    </td>
                    <td style="width:30%;text-align:right">
                        <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);line-height:24px;margin: 0;">
                            <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($order->total_amount - $order->total_refund)); ?>
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
                            <?php echo esc_html($transaction->getPaymentMethodText()); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
            <?php
                $orderTaxRates = $order->orderTaxRates->first();
                $taxtotal = $order->tax_total + $order->shipping_tax;   
                if(Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.valid') && !$taxtotal): ?>
                <div style="text-align: right; font-size: 14px; margin-top: 10px;">
                    <?php echo '*' . esc_html__('Tax to be paid on reverse charge basis', 'fluent-cart'); ?>
                </div>

            <?php endif ?>
        </td>
    </tr>
</table>



