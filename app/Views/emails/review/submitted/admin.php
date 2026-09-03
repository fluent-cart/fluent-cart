<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var \FluentCart\App\Models\ProductReview $review
 * @var \FluentCart\App\Models\Product|null  $product
 */
$productTitle = $product ? $product->post_title : __('Unknown Product', 'fluent-cart');
$reviewerName = $review->reviewer_name ?: __('Anonymous', 'fluent-cart');
$rating = (int) $review->rating;
?>

<div class="space_bottom_30">
    <p><?php echo esc_html__('Hey there,', 'fluent-cart'); ?></p>
    <p>
        <?php
        printf(
            /* translators: %1$s is the reviewer name, %2$s is the product title */
            esc_html__('A new review has been submitted by %1$s for %2$s.', 'fluent-cart'),
            '<strong>' . esc_html($reviewerName) . '</strong>',
            '<strong>' . esc_html($productTitle) . '</strong>'
        );
        ?>
    </p>
</div>

<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:24px;background-color:#f9fafb;border-radius:8px;padding:20px;">
    <tbody>
    <tr>
        <td style="padding:20px;">
            <?php if ($rating > 0) : ?>
                <p style="margin-bottom:8px;">
                    <strong><?php esc_html_e('Rating:', 'fluent-cart'); ?></strong>
                    <?php
                    for ($i = 1; $i <= 5; $i++) {
                        echo $i <= $rating ? '&#9733;' : '&#9734;';
                    }
                    echo ' (' . esc_html($rating) . '/5)';
                    ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($review->title)) : ?>
                <p style="margin-bottom:8px;">
                    <strong><?php esc_html_e('Title:', 'fluent-cart'); ?></strong>
                    <?php echo esc_html($review->title); ?>
                </p>
            <?php endif; ?>

            <p style="margin-bottom:8px;">
                <strong><?php esc_html_e('Review:', 'fluent-cart'); ?></strong><br/>
                <?php echo esc_html($review->content); ?>
            </p>

            <p style="margin-bottom:8px;">
                <strong><?php esc_html_e('Status:', 'fluent-cart'); ?></strong>
                <?php echo esc_html(ucfirst($review->status)); ?>
            </p>

            <p style="margin-bottom:0;">
                <strong><?php esc_html_e('Reviewer Email:', 'fluent-cart'); ?></strong>
                <?php echo esc_html($review->reviewer_email); ?>
            </p>
        </td>
    </tr>
    </tbody>
</table>

<?php
$adminUrl = admin_url('admin.php?page=fluent-cart#/reviews/' . $review->id . '/view');
\FluentCart\App\App::make('view')->render('emails.parts.call_to_action_box', [
    'content'     => __('To view and manage this review, visit the review details page.', 'fluent-cart'),
    'link'        => $adminUrl,
    'button_text' => __('View Review', 'fluent-cart'),
]);
