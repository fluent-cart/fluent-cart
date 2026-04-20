<?php if (!defined('ABSPATH')) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
?>

<div class="space_bottom_30">
    <p><?php
printf(
        /* translators: %s is the customer's full name */
        esc_html__('Hello %s,', 'fluent-cart'),
        esc_html($order->customer->full_name)
    );
        ?></p>
    <p><?php esc_html_e('Thank you for your order! We have received your order placed with offline payment method. We will process your order once payment is confirmed. Here are the details of your order:', 'fluent-cart'); ?></p>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Order Summary', 'fluent-cart'),
]);

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.addresses', [
    'order' => $order,
]);

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('To view your order details, please visit the order details page. Payment information is included in the email.', 'fluent-cart'),
    'link'        => $order->getViewUrl('customer'),
    'button_text' => __('View Order Details', 'fluent-cart')
]);
