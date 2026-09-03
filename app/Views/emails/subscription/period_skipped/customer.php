<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Billing period skipped notice — sent when an admin skips the customer's next
 * billing period. Neutral by design: the internal reason and the admin who
 * skipped are audit-only and are never included here.
 *
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var \FluentCart\App\Models\Order $order Parent order
 */

$manageUrl = (new \FluentCart\Api\StoreSettings())->getCustomerProfilePage()
    ? \FluentCart\App\Services\URL::getCustomerDashboardUrl('subscription/' . $subscription->uuid)
    : '';

$formattedResumeDate = $subscription->next_billing_date
    ? \FluentCart\App\Services\DateTime\DateTime::gmtToTimezone($subscription->next_billing_date)->format(get_option('date_format'))
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
            if ($formattedResumeDate) {
                printf(
                    /* translators: %1$s is the subscription item name, %2$s is the date billing resumes */
                    esc_html__( 'Good news — your upcoming payment for %1$s has been skipped. Your billing resumes on %2$s.', 'fluent-cart' ),
                    '<strong>' . esc_html( $subscription->item_name ) . '</strong>',
                    '<strong>' . esc_html( $formattedResumeDate ) . '</strong>'
                );
            } else {
                printf(
                    /* translators: %1$s is the subscription item name */
                    esc_html__( 'Good news — your upcoming payment for %1$s has been skipped.', 'fluent-cart' ),
                    '<strong>' . esc_html( $subscription->item_name ) . '</strong>'
                );
            }
        ?>
    </p>

    <p>
        <?php esc_html_e( 'No action is needed on your part.', 'fluent-cart' ); ?>
    </p>
</div>

<?php
if ($manageUrl) {
    \FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
        'content'     => esc_html__( 'You can review your subscription any time from your account.', 'fluent-cart' ),
        'link'        => $manageUrl,
        'button_text' => __('Manage Subscription', 'fluent-cart'),
    ]);
}
