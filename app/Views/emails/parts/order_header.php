<?php if ( ! defined( 'ABSPATH' ) ) exit; 

use FluentCart\App\Modules\Tax\TaxModule;?>

<?php if(!empty($order)): ?>
<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
       style="margin-left:auto;margin-right:auto;max-width:620px;margin-bottom:20px;border: none;">
    <tbody>
    <tr style="width:100%">
        <td>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="border: none;">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="width:60%">
                        <h1 style="font-size:24px;font-weight:700;color:rgb(17,24,39);margin:0px">
                            {{ settings.store_brand }}
                        </h1>
                    </td>
                    <td style="vertical-align: middle; width: 15%; text-align: right; border: 0;padding-right: 10px;">
                        <p style="white-space: nowrap; color: #94a3b8; text-align: right; margin: 0;font-size:12px;"><?php echo esc_html__('Order Date', 'fluent-cart'); ?></p>
                        <p style="white-space: nowrap; font-weight: bold; color: #000; text-align: right; margin: 0;font-size:15px;">{{order.created_at}}</p>
                    </td>
                    <td style="width:15%;text-align:right">
                        <p style="white-space: nowrap; color: #94a3b8; text-align: right; margin: 0;font-size:12px;">
                            <?php echo esc_html__('Order #', 'fluent-cart'); ?>
                        </p>
                        <p style="white-space: nowrap; font-weight: bold; color: #000; text-align: right; margin: 0;font-size:14px;">
                            <?php echo esc_html($order->invoice_no); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>
            <?php 
            $orderTaxRates = $order->orderTaxRates->first();
            if ($orderTaxRates): ?>
                <div class="fct_invoice_tax_content" style="text-align: right;">
                    <?php
                    $storeVatNumber = isset($orderTaxRates->meta['store_vat_number']) ? $orderTaxRates->meta['store_vat_number'] : '';
                    $taxcountry = isset($orderTaxRates->meta['tax_country']) ? $orderTaxRates->meta['tax_country'] : '';
                    if ($storeVatNumber !== '') {
                        echo '<p style="font-weight: 600; font-size: 14px;margin-bottom:12px;">' . esc_html(TaxModule::getCountryTaxTitle($taxcountry)) . ': ' . esc_html($storeVatNumber) . '</p>';
                    }
                    ?>
                </div>
            <?php endif; ?>
        </td>
    </tr>
    </tbody>
</table>
<?php endif; ?>
