<?php if (!defined('ABSPATH')) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Order $order
 * @var array $reminder
 */

$reminder = (isset($reminder) && is_array($reminder)) ? $reminder : [];
$dueAt = \FluentCart\Framework\Support\Arr::get($reminder, 'due_at', '');
$dueDate = $dueAt ? \FluentCart\App\Services\DateTime\DateTime::gmtToTimezone($dueAt)->format('M d, Y h:i A') : '';
$dueAmount = \FluentCart\Framework\Support\Arr::get($reminder, 'due_amount', 0);
if (!$dueAmount) {
    $dueAmount = max(((int)$order->total_amount - (int)$order->total_paid), 0);
}
$paymentLink = \FluentCart\Framework\Support\Arr::get($reminder, 'payment_link', \FluentCart\App\Services\Payments\PaymentHelper::getCustomPaymentLink($order->uuid));
$orderRef = \FluentCart\Framework\Support\Arr::get($reminder, 'order_ref', '');
$stage    = \FluentCart\Framework\Support\Arr::get($reminder, 'stage', '');

if (empty($orderRef)) {
    $orderRef = !empty($order->invoice_no) ? $order->invoice_no : '#' . $order->id;
}

$overdueDays = 0;
if (preg_match('/^overdue_(\d+)$/', $stage, $m)) {
    $overdueDays = (int)$m[1];
}

if ($overdueDays > 0) {
    $bodyText = sprintf(
        /* translators: %1$s: invoice reference, %2$d: number of days overdue */
        esc_html__('Your subscription invoice %1$s is now %2$d days overdue. If payment is not received soon, your subscription will be suspended.', 'fluent-cart'),
        '<b>' . esc_html($orderRef) . '</b>',
        $overdueDays
    );
} else {
    $bodyText = sprintf(
        /* translators: %1$s: invoice reference */
        esc_html__('Your subscription invoice %1$s is overdue. If payment is not received soon, your subscription will be suspended.', 'fluent-cart'),
        '<b>' . esc_html($orderRef) . '</b>'
    );
}
$ctaText = esc_html__('Your subscription is at risk. Please pay immediately to avoid suspension.', 'fluent-cart');
?>

<div class="space_bottom_30">
    <p>
        <?php
        printf(
            /* translators: %s is customer name */
            esc_html__('Hello %s,', 'fluent-cart'),
            esc_html($order->customer->full_name)
        );
        ?>
    </p>
    <p><?php echo wp_kses($bodyText, ['b' => []]); ?></p>
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin:12px 0 0;">
        <tbody>
        <tr>
            <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php esc_html_e('Order ID', 'fluent-cart'); ?></td>
            <td style="font-size:13px;color:#111827;font-weight:600;padding:0 0 6px;text-align:right;">#<?php echo esc_html($order->id); ?></td>
        </tr>
        <tr>
            <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php esc_html_e('Order Reference', 'fluent-cart'); ?></td>
            <td style="font-size:13px;color:#111827;font-weight:600;padding:0 0 6px;text-align:right;"><?php echo esc_html($orderRef); ?></td>
        </tr>
        <tr>
            <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php esc_html_e('Outstanding Amount', 'fluent-cart'); ?></td>
            <td style="font-size:14px;color:#111827;font-weight:700;padding:0 0 6px;text-align:right;"><?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($dueAmount, null, false, true, true)); ?></td>
        </tr>
        <?php if ($dueDate): ?>
            <tr>
                <td style="font-size:13px;color:#6b7280;padding:0;"><?php esc_html_e('Due Date', 'fluent-cart'); ?></td>
                <td style="font-size:13px;color:#111827;font-weight:600;padding:0;text-align:right;"><?php echo esc_html($dueDate); ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => $ctaText,
    'link'        => $paymentLink,
    'button_text' => __('Complete Payment', 'fluent-cart'),
]);
