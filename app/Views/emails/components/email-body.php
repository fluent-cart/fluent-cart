<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div style="overflow-x: hidden">
    <div style="background-color: #ffffff; padding: 0px;">
<!--        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 0; background-color: #f1f5f9; print-color-adjust: exact;">-->
<!--            <tbody>-->
<!--            <tr style="border: 0;">-->
<!--                <td style="vertical-align: top; width: 50%; border: 0; padding: 16px;">-->
<!--                    <div style="color: #333333; font-size: 14px; font-weight: bold; margin-bottom: 10px;">{{settings.store_name}}</div>-->
<!--                    <div style="color: #555555; font-size: 14px;">-->
<!--                        {{settings.store_address}}, {{settings.store_address2}}, {{settings.store_state}}<br />-->
<!--                        {{settings.store_city}}, {{settings.store_postcode}}, {{settings.store_country}}<br />-->
<!--                        https://wpmanageninja.com-->
<!--                    </div>-->
<!--                </td>-->
<!--                <td style="vertical-align: top; width: 50%; text-align: right; border: 0; padding: 16px;">-->
<!--                    <div style="color: #333333; font-size: 14px; font-weight: bold; margin-bottom: 10px;">{{order.billing.full_name}}</div>-->
<!--                    <div style="color: #555555; font-size: 14px;">-->
<!--                        {{order.billing.address_1}}, {{settings.store_city}}, {{settings.store_state}}<br />-->
<!--                        {{settings.store_country}}, {{settings.store_postcode}}<br />-->
<!--                        {{order.billing.email}} <br />-->
<!--                        <span>Payment Status: <span style="color: #008000; font-weight: bold;text-transform: uppercase">{{order.payment_status}}</span></span><br>-->
<!--                        Payment Method: {{order.payment_method}}-->
<!--                    </div>-->
<!--                </td>-->
<!--            </tr>-->
<!--            </tbody>-->
<!--        </table>-->
        <div>{{order.payment_summary}}</div>
        <div style="text-align: center; margin-bottom: 20px;">{{order.downloads}}</div>
        <div style="text-align: center; margin-bottom: 20px;">{{order.licenses}}</div>
    </div>
</div>
