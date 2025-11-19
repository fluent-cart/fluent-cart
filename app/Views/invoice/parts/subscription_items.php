<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php if ($subscriptions->count() > 0): ?>

<div style="font-size:16px;font-weight:600;color:rgb(44,62,80);margin: 16px 0 0 0;line-height:24px;">
    <?php echo esc_html__('Subscription Details', 'fluent-cart'); ?>
</div>

<div style="padding: 6px 0 0 10px; display: flex; flex-direction: column;gap:12px;font-size: 14px;color:#4b5563;">
    <table role="presentation"
           style="width: 100%;vertical-align: bottom;border-spacing: 0;padding: 0;margin-bottom: 10px;border:none;">
        <tbody>


        <?php foreach ($subscriptions as $subs): ?>
            <tr>
                <td style="width: 50%;border:none;padding: 1px 0;vertical-align: middle;">
                    <p style="margin: 0;">
                        <?php echo esc_html($subs->item_name); ?>
                    </p>


                </td>

                <td style="width: 50%;text-align:right;vertical-align: top;border:none;padding: 1px 0;">
                    <div>
                        <?php if (!empty($subs->payment_info)) : ?>
                                <span>
                                    <?php echo $subs->payment_info; // @phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </span>
                        <?php endif; ?>

                        <?php if (!empty($subs->next_billing_date)) : ?>
                            <p style="font-size:12px;color:rgb(75,85,99);line-height:20px;margin: 3px 0 0 0;">
                                <?php
                                printf(
                                    /* translators: %s is the auto-renewal date and time */
                                    esc_html__('- Auto renews on %s', 'fluent-cart'),
                                    esc_html(
                                        \FluentCart\App\Services\DateTime\DateTime::anyTimeToGmt($subs->next_billing_date)->format('M d, Y h:i A')
                                    )
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
