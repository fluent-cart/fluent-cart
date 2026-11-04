<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The Write a Review trigger carries both of its labels, one hidden, so
 * ReviewForm.js can flip it to "Edit your review" the moment a review is
 * saved — without the page reload that used to be the only way there.
 */
class ReviewCtaLabelsTest extends WPTestCase
{
    private int $postId = 0;

    public function setUp(): void
    {
        parent::setUp();

        ModuleSettings::saveSettings([
            'reviews' => [
                'active'                 => 'yes',
                'review_permission_mode' => 'anyone',
            ],
        ]);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetReviewSettingsCache();

        $this->postId = self::factory()->post->create(['post_type' => 'fluent-products', 'post_status' => 'publish']);
        wp_set_current_user(0);
    }

    private function cta(): string
    {
        ob_start();
        (new ProductReviewRenderer($this->postId))->renderWriteReviewCta();

        return (string) ob_get_clean();
    }

    public function test_a_visitor_without_a_review_sees_write_with_edit_held_in_reserve(): void
    {
        $html = $this->cta();

        $this->assertStringContainsString('data-open-review-drawer', $html);
        $this->assertMatchesRegularExpression('/<span class="fct-review-cta-label" data-cta-add>/', $html, 'the add label is shown');
        $this->assertMatchesRegularExpression('/<span class="fct-review-cta-label" data-cta-edit hidden>/', $html, 'the edit label is present but hidden');
        $this->assertStringContainsString('Write a Review', $html);
        $this->assertStringContainsString('Edit your review', $html);
    }

    public function test_a_reviewer_sees_edit_with_write_held_in_reserve(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);
        $review = ProductReview::query()->create([
            'post_id'        => $this->postId,
            'rating'         => 4,
            'title'          => 'Mine',
            'content'        => 'Already written.',
            'reviewer_name'  => 'Me',
            'reviewer_email' => 'me@example.com',
        ]);
        // Trust fields are guarded against mass assignment; set them the way
        // the write path does.
        $review->user_id = $userId;
        $review->status = 'approved';
        $review->save();

        $html = $this->cta();

        $this->assertMatchesRegularExpression('/<span class="fct-review-cta-label" data-cta-add hidden>/', $html);
        $this->assertMatchesRegularExpression('/<span class="fct-review-cta-label" data-cta-edit>/', $html);
        wp_set_current_user(0);
    }
}
