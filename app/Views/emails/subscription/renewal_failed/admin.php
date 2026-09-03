<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var string $error Gateway failure reason
 */
?>

<div class="space_bottom_30">
    <p>
        <?php
            printf(
                /* translators: %1$s is the renewal order number, %2$s is the customer's full name */
                esc_html__( 'The automatic renewal charge for renewal #%1$s (customer: %2$s) failed.', 'fluent-cart' ),
                esc_html( $order->invoice_no ?: $order->id ),
                esc_html( $order->customer->full_name )
            );
        ?>
    </p>

    <?php if (!empty($error)): ?>
    <p>
        <?php
            printf(
                /* translators: %1$s is the payment failure reason reported by the payment provider */
                esc_html__( 'Reason: %1$s', 'fluent-cart' ),
                '<em>' . esc_html( $error ) . '</em>'
            );
        ?>
    </p>
    <?php endif; ?>

    <p>
        <?php esc_html_e( 'The gateway will retry the charge on its own schedule.', 'fluent-cart' ); ?>
    </p>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Renewal Summary', 'fluent-cart'),
]);
