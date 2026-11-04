<?php if (!defined('ABSPATH')) exit; ?>
<?php
/** @var array $summary */
use FluentCart\App\Services\Email\EmailDirection;

$startSide = EmailDirection::start();
$endSide = EmailDirection::end();
?>
<p style="font-size:16px;font-weight:500;color:rgb(44,62,80);line-height:24px;margin:0 0 16px;">
    <?php esc_html_e('Renewal Summary', 'fluent-cart'); ?>
</p>
<table role="presentation" width="100%" style="border-spacing:0;border:none;">
    <thead>
    <tr>
        <th style="background-color:rgb(249,250,251);padding:8px 16px;text-align:<?php echo esc_attr($startSide); ?>;font-size:12px;color:rgb(55,65,81);">
            <?php esc_html_e('Item', 'fluent-cart'); ?>
        </th>
        <th style="background-color:rgb(249,250,251);padding:8px 16px;text-align:<?php echo esc_attr($endSide); ?>;font-size:12px;color:rgb(55,65,81);">
            <?php esc_html_e('Amount Due', 'fluent-cart'); ?>
        </th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td style="padding:12px 16px;font-size:15px;color:rgb(47,52,72);">
            <?php echo esc_html($summary['item_name']); ?>
        </td>
        <td style="padding:12px 16px;text-align:<?php echo esc_attr($endSide); ?>;font-size:14px;font-weight:700;color:rgb(17,24,39);">
            <?php echo EmailDirection::amount($summary['amount']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </td>
    </tr>
    <tr>
        <td style="padding:8px 16px;font-size:14px;color:rgb(55,65,81);">
            <?php esc_html_e('Payment Method', 'fluent-cart'); ?>
        </td>
        <td style="padding:8px 16px;text-align:<?php echo esc_attr($endSide); ?>;font-size:14px;color:rgb(55,65,81);">
            <?php echo esc_html($summary['payment_method']); ?>
        </td>
    </tr>
    </tbody>
</table>
