<?php

namespace Tests\Functional\Rest\Reviews;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

class CombinedReviewSummaryCest
{
    public function combinedSummaryRefreshKeepsProductSelection(FunctionalTester $I): void
    {
        ModuleSettings::saveSettings(['reviews' => ['active' => 'yes']]);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();
        $I->logout();

        $selected = (new ProductFactory())->create();
        $excluded = (new ProductFactory())->create();
        foreach ([$selected->ID => 5, $excluded->ID => 1] as $postId => $rating) {
            $review = new ProductReview([
                'post_id' => $postId,
                'reviewer_name' => 'Summary reviewer',
                'review' => 'Summary refresh check.',
                'rating' => $rating,
            ]);
            $review->status = 'approved';
            $review->save();
        }
        ProductReviewResource::recalculateProductRatings([$selected->ID, $excluded->ID]);

        $params = ['product_ids' => (string) $selected->ID, 'per_page' => 1, 'include_summary' => '1'];
        [$status, $body] = $I->get('/fluent-cart/v2/public/reviews/all', $params);
        Assert::assertSame(200, $status, 'The combined summary is public.');
        Assert::assertSame(1, $body['summary']['total'], 'Other products do not contribute.');
        Assert::assertEquals(5, $body['summary']['average'], 'Selected product rating is used.');
        Assert::assertNotEmpty($body['stars_html'], 'Updated stars are rendered server-side.');

        $review = ProductReview::where('post_id', $selected->ID)->first();
        $review->rating = 3;
        $review->save();
        ProductReviewResource::recalculateProductRatings($selected->ID);

        [, $updated] = $I->get('/fluent-cart/v2/public/reviews/all', $params);
        Assert::assertEquals(3, $updated['summary']['average'], 'A refresh reflects an edited rating.');
        Assert::assertEquals(1, $updated['summary']['breakdown'][3], 'The updated bar counts are returned.');

        $params['include_summary'] = '0';
        [, $ordinary] = $I->get('/fluent-cart/v2/public/reviews/all', $params);
        Assert::assertArrayNotHasKey('summary', $ordinary, 'Ordinary list requests avoid summary work.');
    }
}
