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
        <div class="email-template-content" style="max-width: 600px;width:100%; margin-left: auto; margin-right: auto;background: white;border-radius: 12px;overflow: hidden;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);color: white;padding: 10px 30px 30px 30px;text-align: center;">
                <?php
                App::make('view')->render('emails.components.header');
                ?>
                <h1 style="font-size: 24px;font-weight: 600;margin-bottom: 8px;">Your Order Has Shipped!</h1>
                <p style="opacity: 0.9;font-size: 16px;margin: 0;">We've packaged your items and they're on their way</p>
            </div>

            <div style="padding: 20px;">
                <table style="width: 100%; border: none !important;">
                    <tr style="width: 100%;">
                        <td style="width: 50%; border: none !important;">
                            <h3 style="color: #2F3448; font-size: 16px; font-weight: 600; line-height: 24px; margin-bottom: 10px;"><?php esc_html_e('Order Summary', 'fluent-cart'); ?></h3>
                            <table style="font-size: 14px; font-weight: 400; width: 100%; border-collapse: collapse; border: 0; margin-bottom: 20px;">
                                <tr>
                                    <td style="color: #565865; border: 0; width: 100px; padding-top: 5px; padding-bottom: 5px; "><?php echo esc_html__('Order ID:', 'fluent-cart') ?></td>
                                    <td style="color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px;">
                                        #{{order.id}}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color: #565865; border: 0; width: 100px; padding-top: 5px; padding-bottom: 5px;"><?php echo esc_html__('Date:', 'fluent-cart') ?></td>
                                    <td style="color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px;font-size: 12px;">
                                        {{order.updated_at}}
                                    </td>
                                </tr>
                            </table>
                        </td>

                        <td style="width: 50%; border: none !important;">
                            <h3 style="color: #2F3448; font-size: 16px; font-weight: 600; line-height: 24px; margin-bottom: 10px; text-align: right"><?php esc_html_e('Customer Info', 'fluent-cart'); ?></h3>
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

                <div style="background: #ecfdf5;border: 1px solid #a7f3d0;border-radius: 8px;padding: 20px;margin: 25px 0 0;text-align: center;">
                    <p style="color: #065f46; font-weight: 600; margin: 0 0 5px;">Track Your Package</p>
                    <div style="font-size: 18px;font-weight: 700;color: #065f46;margin: 10px 0;letter-spacing: 0.5px;">#{{order.id}}</div>
                    <a href="#" style="display: inline-block;background: linear-gradient(135deg, #10b981, #059669);color: white;padding: 10px 25px;font-size: 16px;text-decoration: none;border-radius: 6px;font-weight: 600;margin-top: 5px;">Track Shipment</a>
                </div>
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
