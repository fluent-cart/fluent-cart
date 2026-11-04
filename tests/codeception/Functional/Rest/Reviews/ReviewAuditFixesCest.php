<?php

namespace Tests\Functional\Rest\Reviews;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

class ReviewAuditFixesCest
{
    private $originalIp;

    public function _before(): void
    {
        $this->originalIp = $_SERVER['REMOTE_ADDR'] ?? null;
        ModuleSettings::saveSettings(['reviews' => [
            'active' => 'yes',
            'review_permission_mode' => 'anyone',
            'auto_approve_reviews' => 'yes',
        ]]);
        ProductReviewService::resetRuntimeCache();
    }

    public function _after(): void
    {
        if ($this->originalIp === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->originalIp;
        }
        ProductReviewService::resetRuntimeCache();
    }

    public function guestLimitSurvivesObjectCacheFlushes(FunctionalTester $I): void
    {
        $I->logout();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.174';
        $product = (new ProductFactory())->create();
        for ($attempt = 0; $attempt < 7; $attempt++) {
            wp_cache_flush();
            [$status, $body] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
                'rating' => 5,
                'content' => 'A bounded guest review.',
                'reviewer_name' => 'Guest Buyer',
                'reviewer_email' => 'limit' . $attempt . '@example.test',
            ]);
            Assert::assertSame($attempt < 5 ? 200 : 429, $status);
            if ($attempt < 5) {
                Assert::assertFalse($body['can_edit']);
            }
        }
        Assert::assertSame(5, ProductReview::query()->where('post_id', $product->ID)->count());
    }

    public function oversizedIdentityAndTitleAreRejectedWithoutRows(FunctionalTester $I): void
    {
        $I->logout();
        $product = (new ProductFactory())->create();
        $payload = [
            'rating' => 5,
            'content' => 'Storage bounds must not silently truncate input.',
            'reviewer_name' => 'Guest Buyer',
            'reviewer_email' => 'bounded@example.test',
        ];
        $longEmail = str_repeat('a', 60) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.example.test';
        foreach ([['title' => str_repeat('x', 193)], ['reviewer_email' => $longEmail]] as $invalid) {
            [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, array_merge($payload, $invalid));
            Assert::assertSame(422, $status);
        }
        Assert::assertSame(0, ProductReview::query()->where('post_id', $product->ID)->count());
    }

    public function disabledReviewsRejectPublicReadsAndOwnerEdits(FunctionalTester $I): void
    {
        $userId = $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();
        $review = ProductReviewResource::create([
            'post_id' => $product->ID, 'user_id' => $userId,
            'rating' => 5, 'content' => 'Original content', 'status' => 'approved',
        ]);
        ModuleSettings::saveSettings(['reviews' => ['active' => 'no']]);
        ProductReviewService::resetRuntimeCache();
        foreach (['', '/summary', '/' . $review->id . '/modal', '/' . $review->id . '/replies'] as $suffix) {
            [$status] = $I->get('/fluent-cart/v2/public/reviews/' . $product->ID . $suffix);
            Assert::assertSame(404, $status);
        }
        [$status] = $I->put('/fluent-cart/v2/public/reviews/' . $product->ID . '/' . $review->id, ['content' => 'Changed']);
        Assert::assertSame(403, $status);
        Assert::assertSame('Original content', ProductReview::query()->find($review->id)->content);
    }

    public function invalidAdminProductIsClientError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$status] = $I->post('/fluent-cart/v2/reviews', [
            'post_id' => 999999999, 'rating' => 5,
            'content' => 'Missing product', 'reviewer_name' => 'Buyer',
        ]);
        Assert::assertSame(400, $status);
    }

    public function adminCanApprovePendingCustomerReply(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();
        $parent = ProductReviewResource::create([
            'post_id' => $product->ID, 'rating' => 5,
            'content' => 'Parent review', 'status' => 'approved',
        ]);
        $reply = ProductReviewResource::create([
            'post_id' => $product->ID, 'parent_id' => $parent->id,
            'reviewer_name' => 'Customer Author', 'is_admin_reply' => 0,
            'content' => 'Pending customer reply', 'status' => 'pending',
        ]);
        [$status] = $I->put('/fluent-cart/v2/reviews/' . $reply->id, ['status' => 'approved']);
        Assert::assertSame(200, $status);
        $saved = ProductReview::query()->find($reply->id);
        Assert::assertSame('approved', $saved->status);
        Assert::assertSame('Customer Author', $saved->reviewer_name);
        Assert::assertSame(0, (int) $saved->is_admin_reply);
    }
}
