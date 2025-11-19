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
        <div class="email-template-content" style="max-width: 600px;width: 100%; margin-left: auto; margin-right: auto;">
            <div style="background-color: #fff; border-radius: 8px; ">
                <?php
                    App::make('view')->render('emails.components.header');
                ?>
                <div style="padding: 20px;">

                    <div class="no-print">
                        <h2 style="margin-top: 0; margin-bottom: 15px; font-size: 20px; font-weight: 600; color: #2F3448;"><?php echo esc_html__('Order Created', 'fluent-cart') ?></h2>

                        <p style="font-size: 14px; margin-top: 0; margin-bottom: 4px; color: #2F3448;"><?php echo esc_html__('Hello ', 'fluent-cart') ?>
                            {{order.billing.first_name}}!</p>

                        <p style="font-size: 14px; margin: 0; color: #2F3448;">
                            <?php echo esc_html__('Your order ', 'fluent-cart') ?>

                            <strong style="color: #007bff;">
                                <a href="{{order.customer_order_link}}" style="color: #007bff; text-decoration: none;">
                                    #{{order.id}}
                                </a>
                            </strong>

                            <?php echo esc_html__('has been created. Please check the details below', 'fluent-cart'); ?>
                        </p>
                        <div style="height: 1px; background-color: #EAECF0; margin-top: 20px; margin-bottom: 20px;"></div>
                    </div>


                    <div>

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
                    </div>
                </div>
            </div>
        </div>
        <?php

        App::make('view')->render('emails.components.cta');

        ?>
    </div>

</div>
</body>
</html>
