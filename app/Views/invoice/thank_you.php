<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Tax\TaxModule;

$settings = new StoreSettings();
$profilePage = $settings->getCustomerProfilePage();

$bgColor = '#d4edda';
$titleColor = '#155724';
$iconBg = '#155724bd';

if ($order->payment_status !== 'paid') {
    $bgColor = '#fff3cd';
    $titleColor = '#856404';
    $iconBg = '#856404bd';
}
?>

<div>
    <div style=" padding: 10px; border-radius: 5px;">
        <div class="email-template-content"
             style="font-family: Arial, Helvetica, sans-serif; max-width: 620px; margin: 0 auto; padding: 0px; width: 100%;">

            <div
                style="margin-bottom: 10px;background: <?php echo esc_attr($bgColor); ?>;padding: 30px 30px;border-radius: 4px;text-align: center;color: white;">
                <?php if ($order->payment_status !== 'paid') : ?>
                    <div
                        style="width: 80px;height: 80px;border-radius: 50%;background: <?php echo esc_attr($iconBg); ?>;display: inline-flex ;align-items: center;justify-content: center;margin-bottom: 20px;">
                        <svg style="width: 40px;height: 40px;" class="w-64 h-64" xmlns="http://www.w3.org/2000/svg"
                             viewBox="0 0 1024 1024" fill="currentColor">
                            <path
                                d="M480 674V192c0-18 14-32 32-32s32 14 32 32v482h-64zm0 63h64v60h-64v-60zM0 512C0 229 229 0 512 0s512 229 512 512-229 512-512 512S0 795 0 512zm961 0c0-247-202-448-449-448S64 265 64 512s201 448 448 448 449-201 449-448z"></path>
                        </svg>
                    </div>
                    <h1 style="font-size: 2em;line-height: 1;margin: 0;font-weight: 700;color:<?php echo esc_attr($titleColor); ?>">
                        <?php echo esc_html__('Payment Pending!', 'fluent-cart'); ?>
                    </h1>

                <?php else: ?>
                    <div
                        style="width: 80px;height: 80px;border-radius: 50%;background: <?php echo esc_attr($iconBg); ?>;display: inline-flex ;align-items: center;justify-content: center;margin-bottom: 20px;">
                        <svg style="width: 40px;height: 40px;" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20,6 9,17 4,12"></polyline>
                        </svg>
                    </div>
                    <h1 style="font-size: 2em;line-height: 1;margin: 0;font-weight: 700;color:<?php echo esc_attr($titleColor); ?>">
                        <?php echo esc_html__('Purchase Successful!', 'fluent-cart'); ?>
                    </h1>
                <?php endif; ?>
            </div>

            <div style="background-color: #fff; border-radius: 8px; border: 1px solid #e7eaee; ">
                <div style="padding: 20px;">
                    <div style="overflow-x: hidden">
                        <div style="background-color: #ffffff; padding: 0px;">

                            <div class="no-print" style="margin-bottom: 16px;text-align: left; padding: 0;">
                                <p style="font-size: 24px;font-weight: 600;line-height: 1.2; margin: 0 0 4px 0; color: #2F3448;">
                                    <?php
                                    echo sprintf(
                                        /* translators: %s is the customer's full name */
                                        esc_html__('Hello %s!', 'fluent-cart'),
                                        esc_html($order->customer->full_name)
                                    );
                                    ?>
                                </p>
                                <?php if ($order->payment_status === 'paid') : ?>
                                    <p style="font-size: 14px; margin: 0; color: #2F3448;">
                                        <?php
                                        printf(
                                            '%s<strong style="color: #007bff;"><a href="%s">#%s</a></strong>%s',
                                            esc_html__('Your order ', 'fluent-cart'),
                                            esc_url($profilePage . 'order/' . $order->uuid),
                                            esc_html($order->invoice_no),
                                            esc_html__(' has been placed successfully.', 'fluent-cart')
                                        );
                                        ?>
                                    </p>
                                <?php else: ?>
                                    <p style="font-size: 14px; margin: 0; color: #2F3448;">

                                        <?php
                                        printf(
                                            '<strong style="color: #007bff;"><a href="%s">%s</a></strong> %s <a style="color: #007bff;" target="_blank" href="%s">%s</a>.',
                                            esc_url($profilePage . 'order/' . $order->uuid),
                                            esc_html__('Your order', 'fluent-cart'),
                                            esc_html__('has payment due. You can pay from', 'fluent-cart'),
                                            esc_url(\FluentCart\App\Services\Payments\PaymentHelper::getCustomPaymentLink($order->uuid)),
                                            esc_html__('here', 'fluent-cart')
                                        );
                                        ?>

                                    </p>
                                <?php endif; ?>

                            </div>

                            <!-- show tax content  -->
                            <?php 
                             $orderTaxRates = $order->orderTaxRates->first();
                            if ($order->tax_total > 0 || $orderTaxRates): ?>
                                <div class="fct_invoice_tax_content" style="text-align: right;">
                                   <?php
                                        if ($orderTaxRates) {
                                            $storeVatNumber = Arr::get($orderTaxRates->meta, 'store_vat_number', '');
                                            $taxcountry = Arr::get($orderTaxRates->meta ?? [], 'tax_country', '');
                                            if ($storeVatNumber !== '') {
                                                echo '<p style="font-weight: 600; font-size: 14px;margin-bottom:12px;">' . esc_html(TaxModule::getCountryTaxTitle($taxcountry)) . ': ' . esc_html($storeVatNumber) . '</p>';
                                            }
                                        }
                                   ?>
                                </div>
                            <?php endif; ?>

                            

                            <?php if ($order->order_items) : ?>
                                <div>
                                    <div
                                        style="display: flex;justify-content: space-between;background: rgb(249,250,251);padding: 0 16px;">
                                        <div
                                            style="font-size: 12px;font-weight: 600;color: rgb(55, 65, 81);text-transform: uppercase;line-height: 24px;margin: 0;text-align: left;">
                                            <?php echo esc_html__('Item', 'fluent-cart'); ?>
                                        </div>
                                        <div
                                            style="width: 200px;text-align:right;font-size: 12px;font-weight: 600;color: rgb(55, 65, 81);text-transform: uppercase;line-height: 24px;margin: 0;">
                                            <?php echo esc_html__('Total', 'fluent-cart'); ?>
                                        </div>
                                    </div>

                                    <div>
                                        <?php
                                        $orderItems = $order->order_items->toArray();
                                        $transaction = $order->getLatestTransaction();

                                        foreach ($orderItems as $item) :
                                            ?>
                                            <div
                                                style="display: flex;align-items: flex-start;justify-content: space-between;padding: 8px 16px;">
                                                <div>
                                                    <p style="font-size: 15px; color: #2F3448; font-weight: 500; overflow: hidden; line-height: 18px; margin-top: 0; margin-bottom: 5px;">
                                                        <?php echo esc_html($item['post_title']); ?>
                                                        <?php if ($item['quantity'] > 1): ?>
                                                            <span
                                                                style="font-size:12px;font-weight:400;color:rgb(75,85,99)">x <?php echo esc_html($item['quantity']); ?></span>
                                                        <?php endif; ?>
                                                    </p>
                                                    <p style="margin: 0; font-size: 14px; color: #758195; font-weight: 400; line-height: 15px;">
                                                        - <?php echo esc_html($item['title']); ?>
                                                    </p>
                                                    <?php if ($item['payment_type'] === 'subscription' && !empty($item['payment_info'])): ?>
                                                        <p style="font-size:12px;color:rgb(75,85,99);line-height:20px;margin: 3px 0 0 0;">
                                                            <?php echo wp_kses_post($item['payment_info']) ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="width: 200px;text-align: right;">
                                                    <div
                                                        style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0;line-height:24px;">
                                                        <?php echo esc_html($item['formatted_total']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <div
                                            style="background-color:rgb(249,250,251);padding:16px;border-radius:8px;margin: 0; width: 100%;max-width: 290px;margin-left: auto;">
                                            <?php if ($order->subtotal != $order->total_amount || $order->tax_total > 0): ?>
                                                <div
                                                    style="display: flex;align-items: center; justify-content: space-between;">
                                                    <div
                                                        style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                                        <?php echo esc_html__('Subtotal', 'fluent-cart'); ?>
                                                    </div>
                                                    <div
                                                        style="text-transform:uppercase;font-size:13px;color:rgb(55,65,81);margin:0;line-height:24px;text-align: right"><?php echo esc_html(Helper::toDecimal($order->subtotal)); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($order->manual_discount_total + $order->coupon_discount_total > 0): ?>
                                                <div
                                                    style="display: flex;align-items: center; justify-content: space-between;">
                                                    <div
                                                        style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                                        <?php echo esc_html__('Discount', 'fluent-cart'); ?>
                                                    </div>
                                                    <div
                                                        style="text-transform:uppercase;font-size:13px;color:rgb(55,65,81);margin:0;line-height:24px;text-align: right">
                                                        - <?php echo esc_html(Helper::toDecimal($order->manual_discount_total + $order->coupon_discount_total)); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($order->shipping_total > 0): ?>
                                                <div
                                                    style="display: flex;align-items: center; justify-content: space-between;">
                                                    <div
                                                        style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                                        <?php echo esc_html__('Shipping', 'fluent-cart'); ?>
                                                    </div>
                                                    <div
                                                        style="text-transform:uppercase;font-size:13px;color:rgb(55,65,81);margin:0;line-height:24px;text-align: right"><?php echo esc_html(Helper::toDecimal($order->shipping_total)); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($order->tax_total > 0): ?>
                                                <div
                                                    style="display: flex;align-items: center; justify-content: space-between;">
                                                    <div
                                                        style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                                        <?php echo esc_html__('Total Tax', 'fluent-cart'); ?>
                                                        <?php echo $order->tax_behavior == 2 ? esc_html__('(Included)', 'fluent-cart') : esc_html__('(Excluded)', 'fluent-cart'); ?>
                                                    </div>
                                                    <div
                                                        style="text-transform:uppercase;font-size:13px;color:rgb(55,65,81);margin:0;line-height:24px;text-align: right">
                                                        <?php echo esc_html(Helper::toDecimal($order->tax_total)); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($order->shipping_tax > 0): ?>
                                                <div
                                                    style="display: flex;align-items: center; justify-content: space-between;">
                                                    <div
                                                        style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                                        <?php echo esc_html__('Shipping Tax', 'fluent-cart'); ?>
                                                        <?php echo $order->tax_behavior == 2 ? esc_html__('(Included)', 'fluent-cart') : esc_html__('(Excluded)', 'fluent-cart'); ?>
                                                    </div>
                                                    <div
                                                        style="text-transform:uppercase;font-size:13px;color:rgb(55,65,81);margin:0;line-height:24px;text-align: right">
                                                        <?php echo esc_html(Helper::toDecimal($order->shipping_tax)); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>


                                            <?php if ($order->total_refund > 0): ?>
                                                <div
                                                    style="display: flex;align-items: center; justify-content: space-between;">
                                                    <div
                                                        style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                                        <?php echo esc_html__('Refund', 'fluent-cart'); ?>
                                                    </div>
                                                    <div
                                                        style="text-transform:uppercase;font-size:13px;color:rgb(55,65,81);margin:0;line-height:24px;text-align: right">
                                                        - <?php echo esc_html(Helper::toDecimal($order->total_refund)); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <div
                                                style="display: flex;align-items: center; justify-content: space-between;">
                                                <div
                                                    style="font-size:16px;font-weight:700;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                                    <?php echo esc_html__('Total', 'fluent-cart'); ?>
                                                </div>
                                                <div
                                                    style="text-transform:uppercase;font-size:14px;font-weight:700;color:rgb(55,65,81);margin:0;line-height:24px;text-align: right">
                                                    <?php echo esc_html(Helper::toDecimal($order->total_amount - $order->total_refund)); ?>
                                                </div>
                                            </div>
                                            <div
                                                style="display: flex;align-items: center; justify-content: space-between;">
                                                <div
                                                    style="font-size:14px;color:rgb(55,65,81);line-height:24px;margin: 0;">
                                                    <?php echo esc_html__('Payment Method', 'fluent-cart'); ?>
                                                </div>
                                                <div
                                                    style="text-transform:uppercase;font-size:13px;color:rgb(55,65,81);margin:0;line-height:24px;text-align: right">
                                                    <?php if ($transaction->card_last_4) :
                                                        echo esc_html($transaction->card_brand) . ' ' . esc_html($transaction->card_last_4);
                                                    else:
                                                        echo esc_html($transaction->payment_method);
                                                    endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- tax note -->
                                        <?php
                                         $taxtotal = $order->tax_total + $order->shipping_tax;
                                         if(Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.valid') && !$taxtotal): ?>

                                            <div style="text-align: right; font-size: 14px; margin-top: 10px;">
                                                <?php echo '*' . esc_html__('Tax to be paid on reverse charge basis', 'fluent-cart'); ?>
                                            </div>

                                        <?php endif ?>


                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php

                            App::make('view')->render('invoice.parts.subscription_items', [
                                'subscriptions' => $order->subscriptions,
                                'order'         => $order
                            ])
                            ?>

                            <?php
                            $downloads = $order->getDownloads();
                            $licenses = $order->getLicenses();
                            if ($downloads) {
                                \FluentCart\App\App::make('view')->render('emails.parts.downloads', [
                                    'order'         => $order,
                                    'heading'       => 'Downloads',
                                    'downloadItems' => $downloads ?: [],
                                    'show_notice'   => false
                                ]);
                            }

                            if ($licenses && $licenses->count() > 0) {
                                \FluentCart\App\App::make('view')->render('emails.parts.licenses', [
                                    'licenses'    => $licenses,
                                    'heading'     => 'Licenses',
                                    'show_notice' => false
                                ]);
                            }
                            ?>

                            <?php if ($order->fulfillment_type === 'physical') : ?>
                                <div
                                    class="fct-thank-you-page-order-items-addresses"
                                    style="display: grid;grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));gap: 20px;margin-top: 20px;">

                                    <div class="fct-thank-you-page-order-items-addresses-bill-to" style="font-size:14px;border-radius: 5px;">
                                        <h5 style="font-weight: bold;font-size: 14px;margin: 0 0 10px 0;color: #495057;border-bottom: 1px solid #dee2e6;padding-bottom: 5px;">
                                            <?php echo esc_html__('Bill To', 'fluent-cart'); ?>
                                        </h5>
                                        <?php
                                        if (!empty($order->billing_address)) :
                                            ?>
                                            <div
                                                class="fct-thank-you-page-order-items-addresses-bill-to-address"
                                                style="margin-bottom: 3px;">
                                                <?php echo esc_html($order->billing_address->getAddressAsText()); ?>
                                            </div>

                                            <div
                                                class="fct-thank-you-page-order-items-addresses-bill-to-email"
                                                style="margin-top: 10px;">
                                                <?php echo esc_html($order->customer->email); ?>
                                            </div>
                                        <?php else: ?>
                                            <div
                                                class="fct-thank-you-page-order-items-addresses-bill-to-name"
                                                style="margin-top: 10px;">
                                                <?php echo esc_html($order->customer->full_name); ?>
                                            </div>
                                            <div
                                                class="fct-thank-you-page-order-items-addresses-bill-to-email"
                                                style="margin-top: 3px;">
                                                <?php echo esc_html($order->customer->email); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php
                                        $phoneNumber = Arr::get($order->billing_address->meta ?? [], 'other_data.phone', '');
                                        if ($phoneNumber !== ''):
                                        ?>
                                            <div class="fct-thank-you-page-order-items-addresses-bill-to-phone">
                                                <?php echo esc_html($phoneNumber); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="fct-thank-you-page-order-items-addresses-bill-to-company-name">
                                            <?php
                                               $companyName = Arr::get($order->billing_address->meta ?? [], 'other_data.company_name', '');

                                               if ($companyName === '') {
                                                    $companyName = Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.name', '');
                                               }
                                                echo esc_html($companyName);
                                            ?>
                                        </div>
                                        <div class="fct-thank-you-page-order-items-addresses-bill-to-vat-number">
                                            <?php
                                               $vatNumber = Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.vat_number', '');
                                               if ($vatNumber !== '') {
                                                echo esc_html__('EU VAT', 'fluent-cart') . ': ' . esc_html($vatNumber);
                                               }
                                            ?>
                                        </div>
                                    </div>
                                    <div
                                        class="fct-thank-you-page-order-items-addresses-ship-to"
                                         style="font-size:14px;border-radius: 5px;">
                                        <h5 class="fct-thank-you-page-order-items-addresses-ship-to-title" style="font-weight: bold;font-size: 14px;margin: 0 0 10px 0;color: #495057;border-bottom: 1px solid #dee2e6;padding-bottom: 5px;">
                                            <?php echo esc_html__('Ship To', 'fluent-cart'); ?>
                                        </h5>
                                        <?php if (!empty($order->shipping_address)) : ?>
                                            <div
                                                class="fct-thank-you-page-order-items-addresses-ship-to-address"
                                                style="margin-bottom: 3px;">
                                                <?php echo esc_html($order->shipping_address->getAddressAsText()); ?>
                                            </div>
                                            <!-- show phone number -->
                                            <?php
                                            $phone = Arr::get($order->shipping_address->meta ?? [], 'other_data.phone', '');
                                            if ($phone !== ''):?>
                                                <div class="fct-thank-you-page-order-items-addresses-ship-to-phone">
                                                    <?php echo esc_html($phone); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div
                                                class="fct-thank-you-page-order-items-addresses-ship-to-name"
                                                style="margin-top: 10px;">
                                                <?php echo esc_html($order->customer->full_name); ?>
                                            </div>
                                            <div
                                                class="fct-thank-you-page-order-items-addresses-ship-to-email"
                                                style="margin-top: 3px;">
                                                <?php echo esc_html($order->customer->email); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>

        </div>
        <?php
        //        include(__DIR__ . '/../emails/components/cta.php')
        ?>
    </div>
</div>

<div style="text-align: center;margin-top: 10px;display: flex;align-items: center;justify-content: center;gap: 10px;">
    <a href="<?php echo esc_url($profilePage . 'order/' . $order->uuid); ?>"
       style="background: var(--fluent-cart-primary-color, #253241); color: #fff; padding: 7px 20px; border-radius: 8px; border: none; font-size: 16px; font-weight: 500; text-decoration:none;display:inline-block;">
        <?php echo esc_html__('View Order', 'fluent-cart'); ?>
    </a>
    <a href="<?php echo esc_url(\FluentCart\App\Services\URL::appendQueryParams(
        home_url(),
        [
            'fluent-cart' => 'receipt',
            'order_hash'  => esc_attr($order->uuid),
            'download'    => 1
        ]
    )) ?>"
       style="background: var(--fluent-cart-primary-color, #253241);font-size:14px;color:#000;background: none;font-weight: 600;display:inline-block;">
        <?php echo esc_html__('Download Receipt', 'fluent-cart'); ?>
    </a>
</div>

<?php do_action('fluent_cart/after_receipt', [
    'order'           => $order,
    'is_first_time'   => $is_first_time ?? false,
    'order_operation' => $order_operation ?? null
]);
?>

<?php
if (!empty($is_first_time)) {
    do_action('fluent_cart/after_receipt_first_time', [
        'order'           => $order,
        'order_operation' => $order_operation ?? null
    ]);
}
?>
