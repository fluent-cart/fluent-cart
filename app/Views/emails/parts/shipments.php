<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Every shipment of an order, for the {{order.shipments}} smartcode.
 *
 * @var $order     \FluentCart\App\Models\Order
 * @var $shipments \FluentCart\Framework\Database\Orm\Collection|\FluentCart\App\Models\Shipment[]
 */

use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\DateTime\DateFormatter;
use FluentCart\Framework\Support\Arr;

$fctShipments = isset($shipments) ? $shipments : [];
$fctStatuses  = Shipment::getStatuses();

$fctLabelStyle = 'font-size:12px;color:rgb(127,140,141);margin:0;text-transform:uppercase;letter-spacing:1px;line-height:20px;';
$fctValueStyle = 'font-size:14px;color:rgb(44,62,80);margin:0;line-height:20px;word-break:break-word;';
?>

<?php foreach ($fctShipments as $fctShipment): ?>
    <?php
        $fctCarrierName    = trim((string) $fctShipment->carrier_name);
        $fctServiceName    = trim((string) Arr::get((array) $fctShipment->meta, 'service_name', ''));
        $fctTrackingNumber = trim((string) $fctShipment->tracking_number);
        $fctTrackingUrl    = trim((string) $fctShipment->tracking_url);
        $fctStatusKey      = (string) $fctShipment->status;
        $fctStatusLabel    = isset($fctStatuses[$fctStatusKey]) ? $fctStatuses[$fctStatusKey] : $fctStatusKey;

        $fctShippedAt = DateFormatter::format($fctShipment->shipped_at, false, isset($order) ? $order : null);

        $fctShipmentItems = is_array($fctShipment->items) ? $fctShipment->items : [];
    ?>
    <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"
           style="width:100%;border-spacing:0;border-collapse:collapse;margin-bottom:16px;">
        <tbody>
        <tr>
            <td style="background-color:rgb(249,250,251);padding:16px;border-radius:8px;">
                <table role="presentation" width="100%"
                       style="width:100%;border-spacing:0;border-collapse:collapse;border:none;">
                    <tbody>
                    <?php if ($fctCarrierName !== ''): ?>
                        <tr>
                            <td style="width:40%;padding-block:4px;padding-inline:0 12px;">
                                <p style="<?php echo esc_attr($fctLabelStyle); ?>"><?php echo esc_html__('Carrier', 'fluent-cart'); ?></p>
                            </td>
                            <td style="width:60%;padding-block:4px;padding-inline:0;">
                                <p style="<?php echo esc_attr($fctValueStyle); ?>"><?php echo esc_html($fctCarrierName); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($fctServiceName !== ''): ?>
                        <tr>
                            <td style="width:40%;padding-block:4px;padding-inline:0 12px;">
                                <p style="<?php echo esc_attr($fctLabelStyle); ?>"><?php echo esc_html__('Service', 'fluent-cart'); ?></p>
                            </td>
                            <td style="width:60%;padding-block:4px;padding-inline:0;">
                                <p style="<?php echo esc_attr($fctValueStyle); ?>"><?php echo esc_html($fctServiceName); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td style="width:40%;padding-block:4px;padding-inline:0 12px;">
                            <p style="<?php echo esc_attr($fctLabelStyle); ?>"><?php echo esc_html__('Status', 'fluent-cart'); ?></p>
                        </td>
                        <td style="width:60%;padding-block:4px;padding-inline:0;">
                            <p style="<?php echo esc_attr($fctValueStyle); ?>"><?php echo esc_html($fctStatusLabel); ?></p>
                        </td>
                    </tr>
                    <?php if ($fctTrackingNumber !== ''): ?>
                        <tr>
                            <td style="width:40%;padding-block:4px;padding-inline:0 12px;">
                                <p style="<?php echo esc_attr($fctLabelStyle); ?>"><?php echo esc_html__('Tracking Number', 'fluent-cart'); ?></p>
                            </td>
                            <td style="width:60%;padding-block:4px;padding-inline:0;">
                                <p style="<?php echo esc_attr($fctValueStyle); ?>">
                                    <?php if ($fctTrackingUrl !== ''): ?>
                                        <a href="<?php echo esc_url($fctTrackingUrl); ?>" style="color:#017EF3;text-decoration:none;" target="_blank"><?php echo esc_html($fctTrackingNumber); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($fctTrackingNumber); ?>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($fctShippedAt !== ''): ?>
                        <tr>
                            <td style="width:40%;padding-block:4px;padding-inline:0 12px;">
                                <p style="<?php echo esc_attr($fctLabelStyle); ?>"><?php echo esc_html__('Shipped', 'fluent-cart'); ?></p>
                            </td>
                            <td style="width:60%;padding-block:4px;padding-inline:0;">
                                <p style="<?php echo esc_attr($fctValueStyle); ?>"><?php echo esc_html($fctShippedAt); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </td>
        </tr>
        </tbody>
    </table>

    <?php
        \FluentCart\App\App::make('view')->render('emails.parts.shipment_items', [
            'items' => $fctShipmentItems,
        ]);
    ?>
<?php endforeach; ?>
