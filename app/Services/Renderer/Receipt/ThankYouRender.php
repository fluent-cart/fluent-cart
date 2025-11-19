<?php

namespace FluentCart\App\Services\Renderer\Receipt;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ThankYouRender
{

    protected $config = null;

    protected $settings = null;

    protected $is_first_time = false;

    protected $order_operation = null;


    public function __construct($config)
    {
        $this->config = $config;

        $this->is_first_time = Arr::get($this->config, 'is_first_time', false);
        $this->order_operation = Arr::get($this->config, 'order_operation', false);

        $this->settings = new StoreSettings();
        AssetLoader::enqueueThankYouPageAssets();

    }

    public function renderWrapperStart()
    {
        ?>
        <div class="fct-thank-you-page">
        <div class="fct-thank-you-page-inner">
        <div class="fct-thank-you-page-content email-template-content">
        <?php
    }

    public function renderWrapperEnd()
    {
        ?>
        </div>
        </div>
        </div>
        <?php
    }

    public function render($hide_wrapper = false)
    {
        $order = Arr::get($this->config, 'order', null);

        if (!$order) {
            return;
        }
        ?>
        <?php if (!$hide_wrapper) {
        $this->renderWrapperStart();
    } ?>
        <?php do_action('fluent_cart/receipt/thank_you/before_header', $this->config); ?>
        <?php $this->renderHeader(); ?>
        <?php do_action('fluent_cart/receipt/thank_you/after_header', $this->config); ?>

        <?php do_action('fluent_cart/receipt/thank_you/before_body', $this->config); ?>
        <?php $this->renderBody(); ?>
        <?php do_action('fluent_cart/receipt/thank_you/after_body', $this->config); ?>
        <?php if (!$hide_wrapper) {
        $this->renderWrapperEnd();
    } ?>

        <?php

        $this->renderFooter();

        do_action('fluent_cart/after_receipt', [
            'order'           => $order,
            'is_first_time'   => $this->is_first_time ?? false,
            'order_operation' => $this->order_operation ?? null
        ]);

        if (!empty($this->is_first_time)) {
            do_action('fluent_cart/after_receipt_first_time', [
                'order'           => $order,
                'order_operation' => $this->order_operation ?? null
            ]);
        }
    }

    public function renderHeader()
    {
        $bgColor = '#d4edda';
        $titleColor = '#155724';
        $iconBg = '#155724bd';

        $order = Arr::get($this->config, 'order', null);

        if ($order->payment_status !== 'paid') {
            $bgColor = '#fff3cd';
            $titleColor = '#856404';
            $iconBg = '#856404bd';
        }
        ?>
        <div class="fct-thank-you-page-header" style="background: <?php echo esc_attr($bgColor); ?>;">
            <?php if ($order->payment_status !== 'paid') : ?>
                <div class="fct-thank-you-page-header-icon" style="background: <?php echo esc_attr($iconBg); ?>;">
                    <svg class="w-64 h-64" xmlns="http://www.w3.org/2000/svg"
                         viewBox="0 0 1024 1024" fill="currentColor">
                        <path
                                d="M480 674V192c0-18 14-32 32-32s32 14 32 32v482h-64zm0 63h64v60h-64v-60zM0 512C0 229 229 0 512 0s512 229 512 512-229 512-512 512S0 795 0 512zm961 0c0-247-202-448-449-448S64 265 64 512s201 448 448 448 449-201 449-448z"></path>
                    </svg>
                </div>
                <h1 class="fct-thank-you-page-header-title" style="color:<?php echo esc_attr($titleColor); ?>">
                    <?php echo esc_html__('Payment Pending!', 'fluent-cart'); ?>
                </h1>

            <?php else: ?>
                <div class="fct-thank-you-page-header-icon" style="background: <?php echo esc_attr($iconBg); ?>;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20,6 9,17 4,12"></polyline>
                    </svg>
                </div>
                <h1 class="fct-thank-you-page-header-title" style="color:<?php echo esc_attr($titleColor); ?>;">
                    <?php echo esc_html__('Purchase Successful!', 'fluent-cart'); ?>
                </h1>
            <?php endif; ?>

            <?php do_action('fluent_cart/receipt/thank_you/after_header_title', $this->config); ?>

        </div>

        <?php
    }

    public function renderBody()
    {
        $order = Arr::get($this->config, 'order', null);

        if (!$order) {
            return;
        }
        ?>
        <div class="fct-thank-you-page-body">
            <div class="fct-thank-you-page-body-inner">
                <div class="fct-thank-you-page-body-content">
                    <div class="fct-thank-you-page-body-content-inner">
                        <?php do_action('fluent_cart/receipt/thank_you/before_order_header', $this->config); ?>
                        <?php $this->renderOrderHeader(); ?>
                        <?php do_action('fluent_cart/receipt/thank_you/after_order_header', $this->config); ?>

                        <?php $this->renderStoreTaxInformation(); ?>

                        <?php do_action('fluent_cart/receipt/thank_you/before_order_items', $this->config); ?>
                        <?php $this->renderOrderItems(); ?>
                        <?php do_action('fluent_cart/receipt/thank_you/after_order_items', $this->config); ?>

                        <?php $this->renderSubscriptionItems(); ?>

                        <?php $this->renderDownloads(); ?>

                        <?php $this->renderLicenses(); ?>

                        <?php $this->renderAddress(); ?>

                    </div>
                </div>
            </div>
        </div>

        <?php
    }

    public function renderOrderHeader()
    {
        $order = Arr::get($this->config, 'order', null);
        $profilePage = $this->settings->getCustomerProfilePage();
        ?>
        <div class="no-print">
            <div class="no-print-title">
                <?php
                echo sprintf(
                        /* translators: %s is the customer's full name */
                    esc_html__('Hello %s!', 'fluent-cart'),
                    esc_html($order->customer->full_name)
                );
                ?>
            </div>
            <?php if ($order->payment_status === 'paid') : ?>
                <p>
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
                <p>

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

        <?php
    }

    public function renderStoreTaxInformation()
    {
        $order = Arr::get($this->config, 'order', null);

        $orderTaxRates = $order->orderTaxRates->first();
        if ($order->tax_total > 0 || $orderTaxRates): ?>
            <div class="fct_invoice_tax_content">
                <?php
                if ($orderTaxRates) {
                    $storeVatNumber = Arr::get($orderTaxRates->meta, 'store_vat_number', '');
                    $taxcountry = Arr::get($orderTaxRates->meta ?? [], 'tax_country', '');
                    if ($storeVatNumber !== '') {
                        echo '<p>' . esc_html(TaxModule::getCountryTaxTitle($taxcountry)) . ': ' . esc_html($storeVatNumber) . '</p>';
                    }
                }
                ?>
            </div>
        <?php endif;
    }

    public function renderOrderItems()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->order_items) : ?>
            <div class="fct-thank-you-page-order-items">
                <?php $this->renderOrderItemsHeader(); ?>

                <?php $this->renderOrderItemsBody(); ?>
            </div>
        <?php endif;
    }

    public function renderOrderItemsHeader()
    {
        ?>
        <div class="fct-thank-you-page-order-items-header">
            <div class="fct-thank-you-page-order-items-header-row">
                <?php echo esc_html__('Item', 'fluent-cart'); ?>
            </div>
            <div class="fct-thank-you-page-order-items-header-row">
                <?php echo esc_html__('Total', 'fluent-cart'); ?>
            </div>
        </div>
        <?php
    }

    public function renderOrderItemsBody()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <div class="fct-thank-you-page-order-items-body">
            <?php
            $orderItems = $order->order_items->toArray();

            foreach ($orderItems as $item) :
                ?>
                <div class="fct-thank-you-page-order-items-list">
                    <div class="fct-thank-you-page-order-items-list-title">
                        <p class="fct-thank-you-page-order-items-list-quantity">
                            <?php echo esc_html($item['post_title']); ?>
                            <?php if ($item['quantity'] > 1): ?>
                                <span>x <?php echo esc_html($item['quantity']); ?></span>
                            <?php endif; ?>
                        </p>
                        <p class="fct-thank-you-page-order-items-list-variant-title">
                            - <?php echo esc_html($item['title']); ?>
                        </p>
                        <?php if ($item['payment_type'] === 'subscription' && !empty($item['payment_info'])): ?>
                            <p class="fct-thank-you-page-order-items-list-payment-info">
                                <?php echo wp_kses_post($item['payment_info']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="fct-thank-you-page-order-items-list-price">
                        <div class="fct-thank-you-page-order-items-list-price-inner">
                            <?php echo esc_html($item['formatted_total']); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php $this->renderOrderTotal(); ?>

            <!-- tax note -->
            <?php $this->renderTaxNote(); ?>

        </div>
        <?php
    }

    public function renderOrderTotal()
    {
        ?>
        <div class="fct-thank-you-page-order-items-total">
            <?php $this->renderSubtotal(); ?>
            <?php $this->renderDiscount(); ?>
            <?php $this->renderShipping(); ?>
            <?php $this->renderTaxTotal(); ?>
            <?php $this->renderShippingTax(); ?>

            <?php $this->renderRefund(); ?>
            <?php $this->renderTotal(); ?>
            <?php $this->renderPaymentMethod(); ?>
        </div>
        <?php
    }

    public function renderTaxNote()
    {
        $order = Arr::get($this->config, 'order', null);
        $taxtotal = $order->tax_total + $order->shipping_tax;
        if (Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.valid') && !$taxtotal): ?>
            <div style="text-align: right; font-size: 14px; margin-top: 10px;">
                <?php echo '*' . esc_html__('Tax to be paid on reverse charge basis', 'fluent-cart') ?>
            </div>
        <?php
        endif;
    }

    public function renderSubtotal()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->subtotal != $order->total_amount || $order->tax_total > 0): ?>
            <div class="fct-thank-you-page-order-items-total-subtotal">
                <div class="fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html__('Subtotal', 'fluent-cart'); ?>
                </div>
                <div class="fct-thank-you-page-order-items-total-value"><?php echo esc_html(Helper::toDecimal($order->subtotal)); ?></div>
            </div>
        <?php endif;
    }

    public function renderDiscount()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->manual_discount_total + $order->coupon_discount_total > 0): ?>
            <div class="fct-thank-you-page-order-items-total-discount">
                <div class="fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html__('Discount', 'fluent-cart'); ?>
                </div>
                <div class="fct-thank-you-page-order-items-total-value">
                    - <?php echo esc_html(Helper::toDecimal($order->manual_discount_total + $order->coupon_discount_total)); ?>
                </div>
            </div>
        <?php endif;
    }

    public function renderShipping()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->shipping_total > 0): ?>
            <div class="fct-thank-you-page-order-items-total-shipping">
                <div class="fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html__('Shipping', 'fluent-cart'); ?>
                </div>
                <div class="fct-thank-you-page-order-items-total-value"><?php echo esc_html(Helper::toDecimal($order->shipping_total)); ?></div>
            </div>
        <?php endif;
    }

    public function renderTaxTotal()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->tax_total > 0): ?>
            <div class="fct-thank-you-page-order-items-total-tax">
                <div class="fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html__('Total Tax', 'fluent-cart'); ?>
                    <?php echo $order->tax_behavior == 2 ? esc_html__('(Included)', 'fluent-cart') : esc_html__('(Excluded)', 'fluent-cart'); ?>
                </div>
                <div class="fct-thank-you-page-order-items-total-value">
                    <?php echo esc_html(Helper::toDecimal($order->tax_total)); ?>
                </div>
            </div>
        <?php endif;
    }

    public function renderShippingTax()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->shipping_tax > 0): ?>
            <div class="fct-thank-you-page-order-items-total-shipping-tax">
                <div class="fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html__('Shipping Tax', 'fluent-cart'); ?>
                    <?php echo $order->tax_behavior == 2 ? esc_html__('(Included)', 'fluent-cart') : esc_html__('(Excluded)', 'fluent-cart'); ?>
                </div>
                <div class="fct-thank-you-page-order-items-total-value">
                    <?php echo esc_html(Helper::toDecimal($order->shipping_tax)); ?>
                </div>
            </div>
        <?php endif;
    }

    public function renderRefund()
    {
        $order = Arr::get($this->config, 'order', null);

        if ($order->total_refund > 0): ?>
            <div class="fct-thank-you-page-order-items-total-refund">
                <div class="fct-thank-you-page-order-items-total-label">
                    <?php echo esc_html__('Refund', 'fluent-cart'); ?>
                </div>
                <div class="fct-thank-you-page-order-items-total-value">
                    - <?php echo esc_html(Helper::toDecimal($order->total_refund)); ?>
                </div>
            </div>
        <?php endif;
    }

    public function renderTotal()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <div class="fct-thank-you-page-order-items-total-total">
            <div class="fct-thank-you-page-order-items-total-label">
                <?php echo esc_html__('Total', 'fluent-cart'); ?>
            </div>
            <div class="fct-thank-you-page-order-items-total-value">
                <?php echo esc_html(Helper::toDecimal($order->total_amount - $order->total_refund)); ?>
            </div>
        </div>
        <?php
    }

    public function renderPaymentMethod()
    {
        $order = Arr::get($this->config, 'order', null);
        $transaction = $order->getLatestTransaction();
        ?>
        <div class="fct-thank-you-page-order-items-total-payment-method">
            <div class="fct-thank-you-page-order-items-total-label">
                <?php echo esc_html__('Payment Method', 'fluent-cart'); ?>
            </div>
            <div class="fct-thank-you-page-order-items-total-value">
                <?php if ($transaction->card_last_4) :
                    echo esc_html($transaction->card_brand) . ' ' . esc_html($transaction->card_last_4);
                else:
                    echo esc_html($transaction->payment_method);
                endif; ?>
            </div>
        </div>
        <?php
    }

    public function renderSubscriptionItems()
    {
        $order = Arr::get($this->config, 'order', null);
        $subscriptions = $order->subscriptions;

        if ($subscriptions->count() == 0) {
            return;
        }
        ?>
        <div class="fct-thank-you-page-order-items-subscriptions">
            <p class="fct-thank-you-page-order-items-subscriptions-heading">
                <?php echo esc_html__('Subscription Details', 'fluent-cart'); ?>
            </p>

            <table class="fct-thank-you-page-order-items-subscriptions-table" role="presentation">
                <tbody>
                <?php foreach ($subscriptions as $subs): ?>
                    <tr>
                        <td class="fct-thank-you-page-order-items-subscriptions-table-file">
                            <p>
                                <?php echo esc_html($subs->item_name); ?>
                            </p>
                        </td>

                        <td class="fct-thank-you-page-order-items-subscriptions-billing-infos">
                            <div>
                                <?php if (!empty($subs->payment_info)) : ?>
                                    <span>
                                        <?php echo $subs->payment_info; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($subs->next_billing_date)) : ?>
                                    <p class="fct-thank-you-page-order-items-subscriptions-billing-infos-next-billing"><?php
                                        echo sprintf(
                                            /* translators: 1: Next billing date */
                                                esc_html__('- Auto renews on %1$s', 'fluent-cart'),
                                                esc_html(
                                                        \FluentCart\App\Services\DateTime\DateTime::anyTimeToGmt($subs->next_billing_date)->format('M d, Y h:i A')
                                                )
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>


        <?php
//        App::make('view')->render('invoice.parts.subscription_items', [
//            'subscriptions' => $order->subscriptions,
//            'order'         => $order
//        ]);
    }

    public function renderDownloads()
    {
        $order = Arr::get($this->config, 'order', null);
        $downloads = $order->getDownloads();
        if (!$downloads) {
            return;
        }
        ?>
        <div class="fct-thank-you-page-order-items-downloads">
            <p class="fct-thank-you-page-order-items-downloads-heading">
                <?php echo esc_html__('Downloads', 'fluent-cart'); ?>
            </p>

            <table class="fct-thank-you-page-order-items-downloads-table"
                   role="presentation">
                <tbody>
                <tr>
                    <td>
                        <table role="presentation" width="100%">
                            <tbody>
                            <?php foreach ($downloads as $downloadItem): ?>
                                <tr>
                                    <td>
                                        <?php if ($downloadItem['downloads']): ?>

                                            <table role="presentation">
                                                <tbody>
                                                <?php foreach ($downloadItem['downloads'] as $download): ?>
                                                    <tr>
                                                        <td class="fct-thank-you-page-order-items-downloads-table-file">
                                                            <p>
                                                                <?php echo esc_html($download['title']); ?>
                                                                <?php if ($download['file_size']): ?>
                                                                    <span>(<?php echo esc_html(Helper::readableFileSize($download['file_size'])); ?>)</span>
                                                                <?php endif; ?>
                                                            </p>
                                                        </td>
                                                        <td class="fct-thank-you-page-order-items-downloads-button">
                                                            <a href="<?php echo esc_url($download['download_url'] ?? ''); ?>">
                                                                <?php echo esc_html__('Download', 'fluent-cart'); ?>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>

                                        <?php endif; ?>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <?php $this->renderDownloadsNotice(); ?>

        <?php
    }

    public function renderDownloadsNotice($show_notice = false)
    {
        $showNotice = $show_notice ?? true;
        ?>
        <?php if (!$showNotice) {
            return;
        } ?>
        <table
            class="fct-thank-you-page-order-items-downloads-notice"
            role="presentation">
            <tbody>
            <tr>
                <td>
                    <p class="fct-thank-you-page-order-items-downloads-notice-title">
                        <?php echo esc_html__('Important', 'fluent-cart'); ?>
                    </p>
                    <p class="fct-thank-you-page-order-items-downloads-notice-content">
                        <?php echo esc_html__('This download link is valid for 7 days. After that, you can download the files again from your account
                        on our website.', 'fluent-cart'); ?>
                    </p>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
    }

    public function renderLicenseNotice($show_notice = false)
    {
        $showNotice = $show_notice ?? true;
        ?>
        <?php if (!$showNotice) {
            return;
        } ?>
        <table
            class="fct-thank-you-page-order-items-downloads-notice"
            role="presentation">
            <tbody>
            <tr>
                <td>
                    <p class="fct-thank-you-page-order-items-downloads-notice-title">
                        <?php echo esc_html__('Important', 'fluent-cart'); ?>
                    </p>
                    <p class="fct-thank-you-page-order-items-downloads-notice-content">
                        <?php echo esc_html__('This download link is valid for 7 days. After that, you can download the files again from your account on our website.', 'fluent-cart'); ?>
                    </p>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
    }

    public function renderLicenses()
    {
        $order = Arr::get($this->config, 'order', null);
        $licenses = $order->getLicenses();
        if (!$licenses || $licenses->count() == 0) {
            return;
        }
        ?>
        <div class="fct-thank-you-page-order-items-licenses">
            <p class="fct-thank-you-page-order-items-licenses-heading">
                <?php echo esc_html__('Licenses', 'fluent-cart'); ?>
            </p>
            <table class="fct-thank-you-page-order-items-licenses-table" role="presentation">
                <tbody>
                <tr>
                    <td>
                        <table role="presentation">
                            <tbody>

                            <?php foreach ($licenses as $license): ?>
                                <tr>
                                    <td class="fct-thank-you-page-order-items-licenses-table-file">
                                        <p>
                                            <?php  echo esc_html($license->productVariant->variation_title); ?>:
                                            <?php echo esc_html($license->license_key); ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <?php $this->renderLicenseNotice(); ?>
        <?php
    }

    public function renderAddress()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->fulfillment_type === 'physical') : ?>
            <div class="fct-thank-you-page-order-items-addresses">
                <?php $this->renderBillToAddress(); ?>
                <?php $this->renderShipToAddress(); ?>
            </div>
        <?php endif;
    }

    public function renderBillToAddress()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <div class="fct-thank-you-page-order-items-addresses-bill-to">
            <h5>
                <?php echo esc_html__('Bill To', 'fluent-cart'); ?>
            </h5>
            <?php
            if (!empty($order->billing_address)) :
                ?>
                <div class="fct-thank-you-page-order-items-addresses-bill-to-address">
                    <?php echo esc_html($order->billing_address->getAddressAsText()); ?>
                </div>

                <div class="fct-thank-you-page-order-items-addresses-bill-to-email">
                    <?php echo esc_html($order->customer->email); ?>
                </div>
            <?php else: ?>
                <div class="fct-thank-you-page-order-items-addresses-bill-to-name">
                    <?php echo esc_html($order->customer->full_name); ?>
                </div>
                <div class="fct-thank-you-page-order-items-addresses-bill-to-email">
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
        <?php
    }

    public function renderShipToAddress()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <div class="fct-thank-you-page-order-items-addresses-ship-to">
            <h5 class="fct-thank-you-page-order-items-addresses-ship-to-title">
                <?php echo esc_html__('Ship To', 'fluent-cart'); ?>
            </h5>
            <?php if (!empty($order->shipping_address)) : ?>
                <div class="fct-thank-you-page-order-items-addresses-ship-to-address">
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
                <div class="fct-thank-you-page-order-items-addresses-ship-to-name">
                    <?php echo esc_html($order->customer->full_name); ?>
                </div>
                <div class="fct-thank-you-page-order-items-addresses-ship-to-email">
                    <?php echo esc_html($order->customer->email); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderFooter()
    {
        ?>
        <div class="fct-thank-you-page-footer">
            <?php do_action('fluent_cart/receipt/thank_you/before_footer_buttons', $this->config); ?>
            <?php $this->renderViewOrderButton(); ?>
            <?php $this->renderDownloadReceiptButton(); ?>
            <?php do_action('fluent_cart/receipt/thank_you/after_footer_buttons', $this->config); ?>
        </div>
        <?php
    }

    public function renderViewOrderButton()
    {
        $order = Arr::get($this->config, 'order', null);
        $profilePage = $this->settings->getCustomerProfilePage();

        ?>
        <a
            class="fct-thank-you-page-view-order-button"
            href="<?php echo esc_url($profilePage . 'order/' . $order->uuid); ?>">
            <?php echo esc_html__('View Order', 'fluent-cart'); ?>
        </a>
        <?php
    }

    public function renderDownloadReceiptButton()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <a
            class="fct-thank-you-page-download-receipt-button"
            href="<?php echo esc_url(\FluentCart\App\Services\URL::appendQueryParams(
                home_url(),
                [
                    'fluent-cart' => 'receipt',
                    'order_hash'  => $order->uuid,
                    'download'    => 1
                ]
            )) ?>">
            <?php echo esc_html__('Download Receipt', 'fluent-cart'); ?>
        </a>
        <?php
    }

}
