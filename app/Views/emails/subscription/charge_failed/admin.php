<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var string|null $error Gateway failure reason
 * @var int|null $attempt Attempt number that failed
 * @var string|null $next_retry_at GMT datetime of the next automatic retry, if any
 */

$nextRetryAt = isset($next_retry_at) ? $next_retry_at : null;
$attemptNo = isset($attempt) ? (int) $attempt : 1;
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

    <p>
        <?php
            printf(
                /* translators: %1$d is the failed attempt number, %2$s is the failure reason */
                esc_html__( 'Attempt %1$d failed with: %2$s', 'fluent-cart' ),
                (int) $attemptNo,
                '<em>' . esc_html( isset($error) ? $error : '' ) . '</em>'
            );
        ?>
    </p>

    <p>
        <?php
            if ($nextRetryAt) {
                printf(
                    /* translators: %1$s is the date/time of the next automatic charge attempt */
                    esc_html__( 'The next automatic attempt is scheduled for %1$s (GMT).', 'fluent-cart' ),
                    '<strong>' . esc_html( $nextRetryAt ) . '</strong>'
                );
            } else {
                esc_html_e( 'No further automatic attempts are scheduled — the renewal order follows the normal overdue flow.', 'fluent-cart' );
            }
        ?>
    </p>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Renewal Summary', 'fluent-cart'),
]);
