<?php

namespace Tests\Integration\Hooks;

use FluentCart\App\Hooks\Handlers\ShortCodes\CustomerProfileHandler;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The customer profile shortcode lets a logged-out visitor carry a `redirect_to`
 * target through the login screen, so that deep links into the customer portal
 * survive the detour. That parameter arrives straight from the query string,
 * which makes CustomerProfileHandler::resolveLoginRedirectUrl() an open-redirect
 * surface: whatever it returns is handed to wp_login_url() and to FluentAuth's
 * shortcode as the post-login destination.
 *
 * These tests pin the boundary. Same-site targets must survive, and every
 * off-site shape must collapse back to the store's own profile page.
 *
 * The site URL is filtered to SITE_URL for the duration of each test and the
 * hostile URLs are built by appending to that same string. That is not cosmetic:
 * it is what makes each hostile URL genuinely carry the site URL as a literal
 * prefix while resolving to a different host, which is the only form of these
 * cases that can tell a host check apart from a prefix check. Built any other
 * way they are rejected on their scheme or port instead, and then pass whether
 * or not the host is ever compared.
 *
 * Two details make SITE_URL what it is. It is port-less, because the real test
 * site (http://localhost:<port>) cannot be suffixed into a parseable host. And
 * its scheme is http, because home_url() keeps the stored scheme while
 * get_site_url() pushes it through set_url_scheme() — under http they agree, so
 * these cases stay honest against a host check and a prefix check alike.
 */
class CustomerProfileLoginRedirectTest extends WPTestCase
{
    const SITE_URL = 'http://store.test';

    const FALLBACK_URL = 'http://store.test/customer-dashboard';

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('pre_option_home', [$this, 'filterSiteUrlForTest']);
        add_filter('pre_option_siteurl', [$this, 'filterSiteUrlForTest']);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_option_home', [$this, 'filterSiteUrlForTest']);
        remove_filter('pre_option_siteurl', [$this, 'filterSiteUrlForTest']);
        unset($_GET['redirect_to']);
        parent::tearDown();
    }

    public function filterSiteUrlForTest()
    {
        return self::SITE_URL;
    }

    public function test_an_absolute_url_on_this_site_is_used_as_the_redirect_target(): void
    {
        $_GET['redirect_to'] = self::SITE_URL . '/account/subscriptions';

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(
            self::SITE_URL . '/account/subscriptions',
            $resolved,
            'a redirect_to pointing at this site should be honoured verbatim'
        );
    }

    public function test_a_site_relative_path_is_used_as_the_redirect_target(): void
    {
        $_GET['redirect_to'] = '/account/orders?page=2';

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(
            '/account/orders?page=2',
            $resolved,
            'a relative path cannot leave the site, so it should be honoured'
        );
    }

    public function test_a_lookalike_domain_that_merely_starts_with_the_site_url_is_rejected(): void
    {
        $_GET['redirect_to'] = self::SITE_URL . '.evil.example/steal';

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(
            self::FALLBACK_URL,
            $resolved,
            'a subdomain-suffix lookalike shares the site URL as a string prefix but is a different host, so it must fall back'
        );
    }

    public function test_a_domain_extending_the_site_host_is_rejected(): void
    {
        $_GET['redirect_to'] = self::SITE_URL . 'evil.example/steal';

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(
            self::FALLBACK_URL,
            $resolved,
            'appending characters to the site host produces a different host and must fall back'
        );
    }

    public function test_a_url_using_the_site_host_as_credentials_is_rejected(): void
    {
        $_GET['redirect_to'] = self::SITE_URL . '@evil.example/steal';

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(
            self::FALLBACK_URL,
            $resolved,
            'the site host in the userinfo position is not the destination host and must fall back'
        );
    }

    public function test_an_unrelated_external_url_is_rejected(): void
    {
        $_GET['redirect_to'] = 'https://evil.example/steal';

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(self::FALLBACK_URL, $resolved, 'an off-site URL must fall back to the store page');
    }

    public function test_a_protocol_relative_url_is_rejected(): void
    {
        $_GET['redirect_to'] = '//evil.example/steal';

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(
            self::FALLBACK_URL,
            $resolved,
            'a protocol-relative URL resolves to an external host in the browser and must fall back'
        );
    }

    public function test_a_javascript_url_is_rejected(): void
    {
        $_GET['redirect_to'] = 'javascript:alert(document.cookie)';

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(self::FALLBACK_URL, $resolved, 'a non-http scheme must never become the redirect target');
    }

    public function test_a_missing_redirect_parameter_leaves_the_fallback_untouched(): void
    {
        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(self::FALLBACK_URL, $resolved, 'with no redirect_to the caller-supplied fallback should be returned');
    }

    public function test_an_empty_redirect_parameter_leaves_the_fallback_untouched(): void
    {
        $_GET['redirect_to'] = '';

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(self::FALLBACK_URL, $resolved, 'an empty redirect_to carries no destination and should be ignored');
    }

    public function test_an_array_redirect_parameter_is_ignored_rather_than_fatal(): void
    {
        $_GET['redirect_to'] = ['https://evil.example/steal'];

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertSame(
            self::FALLBACK_URL,
            $resolved,
            'redirect_to[]=... makes the parameter an array; it must be ignored, not passed to string functions'
        );
    }

    public function test_a_slashed_redirect_parameter_is_unslashed_before_validation(): void
    {
        // WordPress adds slashes to superglobals, so an on-site URL arrives escaped.
        $_GET['redirect_to'] = addslashes(self::SITE_URL . "/account/orders?q=o'brien");

        $resolved = (new CustomerProfileHandler())->resolveLoginRedirectUrl(self::FALLBACK_URL);

        $this->assertStringNotContainsString(
            '\\',
            $resolved,
            'the WordPress-added slashes must be stripped so the target is not corrupted by a backslash'
        );
    }
}
