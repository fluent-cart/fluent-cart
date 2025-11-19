<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="UTF-8">
    <title><?php use FluentCart\App\App;

        echo esc_html__('Order Created Notification', 'fluent-cart'); ?></title>
</head>

<body style="font-family: 'Arial', sans-serif; background-color: #f4f4f4; padding: 0;margin: 0;">
<div style="background-color: #fff;">
    <div style="background-color: #F6F8FB; padding: 40px;">
        <div class="email-template-content" style="max-width: 600px;width:100%; margin-left: auto; margin-right: auto;background-color: white;padding: 30px;border-radius: 8px;">

            <div class="header" style="text-align: right;margin-bottom: 30px;">
                <h1 style="color: #F5A623;font-size: 24px;font-weight: bold;line-height:1;margin: 0 0 4px 0;letter-spacing: 1px;">PACKAGE SLIP</h1>
                <div style="font-size: 16px;">Package - #{{order.id}}</div>
            </div>

            <table style="width: 100%;border-bottom: 1px solid #000;margin-bottom: 20px;">
                <tr style="width: 100%;">
                    <td style="width: 50%; border: none !important;padding-right: 7px;">
                        <h3 style="color: #2F3448; font-size: 16px; font-weight: 600; line-height: 24px; margin-bottom: 10px;">Bill To</h3>
                        <table style="font-size: 14px; font-weight: 400; width: 100%; border-collapse: collapse; border: 0; margin-bottom: 20px;">
                            <tr>
                                <td style="color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px;">
                                    {{order.billing.full_name}}
                                </td>
                            </tr>
                            <tr>
                                <td style="color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px;">
                                    {{order.billing.address_1}}
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td style="width: 50%; border: none !important;padding-left: 7px;">
                        <h3 style="color: #2F3448; font-size: 16px; font-weight: 600; line-height: 24px; margin-bottom: 10px;text-align: right;">Ship To</h3>
                        <table style="font-size: 14px; font-weight: 400; width: 100%; border-collapse: collapse; border: 0; margin-bottom: 20px;text-align: right;">
                            <tr>
                                <td style="color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px;">
                                    {{order.shipping.full_name}}
                                </td>
                            </tr>
                            <tr>
                                <td style="color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px;">
                                    {{order.shipping.address_1}}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <div>

                <table style="width: 100%; border: none !important;">
                    <tr style="width: 100%;">
                        <td style="width: 50%; border: none !important;">
                            <h3 style="color: #2F3448; font-size: 16px; font-weight: 600; line-height: 24px; margin-bottom: 10px;">Order Summary</h3>
                            <table style="font-size: 14px; font-weight: 400; width: 100%; border-collapse: collapse; border: 0; margin-bottom: 20px;">
                                <tr>
                                    <td style="color: #565865; border: 0; width: 100px; padding-top: 5px; padding-bottom: 5px; ">Order ID:</td>
                                    <td style="color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px;">
                                        #{{order.id}}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color: #565865; border: 0; width: 100px; padding-top: 5px; padding-bottom: 5px;">Date:</td>
                                    <td style="color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px;font-size: 12px;">
                                        {{order.updated_at}}
                                    </td>
                                </tr>
                            </table>
                        </td>

                        <td style="width: 50%; border: none !important;">
                            <h3 style="color: #2F3448; font-size: 16px; font-weight: 600; line-height: 24px; margin-bottom: 10px; text-align: right">Customer Info</h3>
                            <table style="font-size: 14px; font-weight: 400; width: 100%; border-collapse: collapse; border: 0; margin-bottom: 20px;">
                                <tr>
                                    <td style="text-align: right; color: #565865; border: 0; width: 100px; padding-top: 5px; padding-bottom: 5px; ">
                                        {{order.customer.full_name}}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="text-align: right; color: #565865; border: 0; width: 100px; padding-top: 5px; padding-bottom: 5px; ">
                                        {{order.customer.email}}
                                    </td>
                                </tr>

                            </table>
                        </td>
                    </tr>
                </table>


                {{order.payment_summary}}
            </div>

        </div>
        <?php

        App::make('view')->render('emails.components.cta');

        ?>
    </div>

    <?php
    App::make('view')->render('emails.components.powered-by-footer');
    ?>
</div>
</body>
</html>
