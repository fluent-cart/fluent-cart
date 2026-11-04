<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Items in one shipment.
 *
 * @var array $items Stored shipment item rows: ['order_item_id', 'qty', 'item_name'].
 */

use FluentCart\Framework\Support\Arr;

$fctItems = isset($items) && is_array($items) ? $items : [];

if (empty($fctItems)) {
    return;
}
?>

<table role="presentation" style="border-spacing:0;padding:0;width:100%;border:none;margin-bottom:24px;">
    <thead>
    <tr>
        <th style="background-color:rgb(249,250,251);padding-inline-start:16px;">
            <p style="font-size:12px;font-weight:600;color:rgb(55,65,81);text-transform:uppercase;line-height:24px;margin:0;text-align:start;">
                <?php echo esc_html__('Item', 'fluent-cart'); ?>
            </p>
        </th>
        <th style="background-color:rgb(249,250,251);padding-inline-end:16px;width:80px;">
            <p style="font-size:12px;font-weight:600;color:rgb(55,65,81);text-transform:uppercase;line-height:24px;margin:0;text-align:end;">
                <?php echo esc_html__('Qty', 'fluent-cart'); ?>
            </p>
        </th>
    </tr>
    </thead>
    <tbody style="width:100%">
    <?php foreach ($fctItems as $fctShipmentItem): ?>
        <?php
            $fctItemName = trim((string) Arr::get($fctShipmentItem, 'item_name', ''));
            if ($fctItemName === '') {
                $fctItemName = __('Item', 'fluent-cart');
            }
            $fctItemQty = (int) Arr::get($fctShipmentItem, 'qty', 0);
        ?>
        <tr>
            <td style="padding-inline-start:16px;padding-block:8px;">
                <p style="font-size:15px;color:#2F3448;font-weight:500;line-height:18px;margin:0;">
                    <?php echo esc_html($fctItemName); ?>
                </p>
            </td>
            <td style="padding-inline-end:16px;text-align:end;">
                <p style="font-size:14px;font-weight:700;color:rgb(17,24,39);margin:0;line-height:24px;">
                    <?php echo esc_html($fctItemQty); ?>
                </p>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
