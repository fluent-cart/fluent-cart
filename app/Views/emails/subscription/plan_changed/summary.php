<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Previous plan / new plan table shared by the customer and admin plan-changed emails.
 *
 * @var \FluentCart\App\Models\Order $order The upgrade order; its timezone dates the next payment
 * @var \FluentCart\App\Models\Subscription|null $subscription New subscription; null when the new plan is one-time
 * @var \FluentCart\App\Models\Subscription|null $old_subscription
 * @var \FluentCart\App\Models\ProductVariation|null $old_variation
 * @var \FluentCart\App\Models\ProductVariation|null $new_variation
 */

$endSide = \FluentCart\App\Services\Email\EmailDirection::end();

$planPrice = function ($sub, $variation) {
    if ($sub && !empty($sub->payment_info)) {
        return wp_kses_post($sub->payment_info);
    }

    return ($variation && $variation->payment_type === 'onetime') ? esc_html__('One-time purchase', 'fluent-cart') : '';
};

$plans = [
    [__('Previous plan', 'fluent-cart'), $old_variation, $old_subscription],
    [__('New plan', 'fluent-cart'), $new_variation, $subscription],
];

$nextPaymentDate = ($subscription && $subscription->next_billing_date)
    ? \FluentCart\App\Services\DateTime\DateFormatter::format($subscription->next_billing_date, false, $order)
    : '';
?>

<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin:12px 0 0;">
    <tbody>
    <?php foreach ($plans as list($label, $variation, $sub)): ?>
        <tr>
            <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php echo esc_html($label); ?></td>
            <td style="font-size:13px;color:#111827;font-weight:600;padding:0 0 6px;text-align:<?php echo esc_attr($endSide); ?>;">
                <?php echo esc_html($variation ? $variation->getDisplayTitle() : ($sub ? $sub->display_item_name : '')); ?>
                <?php $price = $planPrice($sub, $variation); if ($price): ?>
                    <br><span style="font-size:12px;font-weight:400;color:#6b7280;"><?php echo $price; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($nextPaymentDate): ?>
        <tr>
            <td style="font-size:13px;color:#6b7280;padding:0;"><?php esc_html_e('Next payment', 'fluent-cart'); ?></td>
            <td style="font-size:13px;color:#111827;font-weight:600;padding:0;text-align:<?php echo esc_attr($endSide); ?>;"><?php echo esc_html($nextPaymentDate); ?></td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>
