<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * To the reviewer: their review is approved and on the product page.
 *
 * @var \FluentCart\App\Models\ProductReview $review
 * @var \FluentCart\App\Models\Product|object|null $product
 */
$productTitle = $product && !empty($product->post_title)
    ? (string) $product->post_title
    : __('the product', 'fluent-cart');

// The item the review names, when it does — resolved by the same helper every
// other surface uses, so the email can never disagree with the product page
// about what a review is about. On a copy: the helper sets item_label as an
// attribute, and this template can render inside the request that approved
// the review, on the very model the caller goes on to save() — a label left
// on it would be written as a column the table does not have.
$reviewWithItemLabel = clone $review;
\FluentCart\App\Services\ProductReviewService::attachItemLabels([$reviewWithItemLabel]);
$itemLabel = trim((string) $reviewWithItemLabel->getAttribute('item_label'));
if ($itemLabel !== '') {
    /* translators: 1: product title, 2: item (variation) label */
    $productTitle = sprintf(__('%1$s (%2$s)', 'fluent-cart'), $productTitle, $itemLabel);
}

$productId = $product && !empty($product->ID) ? (int) $product->ID : (int) $review->post_id;
$productUrl = $productId ? get_permalink($productId) : '';

$reviewerName = trim((string) $review->reviewer_name);
$rating = max(0, min(5, (int) $review->rating));
$isVerified = !empty($review->is_verified);
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
            /* translators: 1: the product title */
            esc_html__('Your review of %1$s has been approved and is now visible to shoppers on the product page.', 'fluent-cart'),
            '<strong>' . esc_html($productTitle) . '</strong>'
        );
        ?>
    </p>
</div>

<?php // The review, quoted back — the card the storefront shows, in mail-safe markup. ?>
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:28px;background-color:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;">
    <tbody>
    <tr>
        <td style="padding:24px 24px 20px;">
            <?php if ($rating > 0) : ?>
                <?php // Filled and hollow glyphs, and the number in words: the
                      // rating must read without colour — a screen reader, a
                      // forced-colour display, or colour-blind eyes. ?>
                <p style="margin:0 0 12px;font-size:22px;line-height:1;letter-spacing:2px;">
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
                <p style="margin:0 0 10px;font-size:18px;font-weight:700;line-height:1.4;color:#111827;"><?php echo esc_html($review->title); ?></p>
            <?php endif; ?>

            <?php // Escaped first, then the reviewer's own line breaks put back — a
                  // three-paragraph review must not arrive as one block. ?>
            <p style="margin:0;font-size:15px;line-height:1.7;color:#374151;"><?php echo nl2br(esc_html($review->content)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by esc_html() before nl2br() ?></p>
        </td>
    </tr>
    <tr>
        <td style="padding:12px 24px;border-top:1px solid #e5e7eb;background-color:#f1f5f9;border-radius:0 0 8px 8px;font-size:13px;color:#6b7280;">
            <?php
            if ($isVerified) {
                printf(
                    /* translators: 1: the product title */
                    esc_html__('Verified purchase · %1$s', 'fluent-cart'),
                    esc_html($productTitle)
                );
            } else {
                echo esc_html($productTitle);
            }
            ?>
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
                    <?php esc_html_e('See your review', 'fluent-cart'); ?> &rarr;
                </a>
            </td>
        </tr>
        </tbody>
    </table>
<?php endif; ?>

<div class="space_bottom_30">
    <p style="margin:0;font-size:15px;line-height:1.6;color:#9ca3af;">
        <?php esc_html_e('Thank you for sharing your experience — reviews like yours help other shoppers decide with confidence.', 'fluent-cart'); ?>
    </p>
</div>
