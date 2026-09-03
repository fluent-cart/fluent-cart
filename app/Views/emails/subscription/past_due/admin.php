<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var \FluentCart\App\Models\Order $order
 */
?>

<div class="space_bottom_30">
    <p><?php echo esc_html__('Hey there,', 'fluent-cart'); ?></p>
    <p>
        <?php
            printf(
                /* translators: 1: customer full name, 2: subscription item name */
                esc_html__( 'The subscription for %1$s to %2$s has been marked as past due. The renewal order has exceeded the grace period without payment.', 'fluent-cart' ),
                esc_html($subscription->customer->full_name),
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
    'content'     => __('To review this subscription and take action, please check the subscription detail page.', 'fluent-cart'),
    'link'        => $order->getViewUrl('admin'),
    'button_text' => __('View Details', 'fluent-cart'),
]);
