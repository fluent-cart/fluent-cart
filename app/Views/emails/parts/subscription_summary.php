<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\Framework\Database\Orm\Collection $subscriptions
 * @var \FluentCart\App\Models\Order $order
 */
$endSide = \FluentCart\App\Services\Email\EmailDirection::end();
?>
<?php if ($subscriptions->count() > 0): ?>
<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin:28px 0;border-collapse:separate;">
    <tbody>
    <tr>
        <td style="background-color:rgb(245,243,255);border:1px solid rgb(237,233,254);border-radius:12px;padding:16px 20px;">
            <p style="font-size:11px;font-weight:700;color:rgb(109,40,217);text-transform:uppercase;letter-spacing:0.1em;line-height:16px;margin:0 0 6px;">
                <?php echo esc_html__('Subscription', 'fluent-cart'); ?>
            </p>
            <?php foreach ($subscriptions as $subs): ?>
            <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="border:none;">
                <tbody>
                <tr>
                    <td style="vertical-align:top;padding:4px 0;">
                        <p style="font-size:15px;font-weight:700;color:rgb(17,24,39);line-height:22px;margin:0;">
                            <?php echo esc_html($subs->display_item_name); ?>
                        </p>
                    </td>
                    <?php if (!empty($subs->payment_info)) : ?>
                    <td style="vertical-align:top;padding:4px 0;text-align:<?php echo esc_attr($endSide); ?>;">
                        <p style="font-size:14px;font-weight:600;color:rgb(17,24,39);line-height:22px;margin:0;">
                            <?php echo wp_kses_post($subs->payment_info); ?>
                        </p>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php if (!empty($subs->next_billing_date)) : ?>
                <tr>
                    <td colspan="2" style="padding:2px 0 4px;">
                        <p style="font-size:13px;color:rgb(75,85,99);line-height:20px;margin:0;">
                            <?php echo esc_html__('Next Billing Date', 'fluent-cart'); ?>:
                            <strong style="color:rgb(17,24,39);"><?php echo esc_html(\FluentCart\App\Services\DateTime\DateFormatter::format($subs->next_billing_date, true, $order)); ?></strong>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php endforeach; ?>
        </td>
    </tr>
    </tbody>
</table>
<?php endif; ?>
