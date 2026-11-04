<?php

namespace Tests\Integration\Hooks;

use FluentCart\App\Hooks\Handlers\ShortCodes\CustomerProfileHandler;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Every script that localizes fluentCartRestVars re-declares the global, so
 * the last one printed wins. The customer portal's copy had no ajaxurl, which
 * left fluentCartAjax requesting "<page>/undefined" — the product quick-view
 * modal got an HTML page back and threw reading `view` of null.
 */
class CustomerProfileRestVarsTest extends WPTestCase
{
    public function test_portal_rest_vars_carry_the_ajax_url(): void
    {
        $restVars = CustomerProfileHandler::getLocalizationData()['fluentCartRestVars'];

        $this->assertSame(admin_url('admin-ajax.php'), $restVars['ajaxurl'] ?? null);
        $this->assertArrayHasKey('nonce', $restVars['rest']);
    }
}
