<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<style>
    html {
        background-color: rgb(248, 249, 250);
        font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
    }

    body {
        background-color: rgb(248, 249, 250);
        padding-top: 40px;
        padding-bottom: 40px;
        font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
    }

    p {
        font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
        font-size: 14px;
        margin: 0px;
        margin-bottom: 8px;
        line-height: 24px;
    }

    .email_footer p {
        font-size: 12px;
        color: rgb(127, 140, 141);
        margin: 0px;
        line-height: 24px;
        margin-bottom: 8px;
    }

    hr {
        border-color: rgb(229, 231, 235);
        margin-bottom: 20px;
        width: 100%;
        border: none;
        border-top: 1px solid #eaeaea;
    }

    .space_bottom_30 {
        display: block;
        width: 100%;
        margin-bottom: 30px;
    }

    .fct-transaction-table tr:last-child td {
        border-bottom: none !important;
        padding-bottom: 0 !important;
    }
</style>

<?php

use FluentCart\Api\StoreSettings;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\DateTime\DateTime;

$settings = new StoreSettings();

/**
 * @var $order
 */

$profilePage = $settings->getCustomerProfilePage();
$orderTaxRates = $order->orderTaxRates->first();


?>

<div style="background-color: #fff;max-width: 620px;margin-left: auto;margin-right: auto;border: 1px solid #e7eaee;padding: 10px;border-radius: 8px;">
    <div style=" border-radius: 5px;" id="receipt">
        <div class="fct-email-template-content email-template-content"
             style="font-family: Arial, Helvetica, sans-serif; margin: 0 auto; padding: 0px; width: 100%;">

            <div class="fct-email-template-content-inner" style="font-size: 13px;line-height: 1.4;color: #333;">
                <div style="padding: 20px;">

                    <table role="presentation"
                           style="width: 100%;border-collapse: collapse;margin-bottom: 10px;border: none;">
                        <tbody style="width:100%">
                        <tr style="width:100%">
                            <td style="width:60%;border: none;">
                                <h1 style="font-size:24px;font-weight:700;color:rgb(17,24,39);margin:0px">
                                    <?php
                                    $imageLink = $settings->get('store_logo.url');
                                    $storeName = $settings->get('store_name');
                                    if (empty($imageLink)) {
                                        echo esc_html($storeName);
                                    } else {
                                        echo "<img src=".esc_url($imageLink)." alt=".esc_attr($storeName)." style='max-height: 40px;'>";
                                    }
                                    ?>
                                </h1>

                                <!-- show tax content -->
                                <?php
                                $storeVatNumber = Arr::get($orderTaxRates->meta ?? [], 'store_vat_number', '');
                                $taxcountry = Arr::get($orderTaxRates->meta ?? [], 'tax_country', '');
                                if (!empty($storeVatNumber)): ?>
                                    <span class="fct_invoice_tax_content" style="font-weight: 500; font-size: 14px;">
                                    <?php echo esc_html(TaxModule::getCountryTaxTitle($taxcountry)); ?>:
                                        <?php
                                            if ($storeVatNumber !== '') {
                                                echo esc_html($storeVatNumber);
                                            }
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align: middle; width: 15%; text-align: right; border: none;padding-right: 10px;">
                                <p style="white-space: nowrap; color: #94a3b8; text-align: right; margin: 0;font-size:12px;">
                                    <?php echo esc_html__('Order At', 'fluent-cart'); ?>
                                </p>
                                <p style="white-space: nowrap; font-weight: bold; color: #000; text-align: right; margin: 0;font-size:14px;">
                                    <?php
                                    echo esc_html(
                                        date_i18n(
                                        /* translators: Date format for order creation date */
                                            __('M d, Y', 'fluent-cart'),
                                            DateTime::anyTimeToGmt($order->created_at)->getTimestamp()
                                        )
                                    );
                                    ?>
                                </p>
                            </td>
                            <td style="width:15%;text-align:right;border:none;vertical-align:baseline;">
                                <p style="white-space: nowrap; color: #94a3b8; text-align: right; margin: 0;font-size:12px;">
                                    <?php echo esc_html__('Invoice number #', 'fluent-cart'); ?>
                                </p>
                                <p id="fct-order-invoice-no"
                                   style="white-space: nowrap; font-weight: bold; color: #000; text-align: right; margin: 0;font-size:14px;">
                                    <?php echo esc_html($order->invoice_no); ?>
                                </p>
                            </td>
                        </tr>
                        </tbody>
                    </table>

                    <?php if ($order->fulfillment_type === 'physical') : ?>
                        <div style="background: #f8f9fa;padding: 15px;margin-bottom: 20px;">
                            <div style="font-size: 18px;font-weight: bold;margin-bottom: 8px;">
                                <?php echo esc_html($settings->get('store_name')); ?>
                            </div>
                            <div style="margin-bottom: 3px;">
                                <?php echo esc_html($settings->get('store_address1')); ?>
                            </div>

                            <div>
                                <?php echo esc_html(AddressHelper::getStateNameByCode(
                                        $settings->get('store_state'),
                                        $settings->get('store_country')
                                )); ?>
                                <?php echo esc_html(AddressHelper::getCountryNameByCode($settings->get('store_country'))); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div style="display: grid;grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));gap: 20px;">
                        <?php if ($order->fulfillment_type === 'digital') : ?>
                            <div style="border-radius: 5px;print-color-adjust: exact;">
                                <h5 style="font-weight: bold;font-size: 14px;margin: 0 0 10px 0;color: #495057;border-bottom: 1px solid #dee2e6;padding-bottom: 5px;">
                                    <?php echo esc_html($settings->get('store_name')); ?>
                                </h5>
                                <div style="margin-bottom: 3px;">
                                    <?php echo esc_html($settings->get('store_address1')); ?>
                                </div>
                                <div>
                                    <?php echo esc_html(AddressHelper::getStateNameByCode(
                                            $settings->get('store_state'),
                                            $settings->get('store_country')
                                    )); ?>
                                    <?php echo esc_html(AddressHelper::getCountryNameByCode($settings->get('store_country'))); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div style="border-radius: 5px;print-color-adjust: exact;">
                            <h5 style="font-weight: bold;font-size: 14px;margin: 0 0 10px 0;color: #495057;border-bottom: 1px solid #dee2e6;padding-bottom: 5px;">
                                <?php echo esc_html__('Bill To', 'fluent-cart'); ?>
                            </h5>
                            <?php if (!empty($order->billing_address)) : ?>

                                <div style="margin-bottom: 3px;">
                                    <?php echo esc_html($order->billing_address->getAddressAsText()); ?>
                                </div>
                                <?php if (!empty($vat_tax_id)) : ?>
                                    <div style="margin-bottom: 3px;">
                                        <?php echo esc_html__('VAT/Tax ID: ', 'fluent-cart'). esc_html($vat_tax_id); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="margin-top: 10px;">
                                    <?php echo esc_html($order->customer->email); ?>
                                </div>
                                <?php
                                $phoneNumber = Arr::get($order->billing_address->meta ?? [], 'other_data.phone', '');
                                if ($phoneNumber !== ''):
                                ?>
                                    <div style="margin-top: 3px;">
                                        <?php echo esc_html($phoneNumber); ?>
                                    </div>
                                <?php endif; ?>
                            <div>
                                <?php
                                     $companyName = Arr::get($order->billing_address->meta ?? [], 'other_data.company_name', '');
                                     if ($companyName == '') {
                                        $companyName = Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.name', '');
                                     }
                                     echo esc_html($companyName);
                                ?>
                                </div>
                                <div style="margin-top: 5px;">
                                    <?php
                                        $vatNumber = Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.vat_number', '');
                                        
                                        if ($vatNumber !== '') {
                                            echo esc_html__('EU VAT', 'fluent-cart') . ': ' . esc_html($vatNumber);
                                        }
                                    ?>
                                </div>

                            <?php else: ?>
                                <div style="margin-top: 10px;">
                                    <?php echo esc_html($order->customer->full_name); ?>
                                </div>
                                <?php if (!empty($vat_tax_id)) : ?>
                                    <div style="margin-bottom: 3px;">
                                        <?php echo esc_html__('VAT/Tax ID: ', 'fluent-cart'). esc_html($vat_tax_id); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="margin-top: 3px;">
                                    <?php echo esc_html($order->customer->email); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($order->fulfillment_type === 'physical') : ?>
                            <div style="border-radius: 5px;print-color-adjust: exact;">
                                <h5 style="font-weight: bold;font-size: 14px;margin: 0 0 10px 0;color: #495057;border-bottom: 1px solid #dee2e6;padding-bottom: 5px;">
                                    <?php echo esc_html__('Ship To', 'fluent-cart'); ?>
                                </h5>
                                <?php if (!empty($order->shipping_address)) : ?>
                                    <div style="margin-bottom: 3px;">
                                        <?php echo esc_html($order->shipping_address->getAddressAsText()); ?>
                                    </div>
                                    <?php
                                        $phone = Arr::get($order->shipping_address->meta ?? [], 'other_data.phone', '');
                                        if ($phone !== ''):
                                    ?>
                                        <div style="margin-top: 3px;">
                                            <?php echo esc_html($phone); ?>
                                        </div>
                                <?php endif; ?>
                                <?php else: ?>
                                    <div style="margin-top: 10px;">
                                        <?php echo esc_html($order->customer->full_name); ?>
                                    </div>
                                    <div style="margin-top: 3px;">
                                        <?php echo esc_html($order->customer->email); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($order->order_items) : ?>
                        <table style="margin-top: 20px;margin-bottom: 10px;width: 100%;text-align: left;border-spacing: 0;border-collapse: collapse;border: none;">
                            <thead>
                            <tr>
                                <th style="background-color: #f8f9fa;padding: 12px 8px;font-weight: bold;print-color-adjust: exact;border: none;">
                                    <?php echo esc_html__('Description', 'fluent-cart'); ?>
                                </th>
                                <th style="background-color: #f8f9fa;padding: 12px 8px;font-weight: bold;width: 80px;text-align: right;print-color-adjust: exact;border: none;">
                                    <?php echo esc_html__('Qty', 'fluent-cart'); ?>
                                </th>
                                <th style="background-color: #f8f9fa;padding: 12px 8px;font-weight: bold;width: 80px;text-align: right;print-color-adjust: exact;border: none;">
                                    <?php echo esc_html__('Unit price', 'fluent-cart'); ?>
                                </th>
                                <th style="background-color: #f8f9fa;padding: 12px 8px;font-weight: bold;width: 80px;text-align: right;print-color-adjust: exact;border: none;">
                                    <?php echo esc_html__('Amount', 'fluent-cart'); ?>
                                </th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            $orderItems = $order->order_items->toArray();
                            $transaction = $order->getLatestTransaction();

                            foreach ($orderItems as $item) :
                                ?>
                                <tr>
                                    <td style="font-size:15px;padding: 12px 8px;border: none;border-bottom: 1px solid #dee2e6;print-color-adjust: exact;">
                                        <?php echo esc_html($item['post_title']); ?>
                                        <br>
                                        <?php if (!empty($item['title'])) : ?>
                                            <small style="font-size: 13px;color: #758195;">- <?php echo esc_html($item['title']); ?></small>
                                            <br>
                                        <?php endif; ?>
                                        <?php if (!empty($item['payment_info'])) : ?>
                                            <small style="font-size: 13px;color: #758195;"><?php echo esc_html($item['payment_info']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 8px;border: none;border-bottom: 1px solid #dee2e6;text-align: right;">
                                        <?php echo esc_html($item['quantity']); ?>
                                    </td>
                                    <td style="padding: 12px 8px;border: none;border-bottom: 1px solid #dee2e6;text-align: right">
                                        <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($item['unit_price'])); ?>
                                    </td>
                                    <td style="padding: 12px 8px;border: none;border-bottom: 1px solid #dee2e6;text-align: right">
                                        <?php echo esc_html($item['formatted_total']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div style="margin-top: 20px;">
                            <table style="max-width: 250px;width: 100%;margin-left: auto;border-collapse: collapse;background: #f8f9fa;print-color-adjust: exact;border: none;">
                                <tbody>
                                <?php
                                if ($order->subtotal != ($order->total_amount - $order->total_refund) || $order->tax_total > 0): ?>
                                    <tr>
                                        <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                                            <?php echo esc_html__('Subtotal', 'fluent-cart'); ?>
                                        </td>
                                        <td style="font-weight:700;padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->subtotal)); 
                                           ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ($order->manual_discount_total + $order->coupon_discount_total > 0): ?>
                                    <tr>
                                        <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                                            <?php echo esc_html__('Discount', 'fluent-cart'); ?>
                                        </td>
                                        <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                                            - <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->manual_discount_total + $order->coupon_discount_total)); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ($order->shipping_total > 0): ?>
                                    <tr>
                                        <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                                            <?php echo esc_html__('Shipping', 'fluent-cart'); ?>
                                        </td>
                                        <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->shipping_total)); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ($order->tax_total > 0): ?>
                                    <tr>
                                        <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                                            <?php echo esc_html__('Tax', 'fluent-cart');
                                            echo esc_html( $order->tax_behavior == 2 ? __('(Included)', 'fluent-cart') : __('(Excluded)', 'fluent-cart'));
                                            ?>
                                        </td>
                                        <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->tax_total)); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ($order->shipping_tax > 0): ?>
                                    <tr>
                                        <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                                            <?php echo esc_html__('Shipping Tax', 'fluent-cart');
                                            echo esc_html( $order->tax_behavior == 2 ? __('(Included)', 'fluent-cart') : __('(Excluded)', 'fluent-cart'));
                                            ?>
                                        </td>
                                        <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                                            <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->shipping_tax)); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php if ($order->total_refund > 0): ?>
                                    <tr style="font-weight: bold;font-size: 14px;">
                                        <td style="font-weight:500;padding: 8px 20px 8px 0;text-align: right;border:none;">
                                            <?php echo esc_html__('Refund', 'fluent-cart'); ?>
                                        </td>
                                        <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border:none;">
                                            - <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->total_refund)); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr style="font-weight: bold;font-size: 14px;">
                                    <td style="font-weight:500;padding: 8px 20px 8px 0;text-align: right;border:none;">
                                        <?php echo esc_html__('Total', 'fluent-cart'); ?>
                                    </td>
                                    <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border:none;">
                                        <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($order->total_amount - $order->total_refund)); ?>
                                    </td>
                                </tr>
                                <tr style="font-weight: bold;font-size: 14px;">
                                    <td style="font-weight:500;padding: 8px 20px 8px 0;text-align: right;border:none;">
                                        <?php echo esc_html__('Amount Paid', 'fluent-cart'); ?>
                                    </td>
                                    <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border:none;">
                                        <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($order->total_paid - $order->total_refund)); ?>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <!-- tax note -->
                    <?php 
                    $taxtotal = $order->tax_total + $order->shipping_tax;
                    if(Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.valid') && !$taxtotal): ?>

                        <div style="text-align: right; font-size: 14px; margin-top: 10px;">
                            <?php echo '*' . esc_html__('Tax to be paid on reverse charge basis', 'fluent-cart'); ?>
                        </div>

                    <?php endif ?>

                    <?php
                    $transactions = $order->transactions;
                    if (!empty($transactions)) :
                        ?>
                        <div style="margin-top: 30px;">
                            <div style="font-weight: bold;font-size: 14px;color: #495057;margin: 0;padding: 0;">
                                <?php echo esc_html__('Payment history', 'fluent-cart'); ?>
                            </div>
                            <table class="fct-transaction-table"
                                   style="margin-top: 10px;width: 100%;text-align: left;border-spacing: 0;border-collapse: collapse;border: none;">
                                <thead>
                                <tr>
                                    <th style="background-color: #f8f9fa;padding: 12px 8px;font-weight: bold;print-color-adjust: exact;border:none;">
                                        <?php echo esc_html__('Payment method', 'fluent-cart'); ?>
                                    </th>
                                    <th style="background-color: #f8f9fa;padding: 12px 8px;font-weight: bold; text-align: center;print-color-adjust: exact;border:none;">
                                        <?php echo esc_html__('Date', 'fluent-cart'); ?>
                                    </th>
                                    <th style="background-color: #f8f9fa;padding: 12px 8px;font-weight: bold; text-align: right;print-color-adjust: exact;border:none;">
                                        <?php echo esc_html__('Amount', 'fluent-cart'); ?>
                                    </th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($transactions as $transaction) :
                                    if ($transaction->transaction_type === 'refund') {
                                        continue;
                                    }
                                    ?>
                                    <tr>
                                        <td style="padding: 10px;border:none;border-bottom: 1px solid #dee2e6;">
                                            <?php echo esc_html($transaction->getPaymentMethodText()); ?>
                                        </td>
                                        <td style="padding: 10px;border:none;border-bottom: 1px solid #dee2e6;text-align: center;">
                                            <?php
                                            echo esc_html(
                                                date_i18n(
                                                /* translators: Date format for order creation date */
                                                    __('M d, Y', 'fluent-cart'),
                                                    DateTime::anyTimeToGmt($transaction->created_at)->getTimestamp()
                                                )
                                            );
                                            ?>
                                        </td>
                                        <td style="padding: 10px;border:none;border-bottom: 1px solid #dee2e6;text-align: right;">
                                            <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($transaction->total)); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
        <?php
        //        include(__DIR__ . '/../emails/components/cta.php')
        ?>
    </div>
</div>


<?php if ($is_first_time) {
    do_action('fluent_cart/order/receipt_viewed', [
            'order'           => $order,
            'order_operation' => $order_operation
    ]);
} ?>
