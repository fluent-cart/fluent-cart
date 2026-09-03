<?php if (!defined('ABSPATH')) exit;

/**
 * Store digest email body.
 *
 * @var array $digest Built by FluentCart\App\Services\Email\StoreDigestService::buildPayload()
 */

$metrics = isset($digest['metrics']) ? $digest['metrics'] : [];
$flux = isset($digest['fluctuations']) ? $digest['fluctuations'] : [];
$topProducts = isset($digest['top_products']) ? $digest['top_products'] : [];
$isEmpty = !empty($digest['is_empty']);

/**
 * Render a period-over-period delta badge.
 * Positive = green ▲, negative = red ▼. Pass $neutral for metrics whose
 * direction is not inherently good/bad (e.g. refunds).
 */
$renderDelta = function ($pct, $neutral = false) {
    $pct = (float) $pct;

    if ($neutral || abs($pct) < 0.01) {
        $color = '#94a3b8';
        $arrow = '';
    } elseif ($pct > 0) {
        $color = '#16a34a';
        $arrow = '▲ ';
    } else {
        $color = '#dc2626';
        $arrow = '▼ ';
    }

    $sign = $pct > 0 ? '+' : '';

    return '<span style="color:' . $color . ';font-size:12px;font-weight:600;white-space:nowrap;">'
        . $arrow . $sign . number_format($pct, 1) . '%</span>';
};

/**
 * Render one KPI card cell.
 */
$kpiCell = function ($label, $value, $deltaHtml = '') {
    $out = '<td width="50%" style="padding:8px;" valign="top">';
    $out .= '<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#f8f9fa;border-radius:8px;">';
    $out .= '<tr><td style="padding:16px 18px;">';
    $out .= '<p style="margin:0 0 6px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;">' . esc_html($label) . '</p>';
    $out .= '<p style="margin:0;font-size:22px;font-weight:700;color:#2F3448;line-height:1.2;">' . $value . '</p>';
    if ($deltaHtml !== '') {
        $out .= '<p style="margin:6px 0 0;">' . $deltaHtml . '</p>';
    }
    $out .= '</td></tr></table></td>';
    return $out;
};
?>

<h1 style="font-size:24px;font-weight:700;color:#111827;margin:0 0 4px;">
    <?php echo esc_html(isset($digest['store_name']) ? $digest['store_name'] : ''); ?>
</h1>
<p style="margin:0 0 12px;font-size:14px;color:#94a3b8;">
    <span style="display:inline-block;background-color:#eaf4ff;color:#017EF3;font-size:12px;font-weight:600;padding:2px 10px;border-radius:12px;">
        <?php echo esc_html(isset($digest['frequency_label']) ? $digest['frequency_label'] : ''); ?>
    </span>
    &nbsp;<?php echo esc_html(isset($digest['period_label']) ? $digest['period_label'] : ''); ?>
</p>
<?php if (!empty($digest['intro'])): ?>
    <p style="margin:0 0 24px;font-size:15px;line-height:1.5;color:#2F3448;">
        <?php echo esc_html($digest['intro']); ?>
    </p>
<?php endif; ?>

<?php if ($isEmpty): ?>
    <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
           style="background-color:#f8f9fa;border-radius:8px;margin-bottom:8px;">
        <tr>
            <td style="padding:28px;text-align:center;">
                <p style="margin:0;font-size:16px;font-weight:600;color:#2F3448;">
                    <?php echo esc_html__('A quiet period — no orders to report.', 'fluent-cart'); ?>
                </p>
                <p style="margin:8px 0 0;font-size:14px;color:#94a3b8;">
                    <?php echo esc_html__('There were no paid orders during this period.', 'fluent-cart'); ?>
                </p>
            </td>
        </tr>
    </table>
<?php else: ?>

    <!-- Headline KPIs -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 -8px 16px;">
        <tr>
            <?php
            echo $kpiCell(__('Gross Sales', 'fluent-cart'), $metrics['gross_sale'], $renderDelta($flux['gross_sale'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $kpiCell(__('Net Revenue', 'fluent-cart'), $metrics['net_revenue'], $renderDelta($flux['net_revenue'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </tr>
        <tr>
            <?php
            echo $kpiCell(__('Orders', 'fluent-cart'), number_format_i18n($metrics['order_count']), $renderDelta($flux['order_count'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $kpiCell(__('Items Sold', 'fluent-cart'), number_format_i18n($metrics['items_sold']), $renderDelta($flux['items_sold'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </tr>
        <tr>
            <?php
            /* translators: %1$s: number of refunded orders */
            $refundSub = sprintf(
                _n('%1$s refund', '%1$s refunds', $metrics['refund_count'], 'fluent-cart'),
                number_format_i18n($metrics['refund_count'])
            );
            echo $kpiCell(__('Refunds', 'fluent-cart'), $metrics['refund_amount'], '<span style="color:#94a3b8;font-size:12px;">' . esc_html($refundSub) . '</span>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
            <td width="50%" style="padding:8px;"></td>
        </tr>
    </table>

    <!-- Order breakdown -->
    <h2 style="font-size:16px;font-weight:700;color:#2F3448;margin:24px 0 10px;">
        <?php echo esc_html__('Order breakdown', 'fluent-cart'); ?>
    </h2>
    <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
           style="border:1px solid #eaeaea;border-radius:8px;border-collapse:separate;overflow:hidden;">
        <tr style="background-color:#f8f9fa;">
            <th align="left" style="padding:10px 16px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;font-weight:600;"><?php echo esc_html__('Type', 'fluent-cart'); ?></th>
            <th align="right" style="padding:10px 16px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;font-weight:600;"><?php echo esc_html__('Orders', 'fluent-cart'); ?></th>
            <th align="right" style="padding:10px 16px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;font-weight:600;"><?php echo esc_html__('Revenue', 'fluent-cart'); ?></th>
        </tr>
        <?php
        $rows = [
            [__('One-time orders', 'fluent-cart'), $metrics['onetime_count'], $metrics['onetime_gross']],
            [__('New subscriptions', 'fluent-cart'), $metrics['subscription_count'], $metrics['subscription_gross']],
            [__('Renewals', 'fluent-cart'), $metrics['renewal_count'], $metrics['renewal_gross']],
        ];
        foreach ($rows as $row):
            ?>
            <tr>
                <td style="padding:12px 16px;font-size:14px;color:#2F3448;border-top:1px solid #eaeaea;"><?php echo esc_html($row[0]); ?></td>
                <td align="right" style="padding:12px 16px;font-size:14px;color:#2F3448;border-top:1px solid #eaeaea;"><?php echo esc_html(number_format_i18n($row[1])); ?></td>
                <td align="right" style="padding:12px 16px;font-size:14px;font-weight:600;color:#2F3448;border-top:1px solid #eaeaea;"><?php echo $row[2]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <!-- Top products -->
    <?php if (!empty($topProducts)): ?>
        <h2 style="font-size:16px;font-weight:700;color:#2F3448;margin:24px 0 10px;">
            <?php echo esc_html__('Top products', 'fluent-cart'); ?>
        </h2>
        <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
               style="border:1px solid #eaeaea;border-radius:8px;border-collapse:separate;overflow:hidden;">
            <tr style="background-color:#f8f9fa;">
                <th align="left" style="padding:10px 16px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;font-weight:600;"><?php echo esc_html__('Product', 'fluent-cart'); ?></th>
                <th align="right" style="padding:10px 16px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;font-weight:600;"><?php echo esc_html__('Units', 'fluent-cart'); ?></th>
                <th align="right" style="padding:10px 16px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;font-weight:600;"><?php echo esc_html__('Revenue', 'fluent-cart'); ?></th>
            </tr>
            <?php foreach ($topProducts as $product): ?>
                <tr>
                    <td style="padding:12px 16px;font-size:14px;color:#2F3448;border-top:1px solid #eaeaea;"><?php echo esc_html($product['name']); ?></td>
                    <td align="right" style="padding:12px 16px;font-size:14px;color:#2F3448;border-top:1px solid #eaeaea;"><?php echo esc_html(number_format_i18n($product['qty'])); ?></td>
                    <td align="right" style="padding:12px 16px;font-size:14px;font-weight:600;color:#2F3448;border-top:1px solid #eaeaea;"><?php echo $product['revenue']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

<?php endif; ?>

<!-- CTA -->
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin:28px 0 8px;">
    <tr>
        <td align="center">
            <a href="<?php echo esc_url(isset($digest['reports_url']) ? $digest['reports_url'] : ''); ?>"
               style="display:inline-block;background-color:#017EF3;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 28px;border-radius:8px;">
                <?php echo esc_html__('View full reports', 'fluent-cart'); ?>
            </a>
        </td>
    </tr>
</table>

<?php
$proPromo = (isset($digest['pro_promo']) && is_array($digest['pro_promo'])) ? $digest['pro_promo'] : [];
if (empty($digest['is_pro']) && !empty($proPromo['body'])): ?>
    <!-- FluentCart Pro (free installs only) — contextual angle picked in StoreDigestService::proPromo() -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
           style="margin:24px 0 8px;background-color:#f8f9fa;border-radius:8px;">
        <tr>
            <td style="padding:20px 24px;">
                <p style="margin:0 0 10px;font-size:14px;line-height:1.6;color:#2F3448;">
                    <?php echo $proPromo['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe HTML built in StoreDigestService::proPromoCopy() ?>
                </p>
                <a href="<?php echo esc_url(isset($proPromo['url']) ? $proPromo['url'] : ''); ?>"
                   style="font-size:14px;font-weight:600;color:#017EF3;text-decoration:none;">
                    <?php echo esc_html(isset($proPromo['cta']) ? $proPromo['cta'] : ''); ?>
                </a>
            </td>
        </tr>
    </table>
<?php endif; ?>

<!-- Manage / opt-out -->
<?php if (!empty($digest['settings_url'])): ?>
    <p style="margin:20px 0 0;text-align:center;font-size:12px;line-height:1.6;color:#94a3b8;">
        <?php
        $manageLink = '<a href="' . esc_url($digest['settings_url']) . '" style="color:#94a3b8;text-decoration:underline;">'
            . esc_html__('Manage digest settings', 'fluent-cart') . '</a>';
        /* translators: %1$s: "Manage digest settings" link */
        echo sprintf(
            esc_html__('This digest is enabled for your store. %1$s', 'fluent-cart'),
            $manageLink
        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
    </p>
<?php endif; ?>
