<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Per-shipment customer notification.
 *
 * @var \FluentCart\App\Models\Order $order
 * @var \FluentCart\App\Models\Shipment|null $shipment Present when the mail is fired from
 *                fluent_cart/order_shipment_created. Absent when the notification is
 *                previewed / test-sent without a shipment, so every shipment block degrades.
 */

$fctShipment = isset($shipment) ? $shipment : null;

$fctCarrierName    = $fctShipment ? trim((string) $fctShipment->carrier_name) : '';
$fctServiceName    = $fctShipment ? trim((string) \FluentCart\Framework\Support\Arr::get((array) $fctShipment->meta, 'service_name', '')) : '';
$fctTrackingNumber = $fctShipment ? trim((string) $fctShipment->tracking_number) : '';
$fctTrackingUrl    = $fctShipment ? trim((string) $fctShipment->tracking_url) : '';
$fctNotes          = $fctShipment ? trim((string) $fctShipment->notes) : '';
$fctShipmentItems  = ($fctShipment && is_array($fctShipment->items)) ? $fctShipment->items : [];

$fctHasTrackingInfo = $fctCarrierName !== '' || $fctServiceName !== '' || $fctTrackingNumber !== '' || $fctTrackingUrl !== '';
?>

<div class="space_bottom_30">
    <p class="fct-email-greeting">
        <?php
            /* translators: %1$s: customer full name */
            printf(
                esc_html__('Hello %1$s,', 'fluent-cart'),
                esc_html($order->customer->full_name)
            );
        ?>
    </p>
    <p>
        <?php echo esc_html__('Great news! A shipment from your order is on its way to you 📦.', 'fluent-cart'); ?>
    </p>
</div>

<?php if ($fctHasTrackingInfo): ?>
    <p style="font-size:16px;font-weight:500;color:rgb(44,62,80);line-height:24px;margin: 0 0 16px;">
        <?php echo esc_html__('Tracking Details', 'fluent-cart'); ?>
    </p>
    <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"
           style="width:100%;border-spacing:0;border-collapse:collapse;margin-bottom:24px;">
        <tbody>
        <tr>
            <td style="background-color:rgb(249,250,251);padding:16px;border-radius:8px;">
                <table role="presentation" width="100%"
                       style="width:100%;border-spacing:0;border-collapse:collapse;border:none;">
                    <tbody>
                    <?php if ($fctCarrierName !== ''): ?>
                        <tr>
                            <td style="width:40%;padding-block:4px;padding-inline:0 12px;">
                                <p style="font-size:12px;color:rgb(127,140,141);margin:0;text-transform:uppercase;letter-spacing:1px;line-height:20px;">
                                    <?php echo esc_html__('Carrier', 'fluent-cart'); ?>
                                </p>
                            </td>
                            <td style="width:60%;padding-block:4px;padding-inline:0;">
                                <p style="font-size:14px;color:rgb(44,62,80);margin:0;line-height:20px;">
                                    <?php echo esc_html($fctCarrierName); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($fctServiceName !== ''): ?>
                        <tr>
                            <td style="width:40%;padding-block:4px;padding-inline:0 12px;">
                                <p style="font-size:12px;color:rgb(127,140,141);margin:0;text-transform:uppercase;letter-spacing:1px;line-height:20px;">
                                    <?php echo esc_html__('Service', 'fluent-cart'); ?>
                                </p>
                            </td>
                            <td style="width:60%;padding-block:4px;padding-inline:0;">
                                <p style="font-size:14px;color:rgb(44,62,80);margin:0;line-height:20px;">
                                    <?php echo esc_html($fctServiceName); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($fctTrackingNumber !== ''): ?>
                        <tr>
                            <td style="width:40%;padding-block:4px;padding-inline:0 12px;">
                                <p style="font-size:12px;color:rgb(127,140,141);margin:0;text-transform:uppercase;letter-spacing:1px;line-height:20px;">
                                    <?php echo esc_html__('Tracking Number', 'fluent-cart'); ?>
                                </p>
                            </td>
                            <td style="width:60%;padding-block:4px;padding-inline:0;">
                                <p style="font-size:14px;color:rgb(44,62,80);margin:0;line-height:20px;word-break:break-word;">
                                    <?php if ($fctTrackingUrl !== ''): ?>
                                        <a href="<?php echo esc_url($fctTrackingUrl); ?>"
                                           style="color:#017EF3;text-decoration:none;" target="_blank">
                                            <?php echo esc_html($fctTrackingNumber); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo esc_html($fctTrackingNumber); ?>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($fctTrackingUrl !== ''): ?>
                        <tr>
                            <td style="width:40%;padding-block:4px;padding-inline:0 12px;">
                                <p style="font-size:12px;color:rgb(127,140,141);margin:0;text-transform:uppercase;letter-spacing:1px;line-height:20px;">
                                    <?php echo esc_html__('Track Shipment', 'fluent-cart'); ?>
                                </p>
                            </td>
                            <td style="width:60%;padding-block:4px;padding-inline:0;">
                                <p style="font-size:14px;margin:0;line-height:20px;word-break:break-all;">
                                    <a href="<?php echo esc_url($fctTrackingUrl); ?>"
                                       style="color:#017EF3;text-decoration:none;" target="_blank">
                                        <?php echo esc_html($fctTrackingUrl); ?>
                                    </a>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </td>
        </tr>
        </tbody>
    </table>
<?php endif; ?>

<?php if (!empty($fctShipmentItems)): ?>
    <p style="font-size:16px;font-weight:500;color:rgb(44,62,80);line-height:24px;margin: 0 0 16px;">
        <?php echo esc_html__('Items in this Shipment', 'fluent-cart'); ?>
    </p>
    <?php
        \FluentCart\App\App::make('view')->render('emails.parts.shipment_items', [
            'items' => $fctShipmentItems,
        ]);
    ?>
<?php endif; ?>

<?php if ($fctNotes !== ''): ?>
    <p style="font-size:16px;font-weight:500;color:rgb(44,62,80);line-height:24px;margin: 0 0 8px;">
        <?php echo esc_html__('Shipment Notes', 'fluent-cart'); ?>
    </p>
    <p style="font-size:14px;color:rgb(55,65,81);line-height:22px;margin:0 0 24px;">
        <?php echo nl2br(esc_html($fctNotes)); ?>
    </p>
<?php endif; ?>

<?php

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.addresses', [
    'order' => $order,
]);

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => esc_html__('Thank you for choosing us! If you have any questions about this shipment, feel free to reach out.', 'fluent-cart'),
    'link'        => $order->getViewUrl('customer'),
    'button_text' => esc_html__('View Order', 'fluent-cart'),
]);
