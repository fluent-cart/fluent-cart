<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\Subscription $subscription
 * @var \FluentCart\App\Models\Order $order
 * @var string $reason
 */

$renewalAmount = $subscription->recurring_total ?? 0;
$billingInterval = $subscription->billing_interval ?? '';
$reactivateUrl = $subscription->getReactivateUrl();
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
                esc_html__( 'Your subscription for %s has been canceled.', 'fluent-cart' ),
                '<b>' . esc_html( $subscription->item_name ) . '</b>'
            );
        ?>
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin:12px 0 0;">
        <tbody>
        <tr>
            <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php esc_html_e('Subscription', 'fluent-cart'); ?></td>
            <td style="font-size:13px;color:#111827;font-weight:600;padding:0 0 6px;text-align:right;"><?php echo esc_html($subscription->item_name); ?></td>
        </tr>
        <?php if ($renewalAmount): ?>
            <tr>
                <td style="font-size:13px;color:#6b7280;padding:0 0 6px;"><?php esc_html_e('Amount', 'fluent-cart'); ?></td>
                <td style="font-size:13px;color:#111827;font-weight:600;padding:0 0 6px;text-align:right;">
                    <?php echo esc_html(\FluentCart\Api\CurrencySettings::getFormattedPrice($renewalAmount, null, false, true, true)); ?>
                    <?php if ($billingInterval): ?>
                        <span style="font-size:12px;font-weight:400;color:#6b7280;">(<?php echo esc_html($billingInterval); ?>)</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($reason)): ?>
            <tr>
                <td style="font-size:13px;color:#6b7280;padding:0;"><?php esc_html_e('Reason', 'fluent-cart'); ?></td>
                <td style="font-size:13px;color:#111827;font-weight:600;padding:0;text-align:right;"><?php echo esc_html($reason); ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($reactivateUrl): ?>
    <?php
    \FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
        'content'     => __('Changed your mind? You can reactivate your subscription at any time.', 'fluent-cart'),
        'link'        => $reactivateUrl,
        'button_text' => __('Reactivate Subscription', 'fluent-cart'),
    ]);
    ?>
<?php endif; ?>

<p style="font-size:14px;color:#6b7280;text-align:center;">
    <a href="<?php echo esc_url($subscription->getViewUrl('customer')); ?>" style="color:#017EF3;text-decoration:underline;">
        <?php esc_html_e('Manage Subscription', 'fluent-cart'); ?>
    </a>
</p>
