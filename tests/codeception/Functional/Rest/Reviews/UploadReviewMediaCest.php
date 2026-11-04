<?php

namespace Tests\Functional\Rest\Reviews;

use FluentCart\App\Models\ProductReview;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/reviews.md → "Attachments on an admin-added review", items 23-25.
 *
 * Attachments travel on the create request itself — there is no separate
 * upload endpoint, no claim token and nothing left pending between requests.
 * ProductReviewController::create() stores the review first, then hands the
 * uploaded files to fluent_cart/review/admin_store_media, which the Pro
 * reviews module implements. Free ships no media pipeline, so these tests pin
 * the delegation contract rather than any storage behaviour.
 */
class UploadReviewMediaCest
{
    public function _after(FunctionalTester $I): void
    {
        $_FILES = [];
        remove_all_actions('fluent_cart/review/admin_store_media');
    }

    /**
     * Spec: Cases/Functional/reviews.md [23]
     *   "Attachments sent with a new review are handed to the storage
     *    extension, together with the review they belong to."
     */
    public function attachmentsAreHandedToTheStorageExtension(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        $seen = null;
        add_action('fluent_cart/review/admin_store_media', function ($payload) use (&$seen) {
            $seen = [
                'review_id' => isset($payload['review']->id) ? (int) $payload['review']->id : null,
                'post_id'   => isset($payload['review']->post_id) ? (int) $payload['review']->post_id : null,
                'fileCount' => is_array($payload['files'] ?? null) ? count($payload['files']) : 0,
            ];
        });

        $_FILES = [
            'files' => [
                'name'     => 'probe.png',
                'type'     => 'image/png',
                'tmp_name' => '/tmp/fct-probe-does-not-need-to-exist.png',
                'error'    => UPLOAD_ERR_OK,
                'size'     => 128,
            ],
        ];

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'content'       => 'Photos ride along on the create request.',
            'reviewer_name' => 'Inline Attachment',
        ]);

        $I->seeResponseCodeIs(200);

        Assert::assertSame(
            (int) $body['review']['id'],
            $seen['review_id'] ?? null,
            'the extension receives the review the files belong to, already saved'
        );
        Assert::assertSame(
            (int) $product->ID,
            $seen['post_id'] ?? null,
            'and therefore the product, without being told separately'
        );
        Assert::assertSame(1, $seen['fileCount'] ?? 0, 'the uploaded files are passed through');
    }

    /**
     * Spec: Cases/Functional/reviews.md [24]
     *   "A review added without any attachment does not invoke the storage
     *    extension at all."
     */
    public function reviewWithoutAttachmentsDoesNotInvokeStorage(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        $called = false;
        add_action('fluent_cart/review/admin_store_media', function () use (&$called) {
            $called = true;
        });

        $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'content'       => 'No photos on this one.',
            'reviewer_name' => 'No Attachment',
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertFalse($called, 'the storage extension is only asked when files were actually sent');
    }

    /**
     * Spec: Cases/Functional/reviews.md [25]
     *   "With no extension providing storage, the review is still added and
     *    simply carries no attachments."
     */
    public function reviewStillSavesWhenNoStorageExtensionExists(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        $_FILES = [
            'files' => [
                'name'     => 'probe.png',
                'type'     => 'image/png',
                'tmp_name' => '/tmp/fct-probe-does-not-need-to-exist.png',
                'error'    => UPLOAD_ERR_OK,
                'size'     => 128,
            ],
        ];

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 4,
            'content'       => 'Free alone cannot store photos, but the words must survive.',
            'reviewer_name' => 'No Pipeline',
        ]);

        $I->seeResponseCodeIs(200);

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertNotEmpty($row, 'the review itself is saved even though nothing can store its photos');
        Assert::assertSame(0, (int) $row->media_count, 'and honestly reports carrying no attachments');
    }
}
