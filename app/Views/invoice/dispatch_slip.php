<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="UTF-8">
    <title><?php use FluentCart\App\App;

        echo esc_html__('Order Created Notification', 'fluent-cart'); ?>
    </title>
</head>

<body style="font-family: 'Arial', sans-serif; background-color: #f4f4f4; padding: 0;margin: 0;">
<div style="background-color: #fff;">
    <div style="background-color: #F6F8FB; padding: 40px;">
        <div class="email-template-content" style="max-width: 600px;width:100%; margin-left: auto; margin-right: auto;background: white;border-radius: 12px;overflow: hidden;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);color: white;padding: 10px 30px 30px 30px;text-align: center;">
                <?php
                App::make('view')->render('emails.components.header');
                ?>
                <h1 style="font-size: 24px;font-weight: 600;margin-bottom: 8px;">Dispatch Notification</h1>
                <p style="opacity: 0.9;font-size: 16px;margin: 0;">Order ready for shipment from fulfillment center</p>
            </div>

            <div style="background-color: #fff; border-radius: 8px; ">
                <div style="padding: 20px;">

                    <div style="background: linear-gradient(135deg, #fef3c7, #fbbf24);border-radius: 10px;padding: 25px;margin-bottom: 30px;text-align: center;border: 2px solid #f59e0b;">
                        <h2 style="color: #92400e;font-size: 20px;margin-bottom: 10px;font-weight: 600;">Order Dispatched</h2>
                        <div style="color: #b45309;font-size: 16px;font-weight: 500;">{{order.updated_at}}</div>
                        <div style="display: inline-block;background: #059669;color: white;padding: 6px 12px;border-radius: 20px;font-size: 12px;font-weight: 600;text-transform: uppercase;letter-spacing: 0.5px;margin-top: 10px;">Standard Priority</div>
                    </div>

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
                                    <tr>
                                        <td style="color: #565865; border: 0; width: 100px; padding-top: 5px; padding-bottom: 5px;">Delivery Method:</td>
                                        <td>
                                            <span style="display: block; border: 1px none; border-bottom-style: dashed; margin-left: 12px"></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="color: #565865; border: 0; width: 100px; padding-top: 5px; padding-bottom: 5px;">Dispatch Date:</td>
                                        <td>
                                            <span style="display: block; border: 1px none; border-bottom-style: dashed; margin-left: 12px"></span>
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

                    <div style="background: #f0f9ff;border: 1px solid #7dd3fc;border-radius: 8px;padding: 20px;margin-bottom: 25px;">
                        <div style="color: #0c4a6e;font-weight: 600;margin-bottom: 15px;font-size: 16px;">Fulfillment Center Details</div>
                        <table style="width: 100%; border: none !important;">
                            <tr>
                                <td style="width: 50%; border: none !important;padding-right: 5px;vertical-align: top">
                                    <h4 style="font-size: 12px;color: #0369a1;text-transform: uppercase;font-weight: 700;margin: 0 0 5px;">Location</h4>
                                    <div style="color: #0c4a6e;font-size: 14px;line-height: 1.4;">
                                        Distribution Center East
                                        123 Warehouse Blvd
                                        Industrial Park, NY 12345
                                    </div>
                                </td>
                                <td style="width: 50%; border: none !important;padding-left: 5px;">
                                    <h4 style="font-size: 12px;color: #0369a1;text-transform: uppercase;font-weight: 700;margin: 0 0 5px;"">Operator</h4>
                                    <div style="color: #0c4a6e;font-size: 14px;line-height: 1.4;">
                                        Johnson <br>
                                        Employee ID: ... <br>
                                        Shift: Morning (7AM-3PM)
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width: 50%; border: none !important;padding-right: 5px;">
                                    <h4 style="font-size: 12px;color: #0369a1;text-transform: uppercase;font-weight: 700;margin: 0 0 5px;"">Zone</h4>
                                    <div style="color: #0c4a6e;font-size: 14px;line-height: 1.4;">
                                        Picking Zone: A-3 <br>
                                        Packing Station: P-12 <br>
                                        Loading Dock: D-5
                                    </div>
                                </td>
                            </tr>

                        </table>
                    </div>

                    <div>
                        <h3 style="font-size: 18px;color: #374151;font-weight: 600;margin: 0;">Items Dispatched</h3>
                        <hr style="margin-bottom: 20px;">
                        {{order.payment_summary}}
                    </div>

                    <div style="background: #fffbeb;border: 1px solid #fbbf24;border-radius: 8px;padding: 20px;margin: 25px 0;">
                        <div style="color: #92400e;font-weight: 600;margin-bottom: 10px;font-size: 14px;">Dispatch Notes</div>
                        <div style="color: #b45309;font-size: 14px;line-height: 1.5;">
                            • All items verified and quality checked<br>
                            • Package sealed and labeled for FedEx pickup<br>
                            • Customer requested signature confirmation<br>
                            • Fragile items packed with extra protection
                        </div>
                    </div>

                    <p style="font-size: 14px;margin-bottom: 0;">
                        <?php echo esc_html__('Items Received By', 'fluent-cart'); ?>
                    </p>

                    <table style="width: 100%">
                        <tr>
                            <td style="text-align: left; color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px; vertical-align: bottom">
                                <span style="font-size: 12px">
                                    <?php echo esc_html__('Name:', 'fluent-cart'); ?>
                                </span>
                                <span style="display: inline-block; width: 100px; border: 1px none; border-bottom-style: dashed; margin-left: 12px">

                                </span>
                            </td>
                            <td style="text-align: center; color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px; vertical-align: bottom">
                                <span style="font-size: 12px">
                                    <?php echo esc_html__('Signature:', 'fluent-cart'); ?>
                                </span>
                                <span style="display: inline-block; width: 100px; border: 1px none; border-bottom-style: dashed; margin-left: 12px"></span>
                            </td>
                            <td style="text-align: right; color: #2F3448; border: 0; padding-top: 5px; padding-bottom: 3px; vertical-align: bottom">
                                <span style="font-size: 12px">
                                    <?php echo esc_html__('Date:', 'fluent-cart'); ?>
                                </span>
                                <span style="display: inline-block; width: 100px; border: 1px none; border-bottom-style: dashed; margin-left: 12px">

                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <?php
    App::make('view')->render('emails.components.powered-by-footer');
    ?>

</div>
</body>
</html>
