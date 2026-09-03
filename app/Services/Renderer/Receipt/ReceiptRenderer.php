<?php

namespace FluentCart\App\Services\Renderer\Receipt;

use FluentCart\App\Helpers\Helper;
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

    protected $orderTz;

    private $fctTaxSummaryCache = [];

    public function __construct($config = [])
    {
        $this->config = $config;

        $this->is_first_time = Arr::get($this->config, 'is_first_time', false);
        $this->order_operation = Arr::get($this->config, 'order_operation', false);

        $this->settings = new StoreSettings();

        $this->orderTz = Arr::get($this->config, 'user_tz', 'UTC');
    }

    private function getMemoizedTaxSummary($order)
    {
        if (!$order) {
            return TaxSummaryHelper::computeTaxSummary($order);
        }
        $id = (int) $order->id;
        if (!isset($this->fctTaxSummaryCache[$id])) {
            $this->fctTaxSummaryCache[$id] = TaxSummaryHelper::computeTaxSummary($order);
        }
        return $this->fctTaxSummaryCache[$id];
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

        $this->vat_tax_id = $order->getCustomerTaxNumber();

        $profilePage = $this->settings->getCustomerProfilePage();
        $orderTaxRates = $order->getPrimaryOrderTaxRate();

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
        $orderTaxRates = $order->getPrimaryOrderTaxRate();
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
                $date = wp_date(
                    get_option('date_format'),
                    DateTime::anyTimeToGmt($order->created_at)->getTimestamp(),
                    new \DateTimeZone($this->orderTz)
                );
                echo esc_html(Helper::translateNumber($date));
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
        $orderTaxRates = $order->getPrimaryOrderTaxRate();
        $storeVatNumber = Arr::get($orderTaxRates->meta ?? [], 'store_vat_number', '');
        $taxCountry = Arr::get($orderTaxRates->meta ?? [], 'tax_country', '');
        if (!empty($storeVatNumber)): ?>
            <div
                    class="fct-receipt-page-store-address-vat"
                    style="margin-top: 5px;font-weight: 500;">
                <?php echo esc_html(TaxModule::getCountryTaxTitle($taxCountry)) . ': ' . esc_html($storeVatNumber); ?>
            </div>
        <?php endif;
    }

    public function renderBillToAddress()
    {
        $order = Arr::get($this->config, 'order', null);
        $orderTaxRates = $order->getPrimaryOrderTaxRate();
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
                <?php
                $companyName = Arr::get($order->billing_address->meta ?? [], 'other_data.company_name', '');
                if ($companyName === '') {
                    $companyName = $order->getCustomerTaxName();
                }
                if ($companyName !== ''):
                ?>
                <div class="fct-receipt-page-order-items-addresses-bill-to-company-name">
                    <?php echo esc_html($companyName); ?>
                </div>
                <?php endif; ?>
                <?php
                $legalRegId = Arr::get($order->billing_address->meta ?? [], 'other_data.legal_registration_id', '');
                if ($legalRegId === '') {
                    $legalRegId = Arr::get($order->getBusinessInfo(), 'legal_registration_id', '');
                }
                if ($legalRegId !== ''):
                ?>
                <div class="fct-receipt-page-order-items-addresses-bill-to-legal-reg" style="margin-top: 3px;">
                    <?php echo esc_html__('Reg. ID', 'fluent-cart') . ': ' . esc_html($legalRegId); ?>
                </div>
                <?php endif; ?>
                <div
                        class="fct-receipt-page-order-items-addresses-bill-to-vat-number"
                        style="margin-top: 5px;">
                    <?php
                    $vatNumber = $order->getCustomerTaxNumber();
                    if ($vatNumber !== '') {
                        $vatLabel = __('VAT/Tax ID', 'fluent-cart');
                        echo esc_html($vatLabel) . ': ' . esc_html($vatNumber);
                    }
                    ?>
                </div>
                <?php
                $reverseChargeDeclaration = Arr::get($order->getBusinessInfo(), 'reverse_charge_declaration', '');
                if ($reverseChargeDeclaration !== ''):
                ?>
                <div class="fct-receipt-page-order-items-addresses-bill-to-reverse-charge" style="margin-top: 5px;">
                    <strong><?php echo esc_html__('Reverse Charge Declaration', 'fluent-cart'); ?>:</strong>
                    <?php echo esc_html($reverseChargeDeclaration); ?>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div
                        class="fct-receipt-page-order-items-addresses-bill-to-name"
                        style="margin-top: 10px;">
                    <?php echo esc_html($order->customer->full_name); ?>
                </div>
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
                $isReversed = $order->isReverseChargeTaxOrder();
                $rcMode = $order->getOrderRcMode();
                $fctReceiptTaxDisplayMode = TaxSummaryHelper::getTaxDisplayMode();

                foreach ($orderItems as $item) :
                    if (($item['payment_type'] ?? '') === 'fee') {
                        continue;
                    }
                    $itemRates  = TaxSummaryHelper::getItemTaxRates($item);
                    $itemBorder = empty($itemRates) ? '1px solid #dee2e6' : 'none';
                    ?>
                    <tr>
                        <td style="font-size:15px;padding: 12px 8px;border: none;border-bottom: <?php echo esc_attr($itemBorder); ?>;print-color-adjust: exact;">
                            <?php echo esc_html($item['post_title']); ?>
                            <br>
                            <?php if (!empty($item['title'])) : ?>
                                <small style="font-size: 13px;color: #758195;">- <?php echo esc_html(!empty($item['variation_display_title']) ? $item['variation_display_title'] : $item['title']); ?></small>
                                <br>
                            <?php endif; ?>
                            <?php if (!empty($item['payment_info'])) : ?>
                                <small style="font-size: 13px;color: #758195;"><?php echo esc_html($item['payment_info']); ?></small>
                            <?php endif; ?>
                            <?php if ($fctReceiptTaxDisplayMode !== 'simplified' && empty($itemRates) && !empty($item['tax_amount'])) : ?>
                                <small style="font-size: 12px; color: #94a3b8; display:block; margin-top:2px;">
                                    <?php
                                    /* translators: %1$s: formatted tax amount */
                                    echo esc_html(sprintf(__('Tax: %1$s', 'fluent-cart'), Helper::toDecimal((int) $item['tax_amount']))); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px 8px;border: none;border-bottom: <?php echo esc_attr($itemBorder); ?>;text-align: right; vertical-align: top;">
                            <?php echo esc_html(Helper::translateNumber($item['quantity'])); ?>
                        </td>
                        <?php $couponDiscount = (int) Arr::get($item['line_meta'] ?? [], 'coupon_discount', 0); ?>
                        <td style="padding: 12px 8px;border: none;border-bottom: <?php echo esc_attr($itemBorder); ?>;text-align: right; vertical-align: top;">
                            <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($item['unit_price'], null, false, true, true)); ?>
                        </td>
                        <td style="padding: 12px 8px;border: none;border-bottom: <?php echo esc_attr($itemBorder); ?>;text-align: right; vertical-align: top;">
                            <?php if ($couponDiscount > 0): ?>
                                <span style="text-decoration:line-through;opacity:0.5;font-size:11px;display:block;">
                                    <?php echo esc_html(Helper::toDecimal($item['subtotal'])); ?>
                                </span>
                            <?php endif; ?>
                            <?php echo esc_html($item['formatted_total']); ?>
                        </td>
                    </tr>
                    <?php if ($fctReceiptTaxDisplayMode !== 'simplified' && !empty($itemRates)) : ?>
                    <tr>
                        <td colspan="4" style="padding:0 8px 8px 8px;border:none;border-bottom:1px solid #dee2e6;">
                            <?php echo $this->renderTaxRatePills($itemRates, $isReversed, $rcMode); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
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
                    style="max-width: 250px;width: 100%;margin-left: auto;border-collapse: collapse;background: #f8f9fa;print-color-adjust: exact;border: none; border-radius: 8px;">
                <tbody>
                <?php $this->renderSubtotal(); ?>
                <?php $this->renderDiscount(); ?>
                <?php $this->renderShipping(); ?>
                <?php $this->renderFees(); ?>
                <?php $this->renderTaxSummaryBox(); ?>
                <?php $this->renderProrateCredit(); ?>
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
                <td style="text-align: right;border: none; padding-top: 20px;">
                    <?php echo esc_html__('Subtotal', 'fluent-cart'); ?>
                </td>
                <td style="font-weight:700; padding-right: 8px; padding-top: 20px;width: 100px;text-align: right;border: none;">
                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->subtotal));
                    ?>
                </td>
            </tr>
        <?php endif;
    }

    public function renderDiscount()
    {
        $order = Arr::get($this->config, 'order', null);
        $prorateCredit = (int) \FluentCart\Framework\Support\Arr::get($order->config, 'prorate_credit', 0);
        $upgradeDiscount = $order->manual_discount_total - $prorateCredit;
        // When the prorate credit IS the whole discount, label the row directly
        // instead of showing "Discount" with a single redundant breakdown row.
        $onlyProrateCredit = $prorateCredit > 0 && $upgradeDiscount <= 0 && $order->coupon_discount_total <= 0;

        if ($order->manual_discount_total + $order->coupon_discount_total > 0): ?>
        <tr>
            <td style="text-align: right;border: none;">
                <?php echo $onlyProrateCredit ? esc_html__('Prorate Credit', 'fluent-cart') : esc_html__('Discount', 'fluent-cart'); ?>
            </td>
            <td style="padding-right:8px; width: 100px;text-align: right;border: none;">
                - <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($order->manual_discount_total + $order->coupon_discount_total)); ?>
            </td>
        </tr>
        <?php if ($prorateCredit > 0 && $upgradeDiscount > 0): ?>
        <tr>
            <td style="padding-left:20px;text-align: right;border: none;font-size:12px;color:rgb(107,114,128);">
                <?php echo esc_html__('Upgrade Discount', 'fluent-cart'); ?>
            </td>
            <td style="padding-right:8px;width: 100px;text-align: right;border: none;font-size:12px;color:rgb(107,114,128);">
                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($upgradeDiscount)); ?>
            </td>
        </tr>
        <?php endif; ?>
        <?php if ($prorateCredit > 0 && !$onlyProrateCredit): ?>
        <tr>
            <td style="padding-left:20px;text-align: right;border: none;font-size:12px;color:rgb(107,114,128);">
                <?php echo esc_html__('Prorate Credit', 'fluent-cart'); ?>
            </td>
            <td style="padding-right:8px;width: 100px;text-align: right;border: none;font-size:12px;color:rgb(107,114,128);">
                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($prorateCredit)); ?>
            </td>
        </tr>
        <?php endif; ?>
        <?php endif;
    }

    public function renderProrateCredit()
    {
        // Rendered as a sub-row inside renderDiscount.
    }

    public function renderShipping()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->shipping_total <= 0) {
            return;
        }
        $displayShipping = (int) $order->shipping_total;
        if ($order->isReverseChargeTaxOrder()) {
            $rcAdj = (int) Arr::get($this->getMemoizedTaxSummary($order), 'rcShippingAdjustment', 0);
            if ($rcAdj > 0) {
                $displayShipping = max(0, $displayShipping - $rcAdj);
            }
        }
        $shippingMethodTitle = (string) Arr::get($order->config, 'shipping_method_title', '');
        ?>
        <tr>
            <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                <?php echo esc_html__('Shipping', 'fluent-cart'); ?>
                <?php if ($shippingMethodTitle): ?>
                    <span style="display:block;font-size:12px;color:rgb(107,114,128);line-height:18px;">
                        <?php echo esc_html($shippingMethodTitle); ?>
                    </span>
                <?php endif; ?>
            </td>
            <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($displayShipping)); ?>
            </td>
        </tr>
        <?php
    }

    public function renderFees()
    {
        $order = Arr::get($this->config, 'order', null);
        if (!$order || $order->fee_total <= 0) {
            return;
        }
        $feeItems = $order->feeItems()->get();
        foreach ($feeItems as $feeItem): ?>
            <tr>
                <td style="padding: 8px 20px 8px 0;text-align: right;border: none;">
                    <?php echo esc_html($feeItem->title); ?>
                </td>
                <td style="padding: 8px 8px 8px 0;width: 100px;text-align: right;border: none;">
                    <?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($feeItem->subtotal)); ?>
                </td>
            </tr>
        <?php endforeach;
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
        $displayTotal = (int) $order->total_amount - (int) $order->total_refund;
        if ($order->isReverseChargeTaxOrder()) {
            $rcAdj = (int) Arr::get($this->getMemoizedTaxSummary($order), 'rcTotalAdjustment', 0);
            if ($rcAdj > 0) {
                $displayTotal = max(0, $displayTotal - $rcAdj);
            }
        }
        ?>
        <tr style="font-weight: bold;font-size: 14px;">
            <td style="font-weight:500;text-align: right;border:none;">
                <?php echo esc_html__('Total', 'fluent-cart'); ?>
            </td>
            <td style="width: 100px;text-align: right;border:none;  padding-right: 8px;">
                <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($displayTotal, null, false, true, true)); ?>
            </td>
        </tr>
        <?php
    }

    public function renderAmountPaid()
    {
        $order = Arr::get($this->config, 'order', null);
        ?>
        <tr style="font-weight: bold;font-size: 14px;">
            <td style="font-weight:500;text-align: right;border:none; padding-bottom: 20px">
                <?php echo esc_html__('Amount Paid', 'fluent-cart'); ?>
            </td>
            <td style="width: 100px;text-align: right;border:none;padding-bottom: 20px; padding-right: 8px;">
                <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice(($order->total_paid - $order->total_refund), null, false, true, true)); ?>
            </td>
        </tr>
        <?php
    }

    public function renderTaxNote()
    {
        $order = Arr::get($this->config, 'order', null);
        if ($order->isReverseChargeTaxOrder()): ?>

            <div style="text-align: right; font-size: 14px; margin-top: 10px;">
                <?php echo '*' . esc_html(TaxModule::getReverseChargeNoticeText()); ?>
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
                                    $date = wp_date(
                                        get_option('date_format'),
                                        DateTime::anyTimeToGmt($transaction->created_at)->getTimestamp(),
                                        new \DateTimeZone($this->orderTz)
                                    );
                                    echo esc_html(Helper::translateNumber($date));
                                ?>
                            </td>
                            <td style="padding: 10px;border:none;border-bottom: 1px solid #dee2e6;text-align: right;">
                                <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($transaction->total, null, false, true, true)); ?>
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
                <p style="color:red;font-style: italic;"><?php echo esc_html__('Failure reason: ', 'fluent-cart') . esc_html($failed_reason) ?></p>
            <?php endif; ?>
            <span><?php echo esc_html__('Please try to complete payment again from here!', 'fluent-cart'); ?></span>
            <a href="<?php echo esc_url($custom_payment_url ?? ''); ?>"><?php echo esc_url($custom_payment_url); ?></a>
        </div>
    <?php }

    private function renderTaxRatePills(array $rates, bool $isReversed = false, string $rcMode = 'fixed')
    {
        $html = '';
        foreach ($rates as $rate) {
            $isInclusive  = !empty($rate['inclusive']);
            $badgeBg      = $isInclusive ? '#EAF3DE' : '#FAEEDA';
            $textColor    = $isInclusive ? '#3B6D11' : '#854F0B';
            $shouldStrike = $isReversed && (!$isInclusive || $rcMode === 'dynamic');
            $amountStyle  = 'color:' . esc_attr($textColor) . ';font-size:11px;font-weight:500;'
                          . ($shouldStrike ? 'text-decoration:line-through;opacity:0.6;' : '');
            /* translators: %1$s: formatted tax amount (e.g. $12.00) */
            $amountDisplay = $isInclusive
                ? esc_html(sprintf(__('incl. %1$s', 'fluent-cart'), Helper::toDecimal($rate['tax_amount'])))
                : esc_html(Helper::toDecimal($rate['tax_amount']));
            $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:none;margin-top:3px;">';
            $html .= '<tr>';
            $html .= '<td style="padding:0;border:none;">';
            $html .= '<span style="background-color:' . esc_attr($badgeBg) . ';color:' . esc_attr($textColor) . ';font-size:11px;padding:2px 7px;border-radius:4px;display:inline-block;">' . esc_html($rate['label']) . ' (' . esc_html(number_format((float) $rate['rate_percent'], 0)) . '%)</span>';
            $html .= '</td>';
            $html .= '<td style="text-align:right;padding:0;border:none;white-space:nowrap;">';
            $html .= '<span style="' . $amountStyle . '">' . $amountDisplay . '</span>';
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';
        }
        return $html;
    }

    public function renderTaxSummaryBox()
    {
        $order   = Arr::get($this->config, 'order', null);
        $summary = $this->getMemoizedTaxSummary($order);
        if (!$summary['shouldRender']) {
            return;
        }
        ?>
        <tr>
            <td colspan="2" style="padding: 6px 8px;">
                <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:6px; border-collapse:separate; background:#ffffff; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                <tr>
                <td style="padding:6px 8px 6px 0;">
                <table width="100%" cellpadding="0" cellspacing="0" style="border:none;border-collapse:collapse;">
                    <?php if (($summary['displayMode'] ?? '') === 'simplified' && !empty($summary['simpleLine'])): ?>
                    <tr>
                        <td style="padding:4px 0 3px 8px; font-size:11px; font-weight:600; color:#1e293b; text-align:left;">
                            <?php echo esc_html($summary['simpleLine']['label']); ?>
                        </td>
                        <td style="padding:4px 0 3px; font-size:11px; font-weight:600; color:#1e293b; text-align:right;">
                            <?php echo esc_html($summary['simpleLine']['value']); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if (($summary['displayMode'] ?? '') !== 'simplified'): ?>
                    <tr>
                        <td colspan="2" style="padding:0 0 3px 0; font-size:10px; font-weight:600;
                                               text-transform:uppercase; letter-spacing:0.06em; color:#64748b;">
                            <?php if (!empty(Arr::get($summary, 'foldedRateLines', []))): ?>
                                <?php echo esc_html__('Tax breakdown by rate', 'fluent-cart'); ?>
                            <?php else: ?>
                                <?php echo esc_html__('TAX SUMMARY', 'fluent-cart'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                        $foldedRateLines    = Arr::get($summary, 'foldedRateLines', []);
                        $rcIsReverseCharge  = !empty($summary['isReverseCharge']);
                        $rcReversedTotal    = (int) Arr::get($summary, 'reversedTaxTotal', 0);
                        $rcReversedShipping = (int) Arr::get($summary, 'reversedShippingTax', 0);
                        $rcReversedValue    = $rcReversedTotal > 0
                            ? Helper::toDecimal($rcReversedTotal)
                            : __('Charge reversed', 'fluent-cart');
                        $includedInPrices   = (int) Arr::get($summary, 'includedInPrices', 0);
                    ?>
                    <?php if (!empty($foldedRateLines)): ?>
                            <tr>
                                <td colspan="2" style="padding:2px 0 0 0;">
                                    <table width="100%" cellpadding="0" cellspacing="0" style="border:none;border-collapse:collapse;table-layout:fixed;">
                                        <tr>
                                            <td style="width:58%;padding:3px 8px 3px 8px; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:#64748b; vertical-align:top;">
                                                <?php echo esc_html__('Rate', 'fluent-cart'); ?>
                                            </td>
                                            <td style="width:24%;padding:3px 8px 3px 0; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:#64748b; text-align:right; white-space:nowrap; vertical-align:top;">
                                                <?php echo esc_html__('Taxable base', 'fluent-cart'); ?>
                                            </td>
                                            <td style="width:18%;padding:3px 0 3px; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:#64748b; text-align:right; white-space:nowrap; vertical-align:top;">
                                                <?php echo esc_html__('Tax', 'fluent-cart'); ?>
                                            </td>
                                        </tr>
                                        <?php foreach ($foldedRateLines as $foldedRow):
                                            $foldedColor = !empty($foldedRow['inclusive']) ? '#94a3b8' : '#334155'; ?>
                                            <tr>
                                                <td style="width:58%;padding:3px 8px 3px 8px; font-size:11px; color:<?php echo esc_attr($foldedColor); ?>; text-align:left; white-space:normal; word-break:break-word; overflow-wrap:break-word; vertical-align:top;">
                                                    <?php echo esc_html($foldedRow['label']); ?>
                                                </td>
                                                <td style="width:24%;padding:3px 8px 3px 0; font-size:11px; color:<?php echo esc_attr($foldedColor); ?>; text-align:right; white-space:nowrap; vertical-align:top;">
                                                    <?php echo esc_html(Helper::toDecimal((int) $foldedRow['base'])); ?>
                                                </td>
                                                <td style="width:18%;padding:3px 0 3px; font-size:11px; color:<?php echo esc_attr($foldedColor); ?>; text-align:right; white-space:nowrap; vertical-align:top;">
                                                    <?php echo esc_html(Helper::toDecimal((int) $foldedRow['tax'])); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </td>
                            </tr>
                            <?php if ($rcIsReverseCharge): ?>
                            <tr>
                                <td style="padding:3px 0 4px 8px; font-size:11px; font-weight:600; color:#1e293b; text-align: left;">
                                    <?php echo esc_html__('VAT reversed', 'fluent-cart'); ?>
                                </td>
                                <td style="padding:3px 0 4px; font-size:11px; text-align:right; font-weight:600; color:#1e293b;">
                                    <?php echo esc_html($rcReversedValue); ?>
                                </td>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <td style="padding:4px 0 3px 8px; font-size:11px; font-weight:600; color:#1e293b; border-top:1px solid #e2e8f0; text-align:left;">
                                    <?php echo esc_html__('Total tax', 'fluent-cart'); ?>
                                </td>
                                <td style="padding:4px 0 3px; font-size:11px; font-weight:600; color:#1e293b; border-top:1px solid #e2e8f0; text-align:right;">
                                    <?php echo esc_html(Helper::toDecimal((int) $summary['totalOrderTax'])); ?>
                                </td>
                            </tr>
                            <?php if ($includedInPrices > 0): ?>
                            <tr>
                                <td style="padding:3px 0 3px 8px; font-size:11px; color:#94a3b8; text-align:left;">
                                    <?php echo esc_html__('of which included in prices', 'fluent-cart'); ?>
                                </td>
                                <td style="padding:3px 0 3px; font-size:11px; color:#94a3b8; text-align:right;">
                                    <?php echo esc_html(Helper::toDecimal($includedInPrices)); ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($summary['payableTax'] > 0 && $includedInPrices > 0): ?>
                            <tr>
                                <td style="padding:3px 0 4px 8px; font-size:11px; color:#334155; text-align:left; border-top:1px solid #e2e8f0; padding-top:6px;">
                                    <?php echo esc_html__('Payable now (added)', 'fluent-cart'); ?>
                                </td>
                                <td style="padding:3px 0 4px; font-size:11px; color:#334155; text-align:right; border-top:1px solid #e2e8f0; padding-top:6px;">
                                    <?php echo esc_html(Helper::toDecimal((int) $summary['payableTax'])); ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php elseif ($rcIsReverseCharge): ?>
                        <?php if ($summary['showRcShippingRow'] && $rcReversedShipping > 0): ?>
                        <tr>
                            <td style="padding:3px 0 3px 8px; font-size:11px; color:#94a3b8;">
                                <?php echo esc_html__('Added on shipping', 'fluent-cart'); ?>
                            </td>
                            <td style="padding:3px 0 3px; font-size:11px; text-align:right; color:#94a3b8;">
                                <span style="text-decoration:line-through;opacity:0.6;"><?php echo esc_html(Helper::toDecimal($rcReversedShipping)); ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="padding:3px 0 4px 8px; font-size:11px; font-weight:600; color:#1e293b; text-align: left;">
                                <?php echo esc_html__('Tax reversed', 'fluent-cart'); ?>
                            </td>
                            <td style="padding:3px 0 4px; font-size:11px; text-align:right; font-weight:600; color:#1e293b;">
                                <?php echo esc_html($rcReversedValue); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php
                            $rcFeeRows        = Arr::get($summary, 'feeTaxLineRows', []);
                            $taxRateLines     = Arr::get($summary, 'taxRateLines', []);
                            $productTaxRowCount = !empty($taxRateLines)
                                ? count($taxRateLines)
                                : (int) ($summary['inclusiveTax'] > 0) + (int) ($summary['exclusiveTax'] > 0);
                            $rowCount   = $productTaxRowCount + count($rcFeeRows) + (int) ($summary['shippingTax'] > 0);
                            $shippingTaxLines = Arr::get($summary, 'shippingTaxLines', []);
                            $shouldShowBreakdown = !empty($taxRateLines)
                                || !empty($shippingTaxLines)
                                || $rowCount >= 2
                                || ($rowCount === 1 && !($summary['payableTax'] > 0 || $summary['inclusiveTax'] > 0 || (int) Arr::get($summary, 'inclusiveFeeTax', 0) > 0));
                        ?>
                            <?php if (!empty($taxRateLines) && $shouldShowBreakdown): ?>
                                <?php foreach ($taxRateLines as $taxLine):
                                    $taxLineColor = !empty($taxLine['inclusive']) ? '#94a3b8' : '#334155'; ?>
                                    <tr>
                                        <td style="padding:3px 0 3px 8px; font-size:11px; color:<?php echo esc_attr($taxLineColor); ?>; text-align: left;">
                                            <?php echo esc_html($taxLine['label']); ?>
                                        </td>
                                        <td style="padding:3px 0 3px; font-size:11px; text-align:right; color:<?php echo esc_attr($taxLineColor); ?>;">
                                            <?php echo esc_html(Helper::toDecimal($taxLine['order_tax'])); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if (empty($taxRateLines) && $summary['inclusiveTax'] > 0 && $shouldShowBreakdown): ?>
                                <tr>
                                    <td style="padding:3px 0 3px 8px; font-size:11px; color:#94a3b8;">
                                        <?php echo esc_html__('Included in item prices', 'fluent-cart'); ?>
                                    </td>
                                    <td style="padding:3px 0 3px; font-size:11px; text-align:right; color:#94a3b8;">
                                        <?php echo esc_html(Helper::toDecimal($summary['inclusiveTax'])); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if (empty($taxRateLines) && $summary['exclusiveTax'] > 0 && $shouldShowBreakdown): ?>
                                <tr>
                                    <td style="padding:3px 0 3px 8px; font-size:11px; color:#334155; text-align: left;">
                                        <?php echo esc_html__('Added on products', 'fluent-cart'); ?>
                                    </td>
                                    <td style="padding:3px 0 3px; font-size:11px; text-align:right; color:#334155;">
                                        <?php echo esc_html(Helper::toDecimal($summary['exclusiveTax'])); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($shouldShowBreakdown) : ?>
                            <?php foreach ($rcFeeRows as $feeRow) :
                                $feeRowColor = $feeRow['inclusive'] ? '#94a3b8' : '#334155'; ?>
                                <tr>
                                    <td style="padding:3px 0 3px 8px; font-size:11px; color:<?php echo esc_attr($feeRowColor); ?>; text-align: left;">
                                        <?php echo esc_html($feeRow['display_label']); ?>
                                    </td>
                                    <td style="padding:3px 0 3px; font-size:11px; text-align:right; color:<?php echo esc_attr($feeRowColor); ?>;">
                                        <?php echo esc_html(Helper::toDecimal($feeRow['tax_amount'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($summary['shippingTax'] > 0 && $shouldShowBreakdown):
                                $isShippingInclusive = (bool) Arr::get($summary, 'isShippingInclusive', false);
                                $shippingRowColor    = $isShippingInclusive ? '#94a3b8' : '#334155';
                                if (!empty($shippingTaxLines)):
                                    foreach ($shippingTaxLines as $shLine): ?>
                                        <tr>
                                            <td style="padding:3px 0 3px 8px; font-size:11px; color:<?php echo esc_attr($shippingRowColor); ?>;">
                                                <?php echo esc_html($shLine['label']); ?>
                                            </td>
                                            <td style="padding:3px 0 3px; font-size:11px; text-align:right; color:<?php echo esc_attr($shippingRowColor); ?>;">
                                                <?php echo esc_html(Helper::toDecimal($shLine['shipping_tax'])); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                else: ?>
                                    <tr>
                                        <td style="padding:3px 0 3px 8px; font-size:11px; color:<?php echo esc_attr($shippingRowColor); ?>;">
                                            <?php echo esc_html($isShippingInclusive ? __('Included in shipping prices', 'fluent-cart') : __('Added on shipping', 'fluent-cart')); ?>
                                        </td>
                                        <td style="padding:3px 0 3px; font-size:11px; text-align:right; color:<?php echo esc_attr($shippingRowColor); ?>;">
                                            <?php echo esc_html(Helper::toDecimal($summary['shippingTax'])); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($summary['payableTax'] > 0): ?>
                            <tr>
                                <td style="padding:4px 0 4px 8px; font-size:11px; font-weight:600; color:#1e293b;
                                           border-top:1px solid #e2e8f0; text-align: left;">
                                    <?php echo esc_html__('Total payable tax', 'fluent-cart'); ?>
                                </td>
                                <td style="padding:4px 0 4px; font-size:11px; text-align:right; font-weight:600; color:#1e293b;
                                           border-top:1px solid #e2e8f0;">
                                    <?php echo esc_html(Helper::toDecimal($summary['payableTax'])); ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($summary['inclusiveTax'] > 0 || $summary['inclusiveFeeTax'] > 0): ?>
                                <tr>
                                    <td style="padding:3px 0 4px 8px; font-size:11px; color:#94a3b8;">
                                        <?php echo esc_html__('Total tax in this order', 'fluent-cart'); ?>
                                    </td>
                                    <td style="padding:3px 0 4px; font-size:11px; text-align:right; color:#94a3b8;">
                                        <?php echo esc_html(Helper::toDecimal($summary['totalOrderTax'])); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </table>
                </td>
                </tr>
                </table>
            </td>
        </tr>
        <?php
    }
}
