<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var \FluentCart\App\Models\Order $order Parent order
 * @var array $old_payment_method
 * @var array $new_payment_method
 */

$endSide = \FluentCart\App\Services\Email\EmailDirection::end();

$rows = [
    __('Customer', 'fluent-cart')                => $order->customer ? $order->customer->full_name : '',
    __('Subscription', 'fluent-cart')            => $subscription->display_item_name,
    __('New payment method', 'fluent-cart')      => \FluentCart\App\Services\Payments\PaymentHelper::getPaymentMethodLabel((array) $new_payment_method),
    __('Previous payment method', 'fluent-cart') => \FluentCart\App\Services\Payments\PaymentHelper::getPaymentMethodLabel((array) $old_payment_method),
];
?>

<div class="space_bottom_30">
    <p><?php esc_html_e('A customer changed the payment method of a subscription.', 'fluent-cart'); ?></p>
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin:12px 0 0;">
        <tbody>
        <?php foreach ($rows as $label => $value): ?>
            <tr>
                <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php echo esc_html($label); ?></td>
                <td style="font-size:13px;color:#111827;font-weight:600;padding:0 0 6px;text-align:<?php echo esc_attr($endSide); ?>;"><?php echo esc_html($value); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => esc_html__('You can review subscription details from the admin dashboard.', 'fluent-cart'),
    'link'        => $subscription->getViewUrl('admin'),
    'button_text' => __('View Subscription', 'fluent-cart'),
]);
?>
