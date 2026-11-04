<?php if ( ! defined( 'ABSPATH' ) ) exit; 

use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Renderer\Receipt\StoreBusinessInfoHelper;
use FluentCart\Framework\Support\Arr;
$endSide = \FluentCart\App\Services\Email\EmailDirection::end();
$startSide = \FluentCart\App\Services\Email\EmailDirection::start();
$metaItems = [];
if (!empty($order)) {
    $metaItems[] = ['label' => __('Order Date', 'fluent-cart'), 'value' => '{{order.created_at}}'];
    $metaItems[] = ['label' => __('Order #', 'fluent-cart'), 'value' => (string) Arr::get($order, 'uuid')];
    // The receipt number is the invoice number, assigned on payment.
    $receiptNo = (string) Arr::get($order, 'invoice_no');
    if ($receiptNo !== '') {
        $metaItems[] = ['label' => __('Receipt #', 'fluent-cart'), 'value' => $receiptNo];
    }
}
?>

<?php if(!empty($order)): ?>
<table dir="<?php echo esc_attr(\FluentCart\App\Services\Email\EmailDirection::dir()); ?>" align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
       style="margin-left:auto;margin-right:auto;max-width:620px;margin-bottom:20px;border: none;">
    <tbody>
    <tr style="width:100%">
        <td>
            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="border: none;">
                <tbody style="width:100%">
                <tr style="width:100%">
                    <td style="vertical-align:middle;padding-<?php echo esc_attr($endSide); ?>:12px;">
                        <h1 style="font-size:22px;line-height:28px;font-weight:700;letter-spacing:-0.01em;color:rgb(17,24,39);margin:0px">
                            {{ settings.store_brand }}
                        </h1>
                    </td>
                    <?php // Label and value sit side by side and wrap on narrow screens; a legacy 32-character uuid breaks mid-string. ?>
                    <td style="vertical-align:middle;text-align:<?php echo esc_attr($endSide); ?>;border:0;">
                        <?php foreach ($metaItems as $metaItem): ?>
                        <div style="text-align:<?php echo esc_attr($endSide); ?>;">
                            <p style="display:inline-block;color:rgb(75,85,99);margin:0;padding-<?php echo esc_attr($endSide); ?>:8px;font-size:12px;line-height:20px;"><?php echo esc_html($metaItem['label']); ?></p><p style="display:inline-block;max-width:100%;word-break:break-all;font-weight:600;color:rgb(17,24,39);margin:0;font-size:13px;line-height:20px;"><?php echo esc_html($metaItem['value']); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
                </tbody>
            </table>
            <?php 
            $orderTaxRates = $order->orderTaxRates->first();
            $idLines = StoreBusinessInfoHelper::getIdLines($order);
            if ($orderTaxRates || $idLines): ?>
                <div class="fct_invoice_tax_content" style="text-align: <?php echo esc_attr($endSide); ?>;">
                    <?php
                    $storeVatNumber = isset($orderTaxRates->meta['store_vat_number']) ? $orderTaxRates->meta['store_vat_number'] : '';
                    $taxcountry = isset($orderTaxRates->meta['tax_country']) ? $orderTaxRates->meta['tax_country'] : '';
                    if ($storeVatNumber !== '') {
                        echo '<p style="font-weight: 600; font-size: 14px;margin-bottom:12px;">' . esc_html(TaxModule::getCountryTaxTitle($taxcountry)) . ': ' . esc_html($storeVatNumber) . '</p>';
                    }
                    foreach ($idLines as $idLine) {
                        echo '<p style="font-size: 14px;margin: 0 0 4px;">' . esc_html($idLine) . '</p>';
                    }
                    ?>
                </div>
            <?php endif; ?>
        </td>
    </tr>
    </tbody>
</table>
<?php endif; ?>
