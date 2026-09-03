<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var string $error Gateway failure reason
 */

$paymentMethodText = $subscription->getPaymentMethodText();
// getCustomerDashboardUrl() returns a broken relative path ("/subscription/{uuid}")
// when no customer profile page is configured — only render the CTA when the
// dashboard base actually exists.
$updateMethodUrl = (new \FluentCart\Api\StoreSettings())->getCustomerProfilePage()
    ? \FluentCart\App\Services\URL::getCustomerDashboardUrl('subscription/' . $subscription->uuid)
    : '';
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
            if ($updateMethodUrl) {
                esc_html_e( 'We will automatically retry the charge. You can update your payment method from your account if it has changed.', 'fluent-cart' );
            } else {
                esc_html_e( 'We will automatically retry the charge.', 'fluent-cart' );
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
