<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\Framework\Support\Arr;

class ProductReviewRenderer
{
    protected $postId;
    protected $settings;
    protected $renderOptions;

    public function __construct($postId, $options = [])
    {
        $this->postId = $postId;
        $this->settings = ProductReviewService::getReviewSettings();
        $showVerifiedBadge = !isset($this->settings['show_verified_badge']) || $this->settings['show_verified_badge'] === 'yes';
        $this->renderOptions = apply_filters('fluent_cart/review/renderer_options', wp_parse_args($options, [
            'showSummary'       => true,
            'showSortControls'  => true,
            'showVerifiedBadge' => $showVerifiedBadge,
            'showReviewDate'    => true,
            'showReviewerName'  => true,
            'starColor'         => '#f59e0b',
            'defaultSortBy'     => 'created_at',
            'defaultSortOrder'  => 'DESC',
            'perPage'           => 0,
            'showViewReply'     => true,
        ]), $this->postId);
    }

    public function render()
    {
        if (!ProductReviewService::isReviewEnabledForProduct($this->postId)) {
            return;
        }

        $summary = ProductReviewService::getProductRatingSummary($this->postId);
        $restInfo = Helper::getRestInfo();

        $perPageOption = (int) Arr::get($this->renderOptions, 'perPage', 0);
        $perPage = $perPageOption > 0 ? $perPageOption : $this->settings['reviews_per_page'];
        $starColor = sanitize_hex_color(Arr::get($this->renderOptions, 'starColor')) ?: '#f59e0b';
        $defaultSort = Arr::get($this->renderOptions, 'defaultSortBy', 'created_at') . '-' . Arr::get($this->renderOptions, 'defaultSortOrder', 'DESC');

        $showSummary = (bool) Arr::get($this->renderOptions, 'showSummary', true);
        $showSortControls = (bool) Arr::get($this->renderOptions, 'showSortControls', true);
        $showVerifiedBadge = (bool) Arr::get($this->renderOptions, 'showVerifiedBadge', true);
        $showReviewDate = (bool) Arr::get($this->renderOptions, 'showReviewDate', true);
        $showReviewerName = (bool) Arr::get($this->renderOptions, 'showReviewerName', true);
        $showViewReply = (bool) Arr::get($this->renderOptions, 'showViewReply', true);
        $apiAvailable = class_exists('FluentCart\App\Http\Controllers\FrontendControllers\ProductReviewFrontendController');
        ?>
        <?php
        $extraDataAttrs = apply_filters('fluent_cart/review/container_data_attrs', [], $this->postId, $this->renderOptions);
        $extraAttrHtml = '';
        foreach ($extraDataAttrs as $attrKey => $attrVal) {
            $extraAttrHtml .= ' data-' . esc_attr($attrKey) . '="' . esc_attr($attrVal) . '"';
        }
        ?>
        <div class="fct-product-reviews-section"
             data-fluent-cart-reviews
             data-post-id="<?php echo esc_attr($this->postId); ?>"
             data-product-name="<?php echo esc_attr(get_post_field('post_title', $this->postId, 'raw')); ?>"
             data-rest-url="<?php echo esc_url($restInfo['url']); ?>"
             data-rest-nonce="<?php echo esc_attr($restInfo['nonce']); ?>"
             data-per-page="<?php echo esc_attr($perPage); ?>"
             data-default-sort="<?php echo esc_attr($defaultSort); ?>"
             data-show-verified="<?php echo $showVerifiedBadge ? '1' : '0'; ?>"
             data-show-date="<?php echo $showReviewDate ? '1' : '0'; ?>"
             data-show-reviewer="<?php echo $showReviewerName ? '1' : '0'; ?>"
             data-show-view-reply="<?php echo $showViewReply ? '1' : '0'; ?>"
             data-star-required="<?php echo ((!isset($this->settings['enable_star_rating']) || $this->settings['enable_star_rating'] === 'yes') && isset($this->settings['star_rating_required']) && $this->settings['star_rating_required'] === 'yes') ? '1' : '0'; ?>"
             data-api-available="<?php echo $apiAvailable ? '1' : '0'; ?>"
            <?php if ($starColor !== '#f59e0b') : ?>
                style="--fct-star-color: <?php echo esc_attr($starColor); ?>"
            <?php endif; ?>
            <?php echo $extraAttrHtml; ?>
        >
            <div class="fct-reviews-layout<?php echo $showSummary ? '' : ' fct-reviews-layout--single'; ?>">
                <?php if ($showSummary) : ?>
                    <aside class="fct-reviews-layout-left">
                        <?php $this->renderRatingSummary($summary); ?>
                    </aside>
                <?php endif; ?>

                <div class="fct-reviews-layout-right">
                    <div class="fct-reviews-list-header">
                        <h3 class="fct-reviews-section-title">
                            <?php
                            /* translators: %s - total review count */
                            printf(
                                esc_html__('%s Reviews', 'fluent-cart'),
                                '<span data-reviews-total-count>' . esc_html($summary['total']) . '</span>'
                            );
                            ?>
                        </h3>

                        <?php if ($showSortControls) : ?>
                            <?php $this->renderControls($defaultSort); ?>
                        <?php endif; ?>
                    </div>

                    <div class="fct-reviews-list" data-reviews-list>
                        <div class="fct-reviews-loading" data-reviews-loading>
                            <?php esc_html_e('Loading reviews...', 'fluent-cart'); ?>
                        </div>
                    </div>

                    <div class="fct-reviews-pagination" data-reviews-pagination></div>
                </div>
            </div>

            <?php if ($showSummary) : ?>
                <?php
                // The summary rendered its Write a Review CTA, so this block
                // must also supply the drawer that CTA opens — a standalone
                // reviews block otherwise emits a dead button. The drawer
                // dedupes per product, so the single-product template's
                // later renderForm() call cannot add a second one.
                $this->renderForm();
                ?>
            <?php endif; ?>

            <?php do_action('fluent_cart/review/after_section', $this->postId); ?>
        </div>
        <?php
    }

    public function renderForm()
    {
        if (!ProductReviewService::isReviewEnabledForProduct($this->postId)) {
            return;
        }

        $restInfo = Helper::getRestInfo();
        $starColor = sanitize_hex_color(Arr::get($this->renderOptions, 'starColor')) ?: '#f59e0b';
        $apiAvailable = class_exists('FluentCart\App\Http\Controllers\FrontendControllers\ProductReviewFrontendController');
        $permissionMode = $this->settings['review_permission_mode'] ?? 'verified_buyers';
        $userId = get_current_user_id();
        $needsLogin = ($permissionMode !== 'anyone' && !$userId);

        // Only compute drawer state when the user can actually use it
        $extraFieldsHtml = '';
        $hasPhotosStep = false;
        $starEnabled = !isset($this->settings['enable_star_rating']) || $this->settings['enable_star_rating'] === 'yes';
        $existingReview = null;
        $isEditMode = false;

        if (!$needsLogin) {
            // Check if Pro photo step has content
            ob_start();
            do_action('fluent_cart/review/form_extra_fields', $this->postId, $userId);
            $extraFieldsHtml = trim(ob_get_clean());
            $hasPhotosStep = !empty($extraFieldsHtml);
        }

        // Calculate total steps: Rating (if enabled) + Details + Photos (if Pro)
        $totalSteps = 1; // Details is always present
        if ($starEnabled) {
            $totalSteps++;
        }
        if ($hasPhotosStep) {
            $totalSteps++;
        }

        // Check if logged-in user already has a review for this product
        if ($userId && !$needsLogin) {
            $existingReview = ProductReview::query()
                ->where('comment_post_ID', $this->postId)
                ->where('user_id', $userId)
                ->whereIn('comment_approved', ['1', '0'])
                ->first();
        }
        $isEditMode = !empty($existingReview);
        ?>
        <div class="fct-review-form-section"
             data-fluent-cart-review-form
             data-post-id="<?php echo esc_attr($this->postId); ?>"
             data-rest-url="<?php echo esc_url($restInfo['url']); ?>"
             data-rest-nonce="<?php echo esc_attr($restInfo['nonce']); ?>"
             data-star-enabled="<?php echo $starEnabled ? '1' : '0'; ?>"
             data-star-required="<?php echo ($starEnabled && (!isset($this->settings['star_rating_required']) || $this->settings['star_rating_required'] === 'yes')) ? '1' : '0'; ?>"
             data-api-available="<?php echo $apiAvailable ? '1' : '0'; ?>"
             data-total-steps="<?php echo esc_attr($totalSteps); ?>"
            <?php if ($isEditMode) : ?>
                data-edit-mode="1"
                data-review-id="<?php echo esc_attr($existingReview->id); ?>"
                data-existing-rating="<?php echo esc_attr($existingReview->rating); ?>"
                data-existing-title="<?php echo esc_attr($existingReview->title); ?>"
                data-existing-content="<?php echo esc_attr($existingReview->content); ?>"
            <?php endif; ?>
            <?php if ($starColor !== '#f59e0b') : ?>
                style="--fct-star-color: <?php echo esc_attr($starColor); ?>"
            <?php endif; ?>
        >
            <?php if (!$needsLogin) : ?>
                <?php
                // Multiple sections for one product each carry a drawer;
                // ReviewForm.js elects one primary controller per product,
                // so only the first drawer ever opens — the rest stay inert.
                $this->renderDrawer($userId, $extraFieldsHtml, $hasPhotosStep, $totalSteps);
                ?>
            <?php endif; ?>

            <?php do_action('fluent_cart/review/after_form_section', $this->postId); ?>
        </div>
        <?php
    }

    protected function renderDrawer($userId, $extraFieldsHtml, $hasPhotosStep, $totalSteps)
    {
        $currentUser = $userId ? get_userdata($userId) : null;
        $starEnabled = !isset($this->settings['enable_star_rating']) || $this->settings['enable_star_rating'] === 'yes';
        $starRequired = $starEnabled && (!isset($this->settings['star_rating_required']) || $this->settings['star_rating_required'] === 'yes');
        ?>
        <!-- Review Drawer -->
        <div class="fct-review-drawer-overlay" data-review-drawer style="display:none;">
            <div class="fct-review-drawer" data-review-drawer-panel role="dialog" aria-modal="true" aria-labelledby="fct-review-drawer-title-<?php echo esc_attr($this->postId); ?>" tabindex="-1">
                <div class="fct-review-drawer-header">
                    <h4 class="fct-review-drawer-title" data-review-drawer-title id="fct-review-drawer-title-<?php echo esc_attr($this->postId); ?>"><?php esc_html_e('Write a review', 'fluent-cart'); ?></h4>
                    <button type="button" class="fct-review-drawer-close" data-close-review-drawer aria-label="<?php esc_attr_e('Close', 'fluent-cart'); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false"><path d="M12.8337 1.16663L1.16699 12.8333M1.16699 1.16663L12.8337 12.8333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                </div>

                <!-- Stepper -->
                <?php if ($totalSteps > 1) : ?>
                <div class="fct-review-stepper" data-review-stepper>
                    <?php if ($starEnabled) : ?>
                    <div class="fct-review-step-indicator active" data-step-indicator="1">
                        <span class="fct-step-circle">1</span>
                        <span class="fct-step-label"><?php esc_html_e('Rating', 'fluent-cart'); ?></span>
                    </div>
                    <div class="fct-step-line"></div>
                    <div class="fct-review-step-indicator" data-step-indicator="2">
                        <span class="fct-step-circle">2</span>
                        <span class="fct-step-label"><?php esc_html_e('Details', 'fluent-cart'); ?></span>
                    </div>
                    <?php if ($hasPhotosStep) : ?>
                        <div class="fct-step-line"></div>
                        <div class="fct-review-step-indicator" data-step-indicator="3">
                            <span class="fct-step-circle">3</span>
                            <span class="fct-step-label"><?php esc_html_e('Photos', 'fluent-cart'); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php else : ?>
                    <div class="fct-review-step-indicator active" data-step-indicator="1">
                        <span class="fct-step-circle">1</span>
                        <span class="fct-step-label"><?php esc_html_e('Details', 'fluent-cart'); ?></span>
                    </div>
                    <?php if ($hasPhotosStep) : ?>
                        <div class="fct-step-line"></div>
                        <div class="fct-review-step-indicator" data-step-indicator="2">
                            <span class="fct-step-circle">2</span>
                            <span class="fct-step-label"><?php esc_html_e('Photos', 'fluent-cart'); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="fct-review-drawer-body">
                    <div class="fct-review-form-message" data-review-form-message style="display: none;"></div>

                    <form class="fct-review-form" data-review-form>
                        <?php if ($starEnabled) : ?>
                        <!-- Step 1: Rating -->
                        <div class="fct-review-step" data-review-step="1">
                            <label class="fct-review-step-question">
                                <?php esc_html_e('How would you rate this product?', 'fluent-cart'); ?>
                                <?php if ($starRequired) : ?> <span class="required">*</span><?php endif; ?>
                            </label>
                            <div class="fct-star-selector" data-star-selector role="radiogroup" aria-label="<?php esc_attr_e('Rating', 'fluent-cart'); ?>">
                                <?php for ($i = 1; $i <= 5; $i++) : ?>
                                    <button type="button" class="fct-star-select" data-star-value="<?php echo esc_attr($i); ?>"
                                            role="radio" aria-checked="false"
                                            aria-label="<?php printf(esc_attr__('%d star', 'fluent-cart'), $i); ?>"
                                    >&#9733;</button>
                                <?php endfor; ?>
                            </div>
                            <div class="fct-rating-label" data-rating-label></div>
                            <input type="hidden" name="rating" value="" data-review-rating/>
                        </div>
                        <?php endif; ?>

                        <!-- <?php echo $starEnabled ? 'Step 2' : 'Step 1'; ?>: Details -->
                        <div class="fct-review-step" data-review-step="<?php echo $starEnabled ? '2' : '1'; ?>" <?php echo $starEnabled ? 'style="display:none;"' : ''; ?>>
                            <?php if (!$userId) : ?>
                                <div class="fct-review-form-row">
                                    <div class="fct-review-form-field">
                                        <label for="fct-review-name-<?php echo esc_attr($this->postId); ?>"><?php esc_html_e('Your name', 'fluent-cart'); ?> <span class="required">*</span></label>
                                        <input type="text" id="fct-review-name-<?php echo esc_attr($this->postId); ?>" name="reviewer_name" required placeholder="<?php esc_attr_e('Your name', 'fluent-cart'); ?>"/>
                                    </div>
                                    <div class="fct-review-form-field">
                                        <label for="fct-review-email-<?php echo esc_attr($this->postId); ?>"><?php esc_html_e('Email', 'fluent-cart'); ?> <span class="required">*</span> <span class="fct-label-hint">(<?php esc_html_e('private', 'fluent-cart'); ?>)</span></label>
                                        <input type="email" id="fct-review-email-<?php echo esc_attr($this->postId); ?>" name="reviewer_email" required placeholder="<?php esc_attr_e('your@email.com', 'fluent-cart'); ?>"/>
                                    </div>
                                </div>
                            <?php else : ?>
                                <input type="hidden" name="reviewer_name" value="<?php echo esc_attr($currentUser->display_name); ?>"/>
                                <input type="hidden" name="reviewer_email" value="<?php echo esc_attr($currentUser->user_email); ?>"/>
                            <?php endif; ?>

                            <div class="fct-review-form-field">
                                <label for="fct-review-title-<?php echo esc_attr($this->postId); ?>"><?php esc_html_e('Review title', 'fluent-cart'); ?></label>
                                <input type="text" id="fct-review-title-<?php echo esc_attr($this->postId); ?>" name="title" maxlength="80" placeholder="<?php esc_attr_e('Summarize your experience', 'fluent-cart'); ?>"/>
                                <span class="fct-char-count" data-char-count="fct-review-title-<?php echo esc_attr($this->postId); ?>">0 / 80</span>
                            </div>

                            <div class="fct-review-form-field">
                                <label for="fct-review-content-<?php echo esc_attr($this->postId); ?>"><?php esc_html_e('Your review', 'fluent-cart'); ?> <span class="required">*</span></label>
                                <textarea id="fct-review-content-<?php echo esc_attr($this->postId); ?>" name="content" rows="5" maxlength="1500" placeholder="<?php esc_attr_e('What did you like or dislike? How did you use this product?', 'fluent-cart'); ?>"></textarea>
                                <span class="fct-char-count" data-char-count="fct-review-content-<?php echo esc_attr($this->postId); ?>">0 / 1500</span>
                            </div>
                        </div>

                        <?php if ($hasPhotosStep) : ?>
                            <!-- Photos Step (Pro) -->
                            <div class="fct-review-step" data-review-step="<?php echo $starEnabled ? '3' : '2'; ?>" style="display:none;">
                                <p class="fct-review-step-description">
                                    <?php esc_html_e('Add photos to help other shoppers. Up to 5 images — JPG, PNG, or WEBP.', 'fluent-cart'); ?>
                                </p>
                                <?php echo $extraFieldsHtml; // Already escaped by Pro renderer ?>
                                <p class="fct-review-step-hint">
                                    <?php esc_html_e('No photos? That\'s fine — you can skip this step.', 'fluent-cart'); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="fct-review-drawer-footer">
                    <button type="button" class="fct-review-back-btn" data-review-back style="display:none;">
                        &larr; <?php esc_html_e('Back', 'fluent-cart'); ?>
                    </button>
                    <button type="button" class="fct-review-next-btn" data-review-next>
                        <?php esc_html_e('Next', 'fluent-cart'); ?> &rarr;
                    </button>
                    <button type="button" class="fct-review-submit-btn" data-review-submit style="display:none;">
                        <?php esc_html_e('Submit review', 'fluent-cart'); ?> &#10003;
                    </button>
                    <div class="fct-review-step-info" data-review-step-info>
                        <?php
                        /* translators: 1: current step, 2: total steps */
                        printf(esc_html__('Step %1$s of %2$s', 'fluent-cart'), '1', esc_html($totalSteps));
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * The rating summary card on its own — the standalone Rating Summary
     * block. Server-rendered numbers only, so the wrapper deliberately omits
     * data-fluent-cart-reviews: Reviews.js has no list to drive here and
     * must not claim this section.
     */
    public function renderSummarySection()
    {
        if (!ProductReviewService::isReviewEnabledForProduct($this->postId)) {
            return;
        }

        $summary = ProductReviewService::getProductRatingSummary($this->postId);
        $starColor = sanitize_hex_color(Arr::get($this->renderOptions, 'starColor')) ?: '#f59e0b';
        ?>
        <div class="fct-product-reviews-section fct-reviews-summary-only"
            <?php if ($starColor !== '#f59e0b') : ?>
                style="--fct-star-color: <?php echo esc_attr($starColor); ?>"
            <?php endif; ?>
        >
            <?php // No built-in CTA — the Write a Review block is its own CTA ?>
            <?php $this->renderRatingSummary($summary, false); ?>
        </div>
        <?php
    }

    protected function renderRatingSummary($summary, $withCta = true)
    {
        ?>
        <div class="fct-reviews-summary" data-reviews-summary>
            <div class="fct-reviews-summary-left">
                <div class="fct-reviews-average">
                    <span class="fct-reviews-average-number" data-reviews-average><?php echo esc_html($summary['average']); ?></span>
                    <span class="fct-reviews-average-max">/5</span>
                </div>
                <div class="fct-reviews-summary-head-meta">
                <div class="fct-reviews-stars-display" data-reviews-stars role="img" aria-label="<?php printf(esc_attr__('Rated %s out of 5', 'fluent-cart'), esc_attr($summary['average'])); ?>">
                    <?php $this->renderStars($summary['average']); ?>
                </div>
                <div class="fct-reviews-total" data-reviews-total>
                    <?php
                    /* translators: %s - total review count formatted with commas */
                    printf(esc_html__('Based on %s reviews', 'fluent-cart'), esc_html(number_format_i18n($summary['total'])));
                    ?>
                </div>
                </div>
            </div>


            <div class="fct-reviews-summary-right">
                <?php foreach ([5, 4, 3, 2, 1] as $star) :
                    $count = $summary['breakdown'][$star] ?? 0;
                    $percentage = $summary['total'] > 0 ? round(($count / $summary['total']) * 100) : 0;
                    ?>
                    <div class="fct-reviews-bar-row" data-reviews-bar-row>
                        <div class="fct-reviews-bar-track">
                            <div class="fct-reviews-bar-fill" data-reviews-bar-fill style="width: <?php echo esc_attr($percentage); ?>%"></div>
                        </div>
                        <span class="fct-reviews-bar-label"><?php echo esc_html(number_format_i18n($star, 1)); ?> <span class="fct-bar-star-icon">&#9733;</span></span>
                        <span class="fct-reviews-bar-count" data-reviews-bar-count><?php echo esc_html(number_format_i18n($count)); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($withCta) : ?>
                <?php $this->renderWriteReviewCta(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The "Write a Review" / "Edit your review" / "Log in to Review" trigger
     * that opens the shared review drawer. Renders wherever a caller asks —
     * the rating summary (behind its toggle) and every Write a Review block.
     * ReviewForm.js binds triggers by delegation, so any of them opens the
     * product's single drawer.
     */
    public function renderWriteReviewCta()
    {
        if (!ProductReviewService::isReviewEnabledForProduct($this->postId)) {
            return;
        }

        $permissionMode = $this->settings['review_permission_mode'] ?? 'verified_buyers';
        $userId = get_current_user_id();
        $needsLogin = ($permissionMode !== 'anyone' && !$userId);

        if ($needsLogin) {
            $loginUrl = wp_login_url(get_permalink($this->postId));
            ?>
            <a href="<?php echo esc_url($loginUrl); ?>" class="fct-review-cta-btn fct-reviews-summary-write-btn">
                <?php
                $loginText = trim((string) Arr::get($this->renderOptions, 'ctaLoginText', ''));
                echo $loginText !== '' ? esc_html($loginText) : esc_html__('Log in to Review', 'fluent-cart');
                ?>
            </a>
            <?php
        } else {
            $existingReview = $userId
                ? ProductReview::query()
                    ->where('comment_post_ID', $this->postId)
                    ->where('user_id', $userId)
                    ->whereIn('comment_approved', ['1', '0'])
                    ->first()
                : null;
            $isEditMode = !empty($existingReview);
            ?>
            <button type="button" class="fct-review-cta-btn fct-reviews-summary-write-btn" data-open-review-drawer data-post-id="<?php echo esc_attr($this->postId); ?>">
                <?php if ($isEditMode) : ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>
                    <?php
                    $editText = trim((string) Arr::get($this->renderOptions, 'ctaEditText', ''));
                    echo $editText !== '' ? esc_html($editText) : esc_html__('Edit your review', 'fluent-cart');
                    ?>
                <?php else : ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="currentColor" width="16" height="16" aria-hidden="true" focusable="false"><path d="M19.431 1.648a1.12 1.12 0 0 0-1.53.416l-.001.002l-3.972 6.88l-.48-.209a5.42 5.42 0 0 0-6.752 2.05l-.007.011l-.007.012l-.002-.001l-5.2 8.68l-.005.007v.008h-.002a3.506 3.506 0 0 0 1.279 4.78a3.44 3.44 0 0 0 2.05.464l-.058.102l-.001.001a2.8 2.8 0 0 0-.366 1.162l-.38 3.443v.008c-.063.813.848 1.326 1.511.87l.01-.006l2.752-2.082c.286-.202.535-.46.725-.754v.01a3.5 3.5 0 0 0 6.322 2.07q.19.18.405.34c1.04.768 2.399 1.09 3.783 1.09h9.24a2.24 2.24 0 0 0 2.226-1.99h.024V27.06h.002V18.2a2.82 2.82 0 0 0-1.704-2.585l-10.75-4.666l3.685-6.387l.002-.003a1.12 1.12 0 0 0-.416-1.53l-.003-.001l-2.375-1.378zm-6.508 9.038l-2.656 4.6a2.85 2.85 0 0 0-1.246 1.169l-3.208 5.551a1.52 1.52 0 0 1-1.308.757a1.45 1.45 0 0 1-.742-.2l-.004-.002A1.5 1.5 0 0 1 3.2 20.5l5.18-8.646a3.42 3.42 0 0 1 4.261-1.289h.004zm2.173 6.238l2.443-4.234L28.5 17.45l-.003-.004a.81.81 0 0 1 .5.753v.794h-.002v8.02h-5.81q-.382-.001-.748-.056l-.643-.141a4.57 4.57 0 0 1-2.421-1.693c-.8-1.094-2.27-1.37-3.414-.735l-.001.001c-.69.385-1.506.84-2.148 1.2l-1.113.62l-.011.007a2.005 2.005 0 0 1-2.735-.734a2.006 2.006 0 0 1 .736-2.735l4.225-2.44a1 1 0 0 0 .663-.857a4.3 4.3 0 0 0-.479-2.526m-9.567 8.58l2.585 1.506a1.8 1.8 0 0 1-.427.424l-.007.005l-1.515 1.146l-1.001-.583l.208-1.884v-.011c.02-.214.073-.418.157-.603M19.392 7.477l-2.6-1.492l.976-1.692l2.595 1.5z"/></svg>
                    <?php
                    // The Write a Review block's per-state button text options
                    $addText = trim((string) Arr::get($this->renderOptions, 'ctaAddText', ''));
                    echo $addText !== '' ? esc_html($addText) : esc_html__('Write a Review', 'fluent-cart');
                    ?>
                <?php endif; ?>
            </button>
            <?php
        }
    }

    protected function renderControls($defaultSort = 'created_at-DESC')
    {
        $sortOptions = [
            'created_at-DESC' => __('Newest', 'fluent-cart'),
            'created_at-ASC'  => __('Oldest', 'fluent-cart'),
            'rating-DESC'     => __('Highest Rating', 'fluent-cart'),
            'rating-ASC'      => __('Lowest Rating', 'fluent-cart'),
        ];
        $sortOptions = apply_filters('fluent_cart/review/sort_options', $sortOptions);
        ?>
        <div class="fct-reviews-controls" data-reviews-controls>
            <div class="fct-reviews-filter-chips" data-reviews-filter-chips>
                <button type="button" class="fct-filter-chip active" data-filter-chip="all" aria-pressed="true"><?php esc_html_e('All', 'fluent-cart'); ?></button>
                <?php foreach ([5, 4, 3, 2, 1] as $star) : ?>
                    <button type="button" class="fct-filter-chip" data-filter-chip="<?php echo esc_attr($star); ?>" aria-pressed="false"><?php echo esc_html($star); ?> &#9733;</button>
                <?php endforeach; ?>
                <?php do_action('fluent_cart/review/filter_chips', $this->postId); ?>
            </div>
            <div class="fct-reviews-sort">
                <select data-reviews-sort aria-label="<?php esc_attr_e('Sort reviews', 'fluent-cart'); ?>">
                    <?php foreach ($sortOptions as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($defaultSort, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php
    }

    protected function renderStars($rating)
    {
        $fullStars = floor($rating);
        $halfStar = ($rating - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

        for ($i = 0; $i < $fullStars; $i++) {
            echo '<span class="fct-star fct-star-filled">&#9733;</span>';
        }
        if ($halfStar) {
            echo '<span class="fct-star fct-star-half"><span class="fct-star-half-empty">&#9733;</span><span class="fct-star-half-fill">&#9733;</span></span>';
        }
        for ($i = 0; $i < $emptyStars; $i++) {
            echo '<span class="fct-star fct-star-empty">&#9733;</span>';
        }
    }
}
