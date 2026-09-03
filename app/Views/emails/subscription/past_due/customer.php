<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var \FluentCart\App\Models\Order $order
 */
?>

<div class="space_bottom_30">
    <p>
        <?php
            printf(
                /* translators: %s is the customer's full name */
                esc_html__( 'Hello %s,', 'fluent-cart' ),
                esc_html($subscription->customer->full_name)
            );
        ?>
    </p>

    <p>
        <?php
            printf(
                /* translators: %s is the subscription item name */
                esc_html__( 'Your subscription to %s is now past due. An unpaid renewal order has exceeded the grace period. Please complete the payment as soon as possible to reactivate your subscription.', 'fluent-cart' ),
                esc_html( $subscription->item_name )
            );
        ?>
    </p>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Unpaid Renewal', 'fluent-cart'),
]);

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('Please complete the payment to reactivate your subscription.', 'fluent-cart'),
    'link'        => $order->getViewUrl('customer'),
    'button_text' => __('Pay Now', 'fluent-cart'),
]);
