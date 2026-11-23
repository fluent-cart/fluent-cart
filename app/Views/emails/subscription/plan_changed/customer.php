<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order The upgrade order
 * @var \FluentCart\App\Models\Subscription|null $subscription New subscription; null when the new plan is one-time
 * @var \FluentCart\App\Models\Subscription|null $old_subscription
 * @var \FluentCart\App\Models\ProductVariation|null $old_variation
 * @var \FluentCart\App\Models\ProductVariation|null $new_variation
 */

$manageUrl = '';
if ((new \FluentCart\Api\StoreSettings())->getCustomerProfilePage()) {
    $manageUrl = $subscription
        ? \FluentCart\App\Services\URL::getCustomerDashboardUrl('subscription/' . $subscription->uuid)
        : \FluentCart\App\Services\URL::getCustomerDashboardUrl('order/' . $order->uuid);
}
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

    <p><?php esc_html_e( 'Your plan has been changed.', 'fluent-cart' ); ?></p>

    <?php \FluentCart\App\App::make('view')->render('emails.subscription.plan_changed.summary', compact('order', 'subscription', 'old_subscription', 'old_variation', 'new_variation')); ?>
</div>

<?php
if ($manageUrl) {
    \FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
        'content'     => esc_html__( 'You can review your plan any time from your account.', 'fluent-cart' ),
        'link'        => $manageUrl,
        'button_text' => $subscription ? __('Manage Subscription', 'fluent-cart') : __('View Order', 'fluent-cart'),
    ]);
}
