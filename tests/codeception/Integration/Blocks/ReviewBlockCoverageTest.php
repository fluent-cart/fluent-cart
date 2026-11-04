<?php

namespace Tests\Integration\Blocks;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Every Review List child block, both ways round.
 *
 * A block an editor places has to appear, and — the half that is easy to lose —
 * a block an editor deletes has to stop appearing. The second is what makes the
 * row composable at all: if removing the Review Date still leaves a date on the
 * page, the editor is a decoration and the merchant has no control over the row.
 *
 * Every block is covered rather than a chosen few, because the failure is
 * per-block. One field going on drawing itself after it was removed does not
 * disturb the other fourteen, and nothing about the page looks wrong.
 *
 * Three of them draw only when the review gives them something: the variation
 * title needs a label, the photos need media, the reply needs a reply to view.
 * The fixture supplies all three — media through the filter PRO attaches it on,
 * so the block is exercised the way it is really used rather than through a
 * shortcut this test invented.
 *
 * Spec: Cases/reviews.md — "Review List block"
 */
class ReviewBlockCoverageTest extends WPTestCase
{
    /** @var int */
    private $productId;

    /** @var int */
    private $reviewId;

    /**
     * Slug => the class its wrapper carries when it draws.
     *
     * @return array<string, string>
     */
    public static function blocks(): array
    {
        // The second class is the field itself, which the fixed row draws under
        // the same name. Checking only the block wrapper would be checking that
        // Gutenberg does not run a callback for a block that is not there —
        // true, and no use. Checking the field catches the case that matters:
        // the row quietly falling back to the fixed renderer and drawing every
        // field again, wrappers and all absent.
        // [field class, does the list draw this itself when the block is gone]
        //
        // A composed list draws no fallback pager (ProductReviewRenderer: a
        // pager an editor did not place is one they did not want), so every
        // block but the loop is gone when it is removed — including the
        // header fields, because the header falls back only when every header
        // block has gone, and these cases remove one at a time.
        $fields = [
            // The loop itself. Take it away and there are no composed rows, so
            // the renderer draws its own.
            'review-item'                  => ['fct-reviews-list', true],
            'review-list-count'            => ['fct-reviews-section-title', false],
            'review-list-filter'           => ['fct-reviews-filter-chips', false],
            'review-list-sorting'          => ['fct-reviews-sort', false],
            'review-list-pagination'       => ['fct-reviews-pagination', false],
            'review-item-avatar'           => ['fct-review-block-avatar', false],
            'review-item-author-name'      => ['fct-review-item-author', false],
            'review-item-verified-badge'   => ['fct-review-verified', false],
            'review-item-variation-title'  => ['fct-review-item-variant', false],
            'review-item-rating'           => ['fct-review-item-stars', false],
            'review-item-date'             => ['fct-review-item-date', false],
            'review-item-title'            => ['fct-review-item-title', false],
            'review-item-content'          => ['fct-review-item-content', false],
            'review-item-photos'           => ['fct-review-media-gallery', false],
            'review-item-votes'            => ['fct-review-votes-slot', false],
            'review-item-reply'            => ['fct-review-reply-action', false],
        ];

        $cases = [];
        foreach ($fields as $slug => $field) {
            $cases[$slug] = [
                'fluent-cart/' . $slug,
                'wp-block-fluent-cart-' . $slug,
                $field[0],
                $field[1],
            ];
        }

        return $cases;
    }

    public function setUp(): void
    {
        parent::setUp();

        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['active'] = 'yes';
        $modules['reviews']['reviews_enabled'] = 'yes';
        $modules['reviews']['enable_helpful_votes'] = 'yes';
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        $product = (new ProductFactory())->create(['post_title' => 'Fully Furnished Widget']);
        $this->productId = (int) $product->ID;

        // Everything the conditional blocks read: a title, a rating, a verified
        // purchase. The rest arrives through the filter below.
        $review = new ProductReview([
            'post_id'        => $this->productId,
            'reviewer_name'  => 'Dana Buyer',
            'reviewer_email' => 'dana@example.test',
            'title'          => 'A considered opinion',
            'review'         => 'It arrived quickly and has held up well.',
            'rating'         => 5,
        ]);
        $review->status = Status::REVIEW_APPROVED;
        $review->is_verified = 1;
        $review->save();

        $this->reviewId = (int) $review->id;

        // The three the row cannot supply on its own. This is the filter PRO
        // attaches media on, so the photos block is fed the way it is fed in
        // production rather than by reaching past the service.
        add_filter('fluent_cart/review/public_response', [$this, 'attachReviewExtras'], 10, 2);
    }

    public function tearDown(): void
    {
        remove_filter('fluent_cart/review/public_response', [$this, 'attachReviewExtras'], 10);
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    /**
     * @param array $data
     * @param int $postId
     * @return array
     */
    public function attachReviewExtras($data, $postId): array
    {
        if (!isset($data['reviews']['data']) || !is_array($data['reviews']['data'])) {
            return $data;
        }

        foreach ($data['reviews']['data'] as $index => $review) {
            $data['reviews']['data'][$index]['media'] = [
                ['id' => 1, 'type' => 'image', 'url' => 'https://example.test/photo.jpg'],
            ];
            $data['reviews']['data'][$index]['item_label'] = 'Size 42 / Navy';
            $data['reviews']['data'][$index]['reply_count'] = 1;
        }

        return $data;
    }

    /**
     * A block an editor places draws itself.
     *
     * @dataProvider blocks
     * @param string $slug
     * @param string $marker
     * @param string $fieldClass
     * @param bool $listDrawsItAnyway
     */
    public function test_a_placed_block_renders(string $slug, string $marker, string $fieldClass, bool $listDrawsItAnyway): void
    {
        $html = $this->renderList($this->allBlocksExcept(null));

        $this->assertStringContainsString(
            $marker,
            $html,
            $slug . ' draws nothing with everything it needs in place'
        );
        $this->assertStringContainsString(
            $fieldClass,
            $html,
            $slug . ' draws its wrapper but not the field inside it'
        );
    }

    /**
     * A block an editor deletes stops drawing itself.
     *
     * The other fourteen are left in, so a list that renders nothing at all
     * cannot pass this by accident: the assertion below checks the row was
     * still drawn before checking what is missing from it.
     *
     * @dataProvider blocks
     * @param string $slug
     * @param string $marker
     * @param string $fieldClass
     * @param bool $listDrawsItAnyway
     */
    public function test_a_removed_block_does_not_render(string $slug, string $marker, string $fieldClass, bool $listDrawsItAnyway): void
    {
        $html = $this->renderList($this->allBlocksExcept($slug));

        $this->assertStringContainsString(
            'fct-review-item',
            $html,
            'the list still rendered, so the absences below mean something'
        );
        $this->assertStringNotContainsString(
            $marker,
            $html,
            $slug . ' is still on the page after being removed from the block'
        );

        if ($listDrawsItAnyway) {
            // Not a leak: the list owns its header and its pager, and falls
            // back to drawing them itself. Asserted rather than skipped,
            // because the fallback quietly disappearing would be a bug too.
            $this->assertStringContainsString(
                $fieldClass,
                $html,
                $slug . ' was removed and the list drew nothing in its place'
            );

            return;
        }

        $this->assertStringNotContainsString(
            $fieldClass,
            $html,
            $slug . ' was removed but its field is on the page anyway'
        );
    }

    /**
     * Every block bar one, serialized as the list's children.
     *
     * @param string|null $without slug to leave out, or null for all of them
     * @return string
     */
    private function allBlocksExcept($without): string
    {
        $header = '';
        $rowFields = '';
        $pager = '';

        foreach (static::blocks() as $case) {
            $slug = $case[0];

            if ($slug === $without || $slug === 'fluent-cart/review-item') {
                continue;
            }

            $block = '<!-- wp:' . $slug . ' /-->';

            if (strpos($slug, 'fluent-cart/review-list-pagination') === 0) {
                $pager .= $block;
            } elseif (strpos($slug, 'fluent-cart/review-list-') === 0) {
                $header .= $block;
            } else {
                $rowFields .= $block;
            }
        }

        // The row fields go inside the loop block, which is what repeats them.
        // Without it they would be the list's own children, drawn once for the
        // whole list with no review to read.
        $rows = $without === 'fluent-cart/review-item'
            ? ''
            : '<!-- wp:fluent-cart/review-item -->' . $rowFields . '<!-- /wp:fluent-cart/review-item -->';

        return $header . $rows . $pager;
    }

    /**
     * @param string $innerBlocks
     * @return string
     */
    private function renderList(string $innerBlocks): string
    {
        $attributes = wp_json_encode([
            'query_type' => 'custom',
            'product_id' => (string) $this->productId,
            'perPage'    => 1,
        ]);

        $markup = '<!-- wp:fluent-cart/product-review-list ' . $attributes . ' -->'
            . $innerBlocks
            . '<!-- /wp:fluent-cart/product-review-list -->';

        return (string) preg_replace('#<style.*?</style>#s', '', do_blocks($markup));
    }
}
