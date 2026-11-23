<?php

namespace Tests\Integration\Helpers;

use FluentCart\App\Helpers\Helper;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Rest.js refuses a URL that holds a character PHP splits the query on, so the page
 * must receive the server's own arg_separator.input, not a guess.
 * The setting is per-directory, so ini_set() cannot change it here: compare with the live value.
 */
class RestInfoQuerySeparatorsTest extends WPTestCase
{
    public function test_rest_info_carries_the_configured_separators(): void
    {
        $info = Helper::getRestInfo();

        $this->assertArrayHasKey('query_separators', $info);
        $this->assertSame((string)ini_get('arg_separator.input'), $info['query_separators']);
    }

    public function test_rest_info_keeps_its_existing_keys(): void
    {
        $info = Helper::getRestInfo();

        foreach (['base_url', 'url', 'nonce', 'namespace', 'version'] as $key) {
            $this->assertArrayHasKey($key, $info);
        }
    }
}
