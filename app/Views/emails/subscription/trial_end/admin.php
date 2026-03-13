<?php if (!defined('ABSPATH')) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var \FluentCart\App\Models\Order $order
 * @var array $reminder
 */

$reminder = (isset($reminder) && is_array($reminder)) ? $reminder : [];
$trialEndDate = \FluentCart\Framework\Support\Arr::get($reminder, 'trial_end_date',
    !empty($subscription->trial_ends_at) ? $subscription->trial_ends_at : $subscription->next_billing_date
);
$trialEndLabel = $trialEndDate ? \FluentCart\App\Services\DateTime\DateTime::gmtToTimezone($trialEndDate)->format('M d, Y h:i A') : '';
$stage = \FluentCart\Framework\Support\Arr::get($reminder, 'stage', '');
$daysBefore = preg_replace('/[^0-9]/', '', $stage);
$daysText = !empty($daysBefore) ? sprintf(__('%d days', 'fluent-cart'), $daysBefore) : '';
$renewalAmount = $subscription->recurring_total ?? 0;
$billingInterval = $subscription->billing_interval ?? '';
?>

<div class="space_bottom_30">
    <p><?php esc_html_e('A trial end reminder was sent to a customer.', 'fluent-cart'); ?></p>
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin:12px 0 0;">
        <tbody>
        <tr>
            <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php esc_html_e('Customer', 'fluent-cart'); ?></td>
            <td style="font-size:13px;color:#111827;font-weight:600;padding:0 0 6px;text-align:right;"><?php echo esc_html($subscription->customer->full_name); ?></td>
        </tr>
        <tr>
            <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php esc_html_e('Subscription', 'fluent-cart'); ?></td>
            <td style="font-size:13px;color:#111827;font-weight:600;padding:0 0 6px;text-align:right;"><?php echo esc_html($subscription->item_name); ?></td>
        </tr>
        <?php if ($daysText): ?>
            <tr>
                <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php esc_html_e('Trial Ends In', 'fluent-cart'); ?></td>
                <td style="font-size:13px;color:#111827;font-weight:600;padding:0 0 6px;text-align:right;"><?php echo esc_html($daysText); ?></td>
            </tr>
        <?php endif; ?>
        <?php if ($trialEndLabel): ?>
            <tr>
                <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php esc_html_e('Trial End Date', 'fluent-cart'); ?></td>
                <td style="font-size:13px;color:#111827;font-weight:600;padding:0 0 6px;text-align:right;"><?php echo esc_html($trialEndLabel); ?></td>
            </tr>
        <?php endif; ?>
        <?php if ($renewalAmount): ?>
            <tr>
                <td style="font-size:13px;color:#6b7280;padding:0;"><?php esc_html_e('Amount After Trial', 'fluent-cart'); ?></td>
                <td style="font-size:14px;color:#111827;font-weight:700;padding:0;text-align:right;">
                    <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($renewalAmount, null, false, true, true)); ?>
                    <?php if ($billingInterval): ?>
                        <span style="font-size:12px;font-weight:400;color:#6b7280;">(<?php echo esc_html($billingInterval); ?>)</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('You can review subscription details and take action if needed from the admin dashboard.', 'fluent-cart'),
    'link'        => $subscription->getViewUrl('admin'),
    'button_text' => __('View Subscription', 'fluent-cart'),
]);
