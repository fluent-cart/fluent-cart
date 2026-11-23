<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order The upgrade order
 * @var \FluentCart\App\Models\Subscription|null $subscription New subscription; null when the new plan is one-time
 * @var \FluentCart\App\Models\Subscription|null $old_subscription
 * @var \FluentCart\App\Models\ProductVariation|null $old_variation
 * @var \FluentCart\App\Models\ProductVariation|null $new_variation
 */
?>

<div class="space_bottom_30">
    <p>
        <?php
            printf(
                /* translators: %1$s is the customer's full name */
                esc_html__( '%1$s changed their plan.', 'fluent-cart' ),
                '<strong>' . esc_html( $order->customer ? $order->customer->full_name : '' ) . '</strong>'
            );
        ?>
    </p>

    <?php \FluentCart\App\App::make('view')->render('emails.subscription.plan_changed.summary', compact('order', 'subscription', 'old_subscription', 'old_variation', 'new_variation')); ?>
</div>

<?php
\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => esc_html__('You can review the details from the admin dashboard.', 'fluent-cart'),
    'link'        => $subscription ? $subscription->getViewUrl('admin') : $order->getViewUrl('admin'),
    'button_text' => $subscription ? __('View Subscription', 'fluent-cart') : __('View Order', 'fluent-cart'),
]);
?>
