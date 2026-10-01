<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * To the store: a customer has submitted a review.
 *
 * @var \FluentCart\App\Models\ProductReview $review
 * @var \FluentCart\App\Models\Product|object|null $product
 */

// Handed no review — a preview built without one, or a caller's mistake —
// there is nothing to say, and a fatal inside a template takes the whole
// settings screen down with it.
if (!$review instanceof \FluentCart\App\Models\ProductReview) {
    return;
}

$productTitle = $product && !empty($product->post_title)
    ? (string) $product->post_title
    : __('Unknown Product', 'fluent-cart');

// The item the review names, when it does — resolved by the same helper every
// other surface uses, so the email can never disagree with the admin or the
// storefront about what a review is about. On a copy: the helper sets
// item_label as an attribute, and this renders inside the submit request on
// the very model the controller still holds — a label left on it would be
// written as a column the table does not have if anything saved it.
$reviewWithItemLabel = clone $review;
\FluentCart\App\Services\ProductReviewService::attachItemLabels([$reviewWithItemLabel]);
$itemLabel = trim((string) $reviewWithItemLabel->getAttribute('item_label'));
if ($itemLabel !== '') {
    /* translators: 1: product title, 2: item (variation) label */
    $productTitle = sprintf(__('%1$s (%2$s)', 'fluent-cart'), $productTitle, $itemLabel);
}

$reviewerName = trim((string) $review->reviewer_name) ?: __('Anonymous', 'fluent-cart');
$reviewerEmail = trim((string) $review->reviewer_email);
$rating = max(0, min(5, (int) $review->rating));
$isVerified = !empty($review->is_verified);
$photoCount = (int) $review->media_count;
$isPending = $review->status === \FluentCart\App\Helpers\Status::REVIEW_PENDING;

$adminUrl = admin_url('admin.php?page=fluent-cart#/reviews/' . (int) $review->id . '/view');
?>

<div class="space_bottom_30">
    <p><?php esc_html_e('Hello,', 'fluent-cart'); ?></p>
    <p>
        <?php
        printf(
            /* translators: 1: the reviewer's name, 2: the product title */
            esc_html__('%1$s has submitted a new review of %2$s.', 'fluent-cart'),
            '<strong>' . esc_html($reviewerName) . '</strong>',
            '<strong>' . esc_html($productTitle) . '</strong>'
        );
        if ($isPending) {
            echo ' ' . esc_html__('It is waiting for your approval before it appears on the product page.', 'fluent-cart');
        } else {
            echo ' ' . esc_html__('It is already live on the product page.', 'fluent-cart');
        }
        ?>
    </p>
</div>

<?php // The review — the same card the reviewer's own emails show. ?>
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:28px;background-color:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;">
    <tbody>
    <tr>
        <td style="padding:24px 24px 20px;">
            <?php if ($rating > 0) : ?>
                <?php // Filled and hollow glyphs, and the number in words, so the rating reads without colour. ?>
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

            <p style="margin:0;font-size:15px;line-height:1.7;color:#374151;"><?php echo nl2br(esc_html($review->content)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by esc_html() before nl2br() ?></p>
        </td>
    </tr>
    <tr>
        <td style="padding:12px 24px;border-top:1px solid #e5e7eb;background-color:#f1f5f9;border-radius:0 0 8px 8px;font-size:13px;line-height:1.6;color:#6b7280;">
            <?php
            // What a moderator needs at a glance: who, whether they bought
            // it, whether there are photos to look at, and where it stands.
            $facts = [];

            $facts[] = $reviewerEmail !== ''
                ? esc_html($reviewerName) . ' &middot; ' . esc_html($reviewerEmail)
                : esc_html($reviewerName);

            if ($isVerified) {
                $facts[] = esc_html__('Verified purchase', 'fluent-cart');
            }

            if ($photoCount > 0) {
                $facts[] = esc_html(sprintf(
                    /* translators: 1: number of photos */
                    _n('%1$d photo', '%1$d photos', $photoCount, 'fluent-cart'),
                    $photoCount
                ));
            }

            $facts[] = $isPending
                ? '<span style="color:#b45309;font-weight:600;">' . esc_html__('Pending approval', 'fluent-cart') . '</span>'
                : '<span style="color:#047857;font-weight:600;">' . esc_html__('Approved', 'fluent-cart') . '</span>';

            echo implode('<br/>', $facts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each fact is escaped above
            ?>
        </td>
    </tr>
    </tbody>
</table>

<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:28px;">
    <tbody>
    <tr>
        <td>
            <a href="<?php echo esc_url($adminUrl); ?>" target="_blank" style="display:inline-block;background-color:#0f172a;color:#ffffff;padding:14px 28px;border-radius:8px;font-size:16px;font-weight:600;text-decoration:none;">
                <?php $isPending ? esc_html_e('Moderate review', 'fluent-cart') : esc_html_e('View review', 'fluent-cart'); ?> &rarr;
            </a>
        </td>
    </tr>
    </tbody>
</table>

<div class="space_bottom_30">
    <p style="margin:0;font-size:15px;line-height:1.6;color:#9ca3af;">
        <?php esc_html_e('You can approve, reply to, or remove this review from the reviews screen.', 'fluent-cart'); ?>
    </p>
</div>
