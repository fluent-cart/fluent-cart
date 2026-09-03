<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order
 * @var \FluentCart\App\Models\Subscription $subscription
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

    <?php
        $dueDate = $order->getMeta('due_date');
        $formattedDueDate = $dueDate ? date_i18n( get_option('date_format'), strtotime($dueDate) ) : '';
    ?>

    <p>
        <?php
            printf(
                /* translators: %s is the subscription item name */
                esc_html__( 'Your upcoming renewal order for %s is ready.', 'fluent-cart' ),
                esc_html( $subscription->item_name )
            );
        ?>
    </p>

    <?php if ($formattedDueDate): ?>
    <p>
        <?php
            printf(
                /* translators: %s is the due date */
                esc_html__( 'You can pay in advance at any time. Payment is due by %s.', 'fluent-cart' ),
                '<strong>' . esc_html( $formattedDueDate ) . '</strong>'
            );
        ?>
    </p>
    <?php endif; ?>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Renewal Summary', 'fluent-cart'),
]);

echo '<hr />';

\FluentCart\App\App::make('view')->render('emails.parts.addresses', [
    'order' => $order,
]);

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => $formattedDueDate
        ? sprintf(
            /* translators: %s is the due date */
            esc_html__( 'Your payment is due by %s. You can pay early — no need to wait until the due date.', 'fluent-cart' ),
            esc_html( $formattedDueDate )
          )
        : esc_html__( 'You can pay at any time before the due date.', 'fluent-cart' ),
    'link'        => \FluentCart\App\Services\Payments\PaymentHelper::getCustomPaymentLink($order->uuid),
    'button_text' => __('Pay Now', 'fluent-cart'),
]);
