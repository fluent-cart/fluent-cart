<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div style="position: relative;">
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 0px; border: 0;">
        <tbody>
        <tr>
            <td style="vertical-align: middle; width: 70%; border: 0; padding: 16px 10px 16px 0;">
                <p style="margin: 0;font-weight: 700;font-size: 24px;">{{settings.store_brand}}</p>
            </td>
            <td style="vertical-align: middle; width: 15%; text-align: right; border: 0; padding: 16px;">
                <p style="white-space: nowrap; color: #94a3b8; text-align: right; margin: 0;font-size:12px;"><?php echo esc_html__('Order Date', 'fluent-cart'); ?></p>
                <p style="white-space: nowrap; font-weight: bold; color: #000; text-align: right; margin: 0;font-size:15px;">{{order.created_at}}</p>
            </td>
            <td style="vertical-align: middle; width: 15%; text-align: right; border: 0; padding: 16px 0 16px 10px;">
                <p style="white-space: nowrap; color: #94a3b8; text-align: right; margin: 0;font-size:12px;"><?php echo esc_html__('Receipt #', 'fluent-cart'); ?></p>
                <p style="white-space: nowrap; font-weight: bold; color: #000; text-align: right; margin: 0;font-size:15px;">
                    <a style="text-decoration: underline; color: #000;" href="{{order.customer_dashboard_link}}">{{order.invoice_no}}</a>
                </p>
            </td>
        </tr>
        </tbody>
    </table>
</div>
