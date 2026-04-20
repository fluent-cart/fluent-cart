<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $order \FluentCart\App\Models\Order
 */
?>

<div class="space_bottom_30">
    <?php if($order->payment_status === \FluentCart\App\Helpers\Status::PAYMENT_REFUNDED): ?>
        <p>
            <?php
                printf(
                    /* translators: %s is the customer's full name */
                    esc_html__('A full refund has been processed for %s. Here are the details:', 'fluent-cart'),
                    esc_html($order->customer->full_name)
                );
            ?>
        </p>
    <?php else: ?>
        <p>
            <?php
                printf(
                    /* translators: %s is the customer's full name */
                    esc_html__('A partial refund has been processed for %s. Here are the details:', 'fluent-cart'),
                    esc_html($order->customer->full_name)
                );
            ?>
        </p>
    <?php endif; ?>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Summary', 'fluent-cart'),
    'is_refund'      => true,
]);

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('View the details of this refund on the order details page.', 'fluent-cart'),
    'link'        => $order->getViewUrl('admin'),
    'button_text' => __('View Details', 'fluent-cart')
]);
