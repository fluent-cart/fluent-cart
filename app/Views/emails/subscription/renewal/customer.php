<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var \FluentCart\App\Models\Order $order
 */

$transaction = $subscription->getLatestTransaction();
?>

<div class="space_bottom_30">
    <p>
        <?php
            printf(
                /* translators: %s is the customer name */
                esc_html__( 'Hello %s,', 'fluent-cart' ),
                esc_html($subscription->customer->full_name)
            );
        ?>
    </p>

    <p>
        <?php
            printf(
                    /* translators: %s is the subscription item name */
                    esc_html__( 'Your subscription has been successfully renewed, ensuring uninterrupted access to %s.', 'fluent-cart' ),
                    esc_html( $subscription->display_item_name )
            );
        ?>
    </p>
</div>

<?php
\FluentCart\App\App::make('view')->render('emails.parts.subscription_item', [
    'transaction'  => $transaction,
    'order'        => $transaction->order,
    'subscription' => $subscription,
    'heading'      => __('Summary', 'fluent-cart'),
]);

\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('To manage your subscription, download receipts, please visit the details page.', 'fluent-cart'),
    'link'        => $subscription->getViewUrl('customer'),
    'button_text' => __('View Details', 'fluent-cart'),
]);
?>
