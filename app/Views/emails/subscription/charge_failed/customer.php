<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var string $error Gateway failure reason
 * @var string|null $next_retry_at GMT datetime of the next automatic retry, if any
 */

$paymentMethodText = $subscription->getPaymentMethodText();
$nextRetryAt = isset($next_retry_at) ? $next_retry_at : null;
$formattedRetryDate = $nextRetryAt ? date_i18n(get_option('date_format'), strtotime($nextRetryAt)) : '';
// getCustomerDashboardUrl() returns a broken relative path ("/subscription/{uuid}")
// when no customer profile page is configured — only render the CTA when the
// dashboard base actually exists.
$updateMethodUrl = (new \FluentCart\Api\StoreSettings())->getCustomerProfilePage()
    ? \FluentCart\App\Services\URL::getCustomerDashboardUrl('subscription/' . $subscription->uuid)
    : '';
// Admin preview renders this template with stand-in objects, not persisted
// models — isExhausted() type-hints Order, so gate the call on a real model.
$payNowAllowed = ($order instanceof \FluentCart\App\Models\Order)
    && \FluentCart\App\Modules\Subscriptions\Services\SystemChargeService::isExhausted($subscription, $order);
?>

<div class="space_bottom_30">
    <p>
        <?php
            printf(
                /* translators: %1$s is the customer's full name */
                esc_html__( 'Hello %1$s,', 'fluent-cart' ),
                esc_html($order->customer->full_name)
            );
        ?>
    </p>

    <p>
        <?php
            if ($paymentMethodText) {
                printf(
                    /* translators: %1$s is the subscription item name, %2$s is the saved payment method (e.g. visa ***4242) */
                    esc_html__( 'We were unable to charge your saved payment method (%2$s) for the renewal of %1$s.', 'fluent-cart' ),
                    esc_html( $subscription->item_name ),
                    esc_html( $paymentMethodText )
                );
            } else {
                printf(
                    /* translators: %1$s is the subscription item name */
                    esc_html__( 'We were unable to charge your saved payment method for the renewal of %1$s.', 'fluent-cart' ),
                    esc_html( $subscription->item_name )
                );
            }
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
        <?php
            if ($payNowAllowed) {
                if ($formattedRetryDate) {
                    if ($updateMethodUrl) {
                        printf(
                            /* translators: %1$s is the date of the next automatic charge attempt */
                            esc_html__( 'We will automatically try again on %1$s. To avoid any interruption, you can pay now or update your payment method from your account.', 'fluent-cart' ),
                            '<strong>' . esc_html( $formattedRetryDate ) . '</strong>'
                        );
                    } else {
                        printf(
                            /* translators: %1$s is the date of the next automatic charge attempt */
                            esc_html__( 'We will automatically try again on %1$s. To avoid any interruption, you can pay now.', 'fluent-cart' ),
                            '<strong>' . esc_html( $formattedRetryDate ) . '</strong>'
                        );
                    }
                } elseif ($updateMethodUrl) {
                    esc_html_e( 'Please pay now or update your payment method from your account to keep your subscription active.', 'fluent-cart' );
                } else {
                    esc_html_e( 'Please pay now to keep your subscription active.', 'fluent-cart' );
                }
            } elseif ($formattedRetryDate) {
                if ($updateMethodUrl) {
                    printf(
                        /* translators: %1$s is the date of the next automatic charge attempt */
                        esc_html__( 'We will automatically try again on %1$s. You can update your payment method from your account if it has changed.', 'fluent-cart' ),
                        '<strong>' . esc_html( $formattedRetryDate ) . '</strong>'
                    );
                } else {
                    printf(
                        /* translators: %1$s is the date of the next automatic charge attempt */
                        esc_html__( 'We will automatically try again on %1$s.', 'fluent-cart' ),
                        '<strong>' . esc_html( $formattedRetryDate ) . '</strong>'
                    );
                }
            } elseif ($updateMethodUrl) {
                esc_html_e( 'We will automatically try again shortly. You can update your payment method from your account if it has changed.', 'fluent-cart' );
            } else {
                esc_html_e( 'We will automatically try again shortly.', 'fluent-cart' );
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

echo '<hr />';

if ($payNowAllowed) {
    \FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
        'content'     => esc_html__( 'Pay this renewal order now to keep your subscription active — no waiting for the retry.', 'fluent-cart' ),
        'link'        => \FluentCart\App\Services\Payments\PaymentHelper::getCustomPaymentLink($order->uuid),
        'button_text' => __('Pay Now', 'fluent-cart'),
    ]);
}

if ($updateMethodUrl) :
?>
<p>
    <?php
        printf(
            /* translators: %1$s is an HTML link to the customer's subscription page */
            esc_html__( 'Card expired or changed? %1$s to replace the payment method we charge automatically.', 'fluent-cart' ),
            '<a href="' . esc_url( $updateMethodUrl ) . '">' . esc_html__( 'Update your payment method', 'fluent-cart' ) . '</a>'
        );
    ?>
</p>
<?php
endif;
