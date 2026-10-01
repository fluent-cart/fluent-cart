<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * To the reviewer: the store has replied to their review.
 *
 * @var \FluentCart\App\Models\ProductReview $reply  the store's reply
 * @var \FluentCart\App\Models\ProductReview $review the review it answers — its author is the recipient
 * @var \FluentCart\App\Models\Product|object|null $product
 */
$productTitle = $product && !empty($product->post_title)
    ? (string) $product->post_title
    : __('the product', 'fluent-cart');

// The item the review names, when it does — on a copy, for the reason the
// approval template gives: the helper sets item_label as an attribute, and
// this can render on a model the caller goes on to save().
$reviewWithItemLabel = clone $review;
\FluentCart\App\Services\ProductReviewService::attachItemLabels([$reviewWithItemLabel]);
$itemLabel = trim((string) $reviewWithItemLabel->getAttribute('item_label'));
if ($itemLabel !== '') {
    /* translators: 1: product title, 2: item (variation) label */
    $productTitle = sprintf(__('%1$s (%2$s)', 'fluent-cart'), $productTitle, $itemLabel);
}

$productId = $product && !empty($product->ID) ? (int) $product->ID : (int) $review->post_id;
$productUrl = $productId ? get_permalink($productId) : '';

$storeName = trim((string) (new \FluentCart\Api\StoreSettings())->get('store_name'));
if ($storeName === '') {
    $storeName = (string) get_bloginfo('name');
}

$reviewerName = trim((string) $review->reviewer_name);
$rating = max(0, min(5, (int) $review->rating));
?>

<div class="space_bottom_30">
    <p>
        <?php
        if ($reviewerName !== '') {
            /* translators: 1: the reviewer's name */
            printf(esc_html__('Hello %1$s,', 'fluent-cart'), esc_html($reviewerName));
        } else {
            esc_html_e('Hello,', 'fluent-cart');
        }
        ?>
    </p>
    <p>
        <?php
        printf(
            /* translators: 1: the store name, 2: the product title */
            esc_html__('%1$s has replied to your review of %2$s.', 'fluent-cart'),
            '<strong>' . esc_html($storeName) . '</strong>',
            '<strong>' . esc_html($productTitle) . '</strong>'
        );
        ?>
    </p>
</div>

<?php // What they wrote, so the reply has its context. ?>
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:16px;background-color:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;">
    <tbody>
    <tr>
        <td style="padding:20px 24px;">
            <p style="margin:0 0 8px;font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#6b7280;"><?php esc_html_e('Your review', 'fluent-cart'); ?></p>
            <?php if ($rating > 0) : ?>
                <?php // Filled and hollow glyphs, and the number in words, so the rating reads without colour. ?>
                <p style="margin:0 0 10px;font-size:18px;line-height:1;letter-spacing:2px;">
                    <span style="color:<?php echo esc_attr(\FluentCart\App\Services\Renderer\ProductReviewRenderer::starColors()['filled']); ?>;"><?php echo str_repeat('&#9733;', $rating); ?></span><span style="color:<?php echo esc_attr(\FluentCart\App\Services\Renderer\ProductReviewRenderer::starColors()['empty']); ?>;"><?php echo str_repeat('&#9734;', 5 - $rating); ?></span>
                    <span style="font-size:13px;letter-spacing:0;color:#6b7280;vertical-align:middle;">
                        <?php
                        printf(
                            /* translators: 1: the rating given, out of five */
                            esc_html__('%1$d out of 5', 'fluent-cart'),
                            $rating
                        );
                        ?>
                    </span>
                </p>
            <?php endif; ?>
            <?php if (!empty($review->title)) : ?>
                <p style="margin:0 0 8px;font-size:16px;font-weight:700;line-height:1.4;color:#111827;"><?php echo esc_html($review->title); ?></p>
            <?php endif; ?>
            <p style="margin:0;font-size:15px;line-height:1.7;color:#374151;"><?php echo nl2br(esc_html($review->content)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by esc_html() before nl2br() ?></p>
        </td>
    </tr>
    </tbody>
</table>

<?php // The reply. ?>
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:28px;background-color:#ffffff;border:1px solid #e5e7eb;border-left:4px solid #0f172a;border-radius:8px;border-collapse:separate;">
    <tbody>
    <tr>
        <td style="padding:20px 24px;">
            <p style="margin:0 0 8px;font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#6b7280;">
                <?php
                printf(
                    /* translators: 1: the store name */
                    esc_html__('Reply from %1$s', 'fluent-cart'),
                    esc_html($storeName)
                );
                ?>
            </p>
            <p style="margin:0;font-size:15px;line-height:1.7;color:#111827;"><?php echo nl2br(esc_html($reply->content)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by esc_html() before nl2br() ?></p>
        </td>
    </tr>
    </tbody>
</table>

<?php if ($productUrl) : ?>
    <table border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:28px;">
        <tbody>
        <tr>
            <td>
                <a href="<?php echo esc_url($productUrl); ?>" target="_blank" style="display:inline-block;background-color:#0f172a;color:#ffffff;padding:14px 28px;border-radius:8px;font-size:16px;font-weight:600;text-decoration:none;">
                    <?php esc_html_e('See the reply', 'fluent-cart'); ?> &rarr;
                </a>
            </td>
        </tr>
        </tbody>
    </table>
<?php endif; ?>

<div class="space_bottom_30">
    <p style="margin:0;font-size:15px;line-height:1.6;color:#9ca3af;">
        <?php esc_html_e('Thank you again for taking the time to share your experience.', 'fluent-cart'); ?>
    </p>
</div>
