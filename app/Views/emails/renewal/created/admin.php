<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order
 * @var \FluentCart\App\Models\Subscription $subscription
 */
?>

<div class="space_bottom_30">
    <p><?php echo esc_html__('Hey there,', 'fluent-cart'); ?></p>
    <p>
        <?php
            printf(
                /* translators: 1: customer full name, 2: subscription item name */
                esc_html__( 'A new renewal order has been created for %1$s\'s subscription to %2$s. The renewal order is pending payment.', 'fluent-cart' ),
                esc_html($order->customer->full_name),
                esc_html( $subscription->item_name )
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
    'content'     => __('To view more details of this renewal order, please check the order detail page.', 'fluent-cart'),
    'link'        => $order->getViewUrl('admin'),
    'button_text' => __('View Details', 'fluent-cart'),
]);
