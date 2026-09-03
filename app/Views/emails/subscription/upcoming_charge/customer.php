<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Upcoming automatic charge notice — sent when a renewal order is created for a
 * system (auto-charged) subscription, ahead of the due-date charge.
 *
 * @var \FluentCart\App\Models\Order $order The renewal order (payment_scheduled)
 * @var \FluentCart\App\Models\Subscription $subscription
 */

$paymentMethodText = $subscription->getPaymentMethodText();

// getCustomerDashboardUrl() returns a broken relative path ("/subscription/{uuid}")
// when no customer profile page is configured — only render the CTA when the
// dashboard base actually exists.
$manageUrl = (new \FluentCart\Api\StoreSettings())->getCustomerProfilePage()
    ? \FluentCart\App\Services\URL::getCustomerDashboardUrl('subscription/' . $subscription->uuid)
    : '';

// The charge fires on the renewal due date. Always state the DATE — never a
// countdown: daily-interval renewals are created at the due date itself.
$dueDate = $order->getMeta('due_date') ?: $order->created_at;
$formattedChargeDate = $dueDate
    ? \FluentCart\App\Services\DateTime\DateTime::gmtToTimezone($dueDate)->format(get_option('date_format'))
    : '';

$formattedAmount = \FluentCart\Api\CurrencySettings::getFormattedPrice($order->total_amount, null, false, true, true);
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
            if ($formattedChargeDate && $paymentMethodText) {
                printf(
                    /* translators: %1$s is the subscription item name, %2$s is the charge amount, %3$s is the charge date, %4$s is the saved payment method (e.g. visa ***4242) */
                    esc_html__( 'Your subscription %1$s renews soon. We will automatically charge %2$s to your saved payment method (%4$s) on %3$s.', 'fluent-cart' ),
                    '<strong>' . esc_html( $subscription->item_name ) . '</strong>',
                    '<strong>' . esc_html( $formattedAmount ) . '</strong>',
                    '<strong>' . esc_html( $formattedChargeDate ) . '</strong>',
                    esc_html( $paymentMethodText )
                );
            } elseif ($formattedChargeDate) {
                printf(
                    /* translators: %1$s is the subscription item name, %2$s is the charge amount, %3$s is the charge date */
                    esc_html__( 'Your subscription %1$s renews soon. We will automatically charge %2$s to your saved payment method on %3$s.', 'fluent-cart' ),
                    '<strong>' . esc_html( $subscription->item_name ) . '</strong>',
                    '<strong>' . esc_html( $formattedAmount ) . '</strong>',
                    '<strong>' . esc_html( $formattedChargeDate ) . '</strong>'
                );
            } else {
                printf(
                    /* translators: %1$s is the subscription item name, %2$s is the charge amount */
                    esc_html__( 'Your subscription %1$s renews soon. We will automatically charge %2$s to your saved payment method.', 'fluent-cart' ),
                    '<strong>' . esc_html( $subscription->item_name ) . '</strong>',
                    '<strong>' . esc_html( $formattedAmount ) . '</strong>'
                );
            }
        ?>
    </p>

    <p>
        <?php esc_html_e( 'No action is needed — this is a notice so the charge does not surprise you.', 'fluent-cart' ); ?>
    </p>
</div>

<?php

\FluentCart\App\App::make('view')->render('emails.parts.items_table', [
    'order'          => $order,
    'formattedItems' => $order->order_items,
    'heading'        => __('Renewal Summary', 'fluent-cart'),
]);

echo '<hr />';

if ($manageUrl) {
    \FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
        'content'     => esc_html__( 'Need to change the card, pause, or cancel before the charge? Manage your subscription from your account.', 'fluent-cart' ),
        'link'        => $manageUrl,
        'button_text' => __('Manage Subscription', 'fluent-cart'),
    ]);
}
