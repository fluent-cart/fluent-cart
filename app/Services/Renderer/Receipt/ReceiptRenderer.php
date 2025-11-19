<?php

namespace FluentCart\App\Services\Renderer\Receipt;

use FluentCart\App\Models\OrderMeta;
use FluentCart\Framework\Support\Arr;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\DateTime\DateTime;

class ReceiptRenderer
{
    protected $config = null;

    protected $is_first_time = false;

    protected $order_operation = null;

    protected $settings = null;

    protected $vat_tax_id = null;

    public function __construct($config = [])
    {
        $this->config = $config;

        $this->is_first_time = Arr::get($this->config, 'is_first_time', false);
        $this->order_operation = Arr::get($this->config, 'order_operation', false);

        $this->settings = new StoreSettings();

    }

    public function wrapperStart()
    {
        ?>
        <div
        class="fct-receipt-page"
        style="background-color: #fff;max-width: 620px;margin-left: auto;margin-right: auto;border: 1px solid #e7eaee;padding: 10px;border-radius: 8px;">
        <div
        class="fct-receipt-page-inner"
        style=" border-radius: 5px;" id="receipt">
        <div class="fct-email-template-content email-template-content"
        style="font-family: Arial, Helvetica, sans-serif; margin: 0 auto; padding: 0px; width: 100%;">
        <div class="fct-email-template-content-inner" style="font-size: 13px;line-height: 1.4;color: #333;padding: 20px;">

        <?php
    }

    public function wrapperEnd()
    {
        ?>
        </div>
        </div>
        </div>
        </div>
        <?php
    }

    public function render($hideWrapper = false)
    {

        $order = Arr::get($this->config, 'order', null);
        if (!$order) {
            return;
        }

        $vatTaxId = OrderMeta::query()->where('order_id', $order->id)->where('meta_key', 'vat_tax_id')->first();
        $this->vat_tax_id = $vatTaxId ? $vatTaxId->meta_value : '';

        $this->settings = new StoreSettings();
        $profilePage = $this->settings->getCustomerProfilePage();
        $orderTaxRates = $order->orderTaxRates->first();

        ?>

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

        <?php if (!$hideWrapper) {
        $this->wrapperStart();
    } ?>
        <?php $this->renderHeader(); ?>

        <?php $this->renderAddresses(); ?>

        <?php $this->renderOrderItems(); ?>

        <!-- tax note -->
        <?php $this->renderTaxNote(); ?>

        <?php $this->renderPaymentHistory(); ?>
        <?php if (!$hideWrapper) {
        $this->wrapperEnd();
    } ?>


        <?php if ($this->is_first_time) {
        do_action('fluent_cart/order/receipt_viewed', [
                'order'           => $order,
                'order_operation' => $this->order_operation
        ]);
    } ?>
        <?php
    }

    public function renderHeader()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <table
                class="fct-receipt-header"
                role="presentation"
                style="width: 100%;border-collapse: collapse;margin-bottom: 10px;border: none;">
            <tbody style="width:100%">
            <tr style="width:100%">
                <td style="width:60%;border: none;">
                    <?php $this->renderHeaderLogo(); ?>

                    <!-- show tax content -->
                    <?php $this->renderStoreTaxInformation(); ?>
                </td>
                <td
                        class="fct-receipt-header-date"
                        style="vertical-align: middle; width: 15%; text-align: right; border: none;padding-right: 10px;">
                    <?php $this->renderHeaderOrderDate(); ?>
                </td>
                <td
                        class="fct-receipt-header-invoice-no"
                        style="width:15%;text-align:right;border:none;vertical-align:baseline;">
                    <?php $this->renderHeaderInvoiceNo(); ?>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
    }

    public function renderHeaderLogo()
    {
        ?>
        <h1 style="font-size:24px;font-weight:700;color:rgb(17,24,39);margin:0px">
            <?php
            $imageLink = $this->settings->get('store_logo.url');
            $storeName = $this->settings->get('store_name');
            if (empty($imageLink)) {
                echo esc_html($storeName);
            } else {
                echo "<img src=".esc_url($imageLink)." alt=".esc_attr($storeName)." style='max-height: 40px;'>";
            }
            ?>
        </h1>
        <?php
    }

    public function renderStoreTaxInformation()
    {
        $order = Arr::get($this->config, 'order', null);
        $orderTaxRates = $order->orderTaxRates->first();
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
        <?php endif;
    }

    public function renderHeaderOrderDate()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <p style="white-space: nowrap; color: #94a3b8; text-align: right; margin: 0;font-size:12px;print-color-adjust: exact;">
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
        <?php
    }

    public function renderHeaderInvoiceNo()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <p style="white-space: nowrap; color: #94a3b8; text-align: right; margin: 0;font-size:12px;print-color-adjust: exact;">
            <?php echo esc_html__('Invoice number #', 'fluent-cart'); ?>
        </p>
        <p id="fct-order-invoice-no"
           style="white-space: nowrap; font-weight: bold; color: #000; text-align: right; margin: 0;font-size:14px;">
            <?php echo esc_html($order->invoice_no); ?>
        </p>
        <?php
    }

    public function renderAddresses()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>

        <?php if ($order->fulfillment_type === 'physical') : ?>
        <div
                class="fct-receipt-page-store-address"
                style="background: #f8f9fa;padding: 15px;margin-bottom: 20px;print-color-adjust: exact;">
            <?php $this->renderStoreAddress(); ?>
        </div>
    <?php endif; ?>
        <div
                class="fct-receipt-page-order-items-addresses"
                style="display: grid;grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));gap: 20px;">
            <?php if ($order->fulfillment_type === 'digital') : ?>
                <div
                        class="fct-receipt-page-store-address"
                        style="border-radius: 5px;print-color-adjust: exact;">
                    <?php $this->renderStoreAddress(true); ?>
                </div>
            <?php endif; ?>
            <?php $this->renderBillToAddress(); ?>
            <?php if ($order->fulfillment_type === 'physical') : ?>
                <?php $this->renderShipToAddress(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderStoreAddress($title_border = false)
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <?php if ($title_border) : ?>
        <h5
                class="fct-receipt-page-store-address-name"
                style="font-weight: bold;font-size: 14px;margin: 0 0 10px 0;color: #495057;border-bottom: 1px solid #dee2e6;padding-bottom: 5px;">
            <?php echo esc_html($this->settings->get('store_name')); ?>
        </h5>
    <?php else: ?>
        <div
                class="fct-receipt-page-store-address-name"
                style="font-size: 18px;font-weight: bold;margin-bottom: 8px;">
            <?php echo esc_html($this->settings->get('store_name')); ?>
        </div>
    <?php endif; ?>
        <div
                class="fct-receipt-page-store-address-address"
                style="margin-bottom: 3px;">
            <?php echo esc_html($this->settings->getFormattedFullAddress()); ?>
        </div>
        <?php
    }

    public function renderBillToAddress()
    {
        $order = Arr::get($this->config, 'order', null);
        $orderTaxRates = $order->orderTaxRates->first();
        ?>
        <div
                class="fct-receipt-page-order-items-addresses-bill-to"
                style="border-radius: 5px;print-color-adjust: exact;">
            <h5
                    class="fct-receipt-page-order-items-addresses-bill-to-title"
                    style="font-weight: bold;font-size: 14px;margin: 0 0 10px 0;color: #495057;border-bottom: 1px solid #dee2e6;padding-bottom: 5px;">
                <?php echo esc_html__('Bill To', 'fluent-cart'); ?>
            </h5>
            <?php if (!empty($order->billing_address)) : ?>

                <div
                        class="fct-receipt-page-order-items-addresses-bill-to-address"
                        style="margin-bottom: 3px;">
                    <?php echo esc_html($order->billing_address->getAddressAsText()); ?>
                </div>
                <?php if (!empty($this->vat_tax_id)) : ?>
                    <div
                            class="fct-receipt-page-order-items-addresses-bill-to-vat-number"
                            style="margin-bottom: 3px;">
                        <?php echo esc_html__('VAT/Tax ID: ', 'fluent-cart') . esc_html($this->vat_tax_id); ?>
                    </div>
                <?php endif; ?>
                <div
                        class="fct-receipt-page-order-items-addresses-bill-to-email"
                        style="margin-top: 10px;">
                    <?php echo esc_html($order->customer->email); ?>

                </div>
                <?php
                $phoneNumber = Arr::get($order->billing_address->meta ?? [], 'other_data.phone', '');
                if ($phoneNumber !== ''):
                    ?>
                    <div
                            class="fct-receipt-page-order-items-addresses-bill-to-phone"
                            style="margin-top: 3px;">
                        <?php echo esc_html($phoneNumber); ?>
                    </div>
                <?php endif; ?>
                <div class="fct-receipt-page-order-items-addresses-bill-to-company-name">
                    <?php
                    $companyName = Arr::get($order->billing_address->meta ?? [], 'other_data.company_name', '');
                    if ($companyName == '') {
                        $companyName = Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.name', '');
                    }
                    echo esc_html($companyName);
                    ?>
                </div>
                <div
                        class="fct-receipt-page-order-items-addresses-bill-to-vat-number"
                        style="margin-top: 5px;">
                    <?php
                    $vatNumber = Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.vat_number', '');

                    if ($vatNumber !== '') {
                        echo esc_html__('EU VAT', 'fluent-cart') . ': ' . esc_html($vatNumber);
                    }
                    ?>
                </div>

            <?php else: ?>
                <div
                        class="fct-receipt-page-order-items-addresses-bill-to-name"
                        style="margin-top: 10px;">
                    <?php echo esc_html($order->customer->full_name); ?>
                </div>
                <?php if (!empty($vat_tax_id)) : ?>
                    <div
                            class="fct-receipt-page-order-items-addresses-bill-to-vat-number"
                            style="margin-bottom: 3px;">
                        <?php echo esc_html__('VAT/Tax ID: ', 'fluent-cart') . esc_html($vat_tax_id); ?>
                    </div>
                <?php endif; ?>
                <div
                        class="fct-receipt-page-order-items-addresses-bill-to-email"
                        style="margin-top: 3px;">
                    <?php echo esc_html($order->customer->email); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderShipToAddress()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <div
                class="fct-receipt-page-order-items-addresses-ship-to"
                style="border-radius: 5px;print-color-adjust: exact;">
            <h5
                    class="fct-receipt-page-order-items-addresses-ship-to-title"
                    style="font-weight: bold;font-size: 14px;margin: 0 0 10px 0;color: #495057;border-bottom: 1px solid #dee2e6;padding-bottom: 5px;">
                <?php echo esc_html__('Ship To', 'fluent-cart'); ?>
            </h5>
            <?php if (!empty($order->shipping_address)) : ?>
                <div
                        class="fct-receipt-page-order-items-addresses-ship-to-address"
                        style="margin-bottom: 3px;">
                    <?php echo esc_html($order->shipping_address->getAddressAsText()); ?>
                </div>
                <?php
                $phone = Arr::get($order->shipping_address->meta ?? [], 'other_data.phone', '');
                if ($phone !== ''):
                    ?>
                    <div
                            class="fct-receipt-page-order-items-addresses-ship-to-phone"
                            style="margin-top: 3px;">
                        <?php echo esc_html($phone); ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div
                        class="fct-receipt-page-order-items-addresses-ship-to-name"
                        style="margin-top: 10px;">
                    <?php echo esc_html($order->customer->full_name); ?>
                </div>
                <div
                        class="fct-receipt-page-order-items-addresses-ship-to-email"
                        style="margin-top: 3px;">
                    <?php echo esc_html($order->customer->email); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderOrderItems()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->order_items) : ?>
            <?php $this->renderOrderItemsTable(); ?>

            <?php $this->renderOrderItemsFooter(); ?>
        <?php endif;
    }

    public function renderOrderItemsTable()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->order_items) : ?>
            <table
                    class="fct-receipt-page-order-items-table"
                    style="margin-top: 20px;margin-bottom: 10px;width: 100%;text-align: left;border-spacing: 0;border-collapse: collapse;border: none;">
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
        <?php endif;
    }

    public function renderOrderItemsFooter()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <div
                class="fct-receipt-page-order-items-footer"
                style="margin-top: 20px;">
            <table
                    class="fct-receipt-page-order-items-footer-table"
                    style="max-width: 250px;width: 100%;margin-left: auto;border-collapse: collapse;background: #f8f9fa;print-color-adjust: exact;border: none;">
                <tbody>
                <?php $this->renderSubtotal(); ?>
                <?php $this->renderDiscount(); ?>
                <?php $this->renderShipping(); ?>
                <?php $this->renderTaxTotal(); ?>
                <?php $this->renderShippingTax(); ?>
                <?php $this->renderRefund(); ?>
                <?php $this->renderTotal(); ?>
                <?php $this->renderAmountPaid(); ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function renderSubtotal()
    {
        $order = Arr::get($this->config, 'order', null);
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
        <?php endif;
    }

    public function renderDiscount()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->manual_discount_total + $order->coupon_discount_total > 0): ?>
            <tr>
                <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                    <?php echo esc_html__('Discount', 'fluent-cart'); ?>
                </td>
                <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                    - <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->manual_discount_total + $order->coupon_discount_total)); ?>
                </td>
            </tr>
        <?php endif;
    }

    public function renderShipping()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->shipping_total > 0): ?>
            <tr>
                <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                    <?php echo esc_html__('Shipping', 'fluent-cart'); ?>
                </td>
                <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->shipping_total)); ?>
                </td>
            </tr>
        <?php endif;
    }

    public function renderTaxTotal()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->tax_total > 0): ?>
            <tr>
                <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                    <?php echo esc_html__('Tax', 'fluent-cart');
                    echo esc_html($order->tax_behavior == 2 ? __('(Included)', 'fluent-cart') : __('(Excluded)', 'fluent-cart'));
                    ?>
                </td>
                <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->tax_total)); ?>
                </td>
            </tr>
        <?php endif;
    }

    public function renderShippingTax()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->shipping_tax > 0): ?>
            <tr>
                <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                    <?php echo esc_html__('Shipping Tax', 'fluent-cart');
                    echo esc_html($order->tax_behavior == 2 ? __('(Included)', 'fluent-cart') : __('(Excluded)', 'fluent-cart'));
                    ?>
                </td>
                <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->shipping_tax)); ?>
                </td>
            </tr>
        <?php endif;
    }

    public function renderRefund()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->total_refund > 0): ?>
            <tr style="font-weight: bold;font-size: 14px;">
                <td style="font-weight:500;padding: 8px 20px 8px 0;text-align: right;border:none;">
                    <?php echo esc_html__('Refund', 'fluent-cart'); ?>
                </td>
                <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border:none;">
                    - <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->total_refund)); ?>
                </td>
            </tr>
        <?php endif;
    }

    public function renderTotal()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <tr style="font-weight: bold;font-size: 14px;">
            <td style="font-weight:500;padding: 8px 20px 8px 0;text-align: right;border:none;">
                <?php echo esc_html__('Total', 'fluent-cart'); ?>
            </td>
            <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border:none;">
                <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($order->total_amount - $order->total_refund)); ?>
            </td>
        </tr>
        <?php
    }

    public function renderAmountPaid()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <tr style="font-weight: bold;font-size: 14px;">
            <td style="font-weight:500;padding: 8px 20px 8px 0;text-align: right;border:none;">
                <?php echo esc_html__('Amount Paid', 'fluent-cart'); ?>
            </td>
            <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border:none;">
                <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($order->total_paid - $order->total_refund)); ?>
            </td>
        </tr>
        <?php
    }

    public function renderTaxNote()
    {
        $order = Arr::get($this->config, 'order', null);
        $taxtotal = $order->tax_total + $order->shipping_tax;
        if (Arr::get($orderTaxRates->meta ?? [], 'vat_reverse.valid') && !$taxtotal): ?>

            <div style="text-align: right; font-size: 14px; margin-top: 10px;">
                <?php echo esc_html__('* Tax to be paid on reverse charge basis', 'fluent-cart'); ?>
            </div>

        <?php endif;
    }

    public function renderPaymentHistory()
    {
        $order = Arr::get($this->config, 'order', null);
        $transactions = $order->transactions;
        if (!empty($transactions)) :
            ?>
            <div
                    class="fct-payment-history"
                    style="margin-top: 30px;">
                <div
                        class="fct-payment-history-heading"
                        style="font-weight: bold;font-size: 14px;color: #495057;margin: 0;padding: 0;">
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
        <?php endif;
    }

    public function renderConfirmationError($errorData)
    {
        $failed_reason = Arr::get($errorData, 'failed_reason', '');
        $custom_payment_url = Arr::get($errorData, 'custom_payment_url', '');
        ?>
        <style>
            .fluent_cart_confirmation_failed {
                max-width: 700px;
                margin: 0 auto;
                padding: 30px;
                border-radius: 9px;
                box-shadow: 1px 1px 5px 0px #ccc;
                border-left: 3px solid red;
            }
        </style>
        <div class="fluent_cart_confirmation_failed">
            <h3><?php echo esc_html__('Payment Confirmation Failed!', 'fluent-cart'); ?></h3>
            <span><?php echo esc_html__('We are sorry, your order is placed but payment confirmation has failed.', 'fluent-cart'); ?></span>
            <?php if ($failed_reason) : ?>
                <p style="color:red;font-style: italic;"><?php echo esc_html__('Failed reason- ', 'fluent-cart') . esc_html($failed_reason) ?></p>
            <?php endif; ?>
            <span><?php echo esc_html__('Please try to complete payment again from here!', 'fluent-cart'); ?></span>
            <a href="<?php echo esc_url($custom_payment_url ?? ''); ?>"><?php echo esc_url($custom_payment_url); ?></a>
        </div>
    <?php }
}
