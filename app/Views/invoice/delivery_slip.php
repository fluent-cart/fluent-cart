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
                <h1 style="font-size: 24px;font-weight: 600;margin-bottom: 8px;">Package Delivered Successfully!</h1>
                <p style="opacity: 0.9;font-size: 16px;margin: 0;">Your order has arrived at its destination</p>
            </div>

            <div style="background-color: #fff; border-radius: 8px; ">

                <div style="padding: 20px;">
                    <table style="width: 100%; border: none !important;">
                        <tr style="width: 100%;">
                            <td style="width: 50%; border: none !important;">
                                <h3 style="color: #2F3448; font-size: 16px; font-weight: 600; line-height: 24px; margin-bottom: 10px;"><?php esc_html_e('Order Summary', 'fluent-cart'); ?></h3>
                                <table style="font-size: 14px; font-weight: 400; width: 100%; border-collapse: collapse; border: 0; margin-bottom: 20px;">
                                    <tr>
                                        <td style="color: #565865; border: 0; width: 100px; padding-top: 5px; padding-bottom: 5px; "><?php echo esc_html__('Order ID:', 'fluent-cart') ?></td>
                                        <td style="color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px;">
                                            <a href="{{order.customer_order_link}}" style="color: #007bff; text-decoration: none;">
                                                #{{order.id}}
                                            </a>
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


                    <table style="width: 100%; border: none !important;margin-top: 20px;">
                        <tr style="width: 100%;">
                            <td style="text-align: right;padding-right: 4px;">
                                <a href="https://fluentcart.com" style="padding: 10px 20px;display:inline-block;text-decoration: none;border-radius: 8px;font-weight: 600;font-size: 14px;text-align: center;background: linear-gradient(135deg, #3b82f6, #2563eb);color: white;">
                                    Leave a Review
                                </a>
                            </td>
                            <td style="padding-left: 4px;">
                                <a href="{{order.customer_order_link}}" style="padding: 8px 20px;display:inline-block;text-decoration: none;border-radius: 8px;font-weight: 600;font-size: 14px;text-align: center;background: white;color: #374151;border: 2px solid #d1d5db;">
                                    View Receipt
                                </a>
                            </td>
                        </tr>
                    </table>

                    <div style="background: linear-gradient(135deg, #fef3c7, #fef9e7);border-radius: 8px;padding: 20px;margin: 25px 0 0;text-align: center;border: 1px solid #fbbf24;">
                        <h3 style="color: #b45309;margin: 0 0 8px;font-size: 16px;font-weight: 600;">Thank You for Your Business!</h3>
                        <p style="margin: 0;color: #92400e;font-size: 14px;">We hope you love your purchase. Your satisfaction is our top priority.</p>
                    </div>
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
