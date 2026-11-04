<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks;
use lucatume\WPBrowser\TestCase\WPTestCase;

class ReviewCompositionLifecycleTest extends WPTestCase
{
    public function test_localized_fields_require_a_review_item_ancestor(): void
    {
        $blocks = (new InnerBlocks())->localizeData()['fluent_cart_review_inner_blocks']['blocks'];

        // The loop and the header blocks belong to the list itself; the rest
        // (the pager included, which declares no ancestor of its own) take the
        // review-item default.
        $listLevel = array_merge(['fluent-cart/review-item'], InnerBlocks::headerBlockSlugs());

        foreach ($blocks as $block) {
            $expected = in_array($block['slug'], $listLevel, true)
                ? ['fluent-cart/product-review-list']
                : ['fluent-cart/review-item'];

            $this->assertSame($expected, $block['ancestor'], $block['slug'] . ': the editor must only offer fields inside a review row.');
        }
    }

    /** @dataProvider sweepSizes */
    public function test_retained_rows_do_not_starve_later_orphans(int $size): void
    {
        update_option(InnerBlocks::SWEEP_CURSOR_KEY, 0, false);
        $markup = '<!-- wp:fluent-cart/review-item {"wp_client_id":"live-row"} -->'
            . '<!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->';
        $owner = self::factory()->post->create(['post_status' => 'publish', 'post_content' => $markup]);
        $expired = gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);
        $keys = [];

        for ($i = 0; $i < $size; $i++) {
            $keys[$i] = InnerBlocks::compositionKey('sweep-test-' . $i);
            update_option($keys[$i], [
                'set_at' => $expired,
                'owner' => $owner,
                'client_id' => $i < 10 ? 'live-row' : 'orphan-' . $i,
                'markup' => serialize_block(parse_blocks($markup)[0]),
            ], false);
        }

        $sweep = new \ReflectionMethod(InnerBlocks::class, 'pruneCompositions');
        $sweep->setAccessible(true);
        for ($i = 0; $i < (int) ceil($size / InnerBlocks::COMPOSITION_SWEEP_CHECKS) + 2; $i++) {
            $sweep->invoke(null);
        }

        foreach ($keys as $i => $key) {
            if ($i < 10) {
                $this->assertIsArray(get_option($key), 'Still-used rows survive every sweep.');
            } else {
                $this->assertFalse(get_option($key), 'Every orphan after the retained rows must eventually be deleted.');
            }
        }
    }

    public function sweepSizes(): array
    {
        return ['short batch' => [40], 'multiple batches' => [205]];
    }

    public function test_obsolete_versions_are_removed_but_current_and_recent_versions_survive(): void
    {
        $old = '<!-- wp:fluent-cart/review-item {"wp_client_id":"edited-row"} -->'
            . '<!-- wp:fluent-cart/review-item-avatar /--><!-- /wp:fluent-cart/review-item -->';
        $current = str_replace('review-item-avatar', 'review-item-title', $old);
        $owner = self::factory()->post->create(['post_status' => 'publish', 'post_content' => $current]);
        self::factory()->post->create([
            'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $owner, 'post_content' => $old,
        ]);
        $keys = [];
        foreach (['old' => $old, 'current' => $current, 'recent' => $old] as $version => $markup) {
            $keys[$version] = InnerBlocks::compositionKey('version-' . $version);
            update_option($keys[$version], [
                'set_at' => gmdate('Y-m-d H:i:s', time() - ($version === 'recent' ? 1 : 90) * DAY_IN_SECONDS),
                'owner' => $owner,
                'client_id' => 'edited-row',
                'markup' => serialize_block(parse_blocks($markup)[0]),
            ], false);
        }
        update_option(InnerBlocks::SWEEP_CURSOR_KEY, 0, false);
        $sweep = new \ReflectionMethod(InnerBlocks::class, 'pruneCompositions');
        $sweep->setAccessible(true);
        $sweep->invoke(null);

        $this->assertFalse(get_option($keys['old']), 'An old revision cannot retain an obsolete composition.');
        $this->assertIsArray(get_option($keys['current']), 'The current exact layout remains available for refreshes.');
        $this->assertIsArray(get_option($keys['recent']), 'Cached pages keep their recent previous layout for the retention window.');
    }

    public function test_an_exact_copy_in_a_template_keeps_the_composition_alive(): void
    {
        $markup = '<!-- wp:fluent-cart/review-item {"wp_client_id":"copied-row"} -->'
            . '<!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->';
        $owner = self::factory()->post->create(['post_status' => 'publish', 'post_content' => 'Removed']);
        self::factory()->post->create([
            'post_type' => 'wp_template', 'post_status' => 'publish',
            'post_content' => '<!-- wp:group --><div class="wp-block-group">' . $markup . '</div><!-- /wp:group -->',
        ]);
        $key = InnerBlocks::compositionKey('copied-layout');
        update_option($key, [
            'set_at' => gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS),
            'owner' => $owner,
            'client_id' => 'copied-row',
            'markup' => serialize_block(parse_blocks($markup)[0]),
        ], false);
        update_option(InnerBlocks::SWEEP_CURSOR_KEY, 0, false);
        $sweep = new \ReflectionMethod(InnerBlocks::class, 'pruneCompositions');
        $sweep->setAccessible(true);
        $sweep->invoke(null);

        $this->assertIsArray(get_option($key), 'A nested template copy keeps the exact composition in use.');
    }

    public function test_a_trashed_owner_does_not_keep_a_composition_alive(): void
    {
        $markup = '<!-- wp:fluent-cart/review-item {"wp_client_id":"trashed-row"} -->'
            . '<!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->';
        $owner = self::factory()->post->create(['post_status' => 'trash', 'post_content' => $markup]);
        $key = InnerBlocks::compositionKey('trashed-layout');
        update_option($key, [
            'set_at' => gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS),
            'owner' => $owner, 'client_id' => 'trashed-row',
            'markup' => serialize_block(parse_blocks($markup)[0]),
        ], false);
        update_option(InnerBlocks::SWEEP_CURSOR_KEY, 0, false);
        $sweep = new \ReflectionMethod(InnerBlocks::class, 'pruneCompositions');
        $sweep->setAccessible(true);
        $sweep->invoke(null);

        $this->assertFalse(get_option($key), 'Discarded content must not retain an expired layout.');
    }
}
