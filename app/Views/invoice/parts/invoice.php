<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
?>

<h2 style='margin-bottom: 20px;'>Order Summary</h2>

<table style='width: 100%; margin-bottom: 30px;'>
    <tr>
        <td style='width: 50%; vertical-align: top;'>
            <div style='font-weight: bold; font-size: 16px; margin-bottom: 10px;'>Order Info</div>
            <div style='font-size: 14px; margin-bottom: 6px;'><strong>Order ID:</strong> #<?php echo esc_html($order->id)?></div>
            <div style='font-size: 14px;'><strong>Date:</strong> <?php echo esc_html($orderDate)?></div>
        </td>
        <td style='width: 50%; vertical-align: top; text-align: right;'>
            <div style='font-weight: bold; font-size: 16px; margin-bottom: 10px;'>Customer Info</div>
            <div style='font-size: 14px; margin-bottom: 6px;'><strong>Name:</strong> <?php echo esc_html($customerName)?></div>
            <div style='font-size: 14px;'><strong>Email:</strong> <?php echo esc_html($customerEmail)?></div>
        </td>
    </tr>
</table>

<table border='1' style='width: 100%; border-collapse: collapse;'>
    <thead>
    <tr style='background: #f9f9f9; text-align: left;'>
        <th style='padding: 10px;'>Products</th>
        <th style='padding: 10px; text-align: center;'>Quantity</th>
        <th style='padding: 10px; text-align: center;'>Price</th>
        <th style='padding: 10px; text-align: center;'>Total Price</th>
    </tr>
    </thead>
    <tbody>
    <?php
        foreach ($order->order_items as $item) {
            $product = $item->product;
            echo wp_kses_post(App::view()->make('invoice.parts.table_row',[
                'product' => $product,
                'item' => $item,
            ]));
        }
    ?>
    </tbody>
</table>

<table style='width: 100%; margin-top: 20px;'>
    <tr>
        <td style='text-align: right; font-weight: bold;'>Subtotal:</td>
        <td style='text-align: right;'><?php echo esc_html(Helper::toDecimal($subtotal)); ?></td>
    </tr>
    <tr>
        <td style='text-align: right; font-weight: bold;'>Discount:</td>
        <td style='text-align: right;'>- <?php echo esc_html(Helper::toDecimal($subtotal-$total)); ?> </td>
    </tr>
    <tr>
        <td style='text-align: right; font-weight: bold;'>Total:</td>
        <td style='text-align: right; font-size: 16px;'>  <?php echo esc_html(Helper::toDecimal($total)); ?></td>
    </tr>
</table>
