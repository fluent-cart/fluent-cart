<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var \FluentCart\App\Models\Order $order Parent order
 * @var array $old_payment_method
 * @var array $new_payment_method
 */

$newLabel = \FluentCart\App\Services\Payments\PaymentHelper::getPaymentMethodLabel((array) $new_payment_method);
$oldLabel = \FluentCart\App\Services\Payments\PaymentHelper::getPaymentMethodLabel((array) $old_payment_method);

$manageUrl = (new \FluentCart\Api\StoreSettings())->getCustomerProfilePage()
    ? \FluentCart\App\Services\URL::getCustomerDashboardUrl('subscription/' . $subscription->uuid)
    : '';
?>

<div class="space_bottom_30">
    <p class="fct-email-greeting">
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
            printf(
                /* translators: %1$s is the subscription item name, %2$s is the previous payment method, %3$s is the new payment method */
                esc_html__( 'The payment method for %1$s has been updated from %2$s to %3$s. Future payments for this subscription will use %3$s.', 'fluent-cart' ),
                '<strong>' . esc_html( $subscription->display_item_name ) . '</strong>',
                esc_html( $oldLabel ),
                '<strong>' . esc_html( $newLabel ) . '</strong>'
            );
        ?>
    </p>

    <p>
        <?php esc_html_e( 'If you did not make this change, contact us.', 'fluent-cart' ); ?>
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
