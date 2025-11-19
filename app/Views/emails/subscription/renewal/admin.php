<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var \FluentCart\App\Models\Order $order
 */

$transaction = $subscription->getLatestTransaction();
$celebration = \FluentCart\App\Services\TemplateService::getCelebration('renewal');
?>

<?php
if (!empty($celebration)) {
    \FluentCart\App\App::make('view')->render('emails.parts.celebration', [
        'text' => $celebration
    ]);
}
?>

<div class="space_bottom_30">
    <p><?php echo esc_html__('Hey There 👋,', 'fluent-cart'); ?></p>
    <p><?php
        printf(
            /* translators: %1$s is the customer name, %2$s is the subscription item name */
            esc_html__('%1$s just renewed their subscription: %2$s.', 'fluent-cart'),
            esc_html($subscription->customer->full_name),
            '<b>' . esc_html($subscription->item_name) . '</b>'
        );
        ?></p>
</div>

<div class="space_bottom_30">
    <p><b><?php esc_html_e('Subscription Renewal Summary:', 'fluent-cart'); ?></b></p>
    <p><?php esc_html_e('Renewal Date:', 'fluent-cart'); ?> <b><?php echo esc_html($transaction->created_at->format('d M Y, H:i')); ?></b></p>
    <p><?php esc_html_e('Renewal Amount 💰:', 'fluent-cart'); ?> <b><?php echo esc_html(\FluentCart\App\Helpers\Helper::toDecimal($transaction->total)); ?></b></p>
    <p><?php esc_html_e('Payment Method:', 'fluent-cart'); ?> <b><?php echo esc_html($transaction->getPaymentMethodText()); ?></b></p>
    <p><?php esc_html_e('Vendor Transaction ID:', 'fluent-cart'); ?> <b><?php echo esc_html($transaction->vendor_charge_id); ?></b></p>
</div>


<?php
\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => esc_html__('Want to see more details about this renewal? You can view the order details page for more information.', 'fluent-cart'),
    'link'        => $transaction->order->getViewUrl('admin'),
    'button_text' => esc_html__('View Details', 'fluent-cart'),
]);
?>
