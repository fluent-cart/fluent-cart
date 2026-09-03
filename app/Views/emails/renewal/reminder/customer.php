<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order
 */
?>

<div class="space_bottom_30">
    <p>
        <?php
            printf(
                /* translators: %s is the customer's full name */
                esc_html__( 'Hello %s,', 'fluent-cart' ),
                esc_html($order->customer->full_name)
            );
        ?>
    </p>

    <p>
        <?php
            printf(
                /* translators: %s is the renewal order number */
                esc_html__( 'This is a friendly reminder that your renewal order %s is still awaiting payment. Please complete the payment at your earliest convenience to avoid any interruption to your subscription.', 'fluent-cart' ),
                esc_html( $order->invoice_no )
            );
        ?>
    </p>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Renewal Summary', 'fluent-cart'),
]);

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('Please complete the payment to keep your subscription active.', 'fluent-cart'),
    'link'        => $order->getViewUrl('customer'),
    'button_text' => __('Pay Now', 'fluent-cart'),
]);
