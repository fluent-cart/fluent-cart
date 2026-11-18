<?php

namespace Tests\Integration\Hooks;

use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The shortcode handlers scan the current post for their shortcode on
 * wp_enqueue_scripts, which runs in wp_head() outside the Loop. A plugin that
 * fires the_post without setup_postdata() leaves $pages null, and
 * get_the_content() then fatals with count(null) on PHP 8.
 */
class ShortcodeEnqueueContentScanTest extends WPTestCase
{
    private $savedPages;

    public function setUp(): void
    {
        parent::setUp();
        $this->savedPages = $GLOBALS['pages'] ?? null;
    }

    public function tearDown(): void
    {
        $GLOBALS['pages'] = $this->savedPages;
        parent::tearDown();
    }

    public function test_enqueue_scan_survives_post_globals_without_setup_postdata(): void
    {
        global $wp_query;

        $pageId = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '[fluent_cart_cart] [fluent_cart_customer_login]',
        ]);

        $GLOBALS['post'] = get_post($pageId);
        // Mimics a plugin firing the_post by hand before wp_head().
        do_action_ref_array('the_post', [&$GLOBALS['post'], &$wp_query]);
        $GLOBALS['pages'] = null;

        $queuedBefore = count(wp_styles()->queue);

        do_action('wp_enqueue_scripts');

        $this->assertGreaterThan($queuedBefore, count(wp_styles()->queue));
    }
}
