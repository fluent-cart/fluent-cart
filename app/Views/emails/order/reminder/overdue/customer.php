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

if (empty($orderRef)) {
    $orderRef = !empty($order->invoice_no) ? $order->invoice_no : '#' . $order->id;
}
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
    <p>
        <?php
        printf(
            /* translators: %s is order reference */
            esc_html__('This is a friendly reminder that your payment for order %s is still pending.', 'fluent-cart'),
            esc_html($orderRef)
        );
        ?>
    </p>
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
    'content'     => __('Your order has an outstanding balance. Please complete your payment at your earliest convenience.', 'fluent-cart'),
    'link'        => $paymentLink,
    'button_text' => __('Complete Payment', 'fluent-cart'),
]);
