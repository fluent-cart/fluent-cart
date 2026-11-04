<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var string $error Gateway failure reason
 * @var array|null $renewal_payment Gateway-reported renewal amount (cents) and currency, when known
 */
?>

<div class="space_bottom_30">
    <p>
        <?php
            printf(
                /* translators: %1$s is the renewal order number, %2$s is the customer's full name */
                esc_html__( 'The automatic renewal charge for renewal #%1$s (customer: %2$s) failed.', 'fluent-cart' ),
                esc_html( $order->invoice_no ?: $order->uuid ),
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
        <?php esc_html_e( 'Any retry is up to the payment gateway.', 'fluent-cart' ); ?>
    </p>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.renewal_summary', [
    'summary' => \FluentCart\App\Services\Email\SubscriptionRenewalSummary::make($subscription, $order, $renewal_payment ?? []),
]);
