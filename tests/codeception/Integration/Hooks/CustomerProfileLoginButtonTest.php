<?php

namespace Tests\Integration\Hooks;

use FluentCart\App\Hooks\Handlers\ShortCodes\CustomerProfileHandler;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The customer portal's logged-out view links to the login screen. It used to
 * be a bare `.button`, so the theme painted it — and a theme that states a
 * transparent button text (Astra's dynamic `.button { color: transparent }`
 * when its button colour is unset) left the label invisible on the theme's
 * grey fill: `rgba(0,0,0,0)` on #e6e6e6.
 *
 * The link now carries the portal's own class, which the portal stylesheet
 * paints with the portal's button pair (customer-profile-global.scss). That
 * stylesheet was only enqueued for logged-in customers, so the logged-out
 * branch must enqueue it too, or the class styles nothing.
 */
class CustomerProfileLoginButtonTest extends WPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        wp_dequeue_style('fluent-cart-customer-profile-global');
        wp_deregister_style('fluent-cart-customer-profile-global');
    }

    public function test_the_logged_out_login_link_is_the_portal_button(): void
    {
        $html = (new CustomerProfileHandler())->render();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression(
            '/<a href="[^"]+" class="button fct-customer-login-btn">/',
            $html,
            'the Login link must carry the portal button class the stylesheet paints'
        );
    }

    public function test_the_logged_out_view_enqueues_the_portal_stylesheet(): void
    {
        (new CustomerProfileHandler())->render();

        $this->assertTrue(
            wp_style_is('fluent-cart-customer-profile-global', 'enqueued'),
            'the stylesheet that paints the Login button must load for logged-out visitors'
        );
    }
}
