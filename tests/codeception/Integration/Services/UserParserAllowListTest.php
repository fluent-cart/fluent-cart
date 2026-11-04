<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\ShortCodeParser\Parsers\UserParser;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\WPUserFactory;

/**
 * {{user.*}} may only read the allow-listed profile fields; anything else used to
 * fall through to Arr::get() on the whole parser data.
 */
class UserParserAllowListTest extends WPTestCase
{
    public function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_credential_fields_never_resolve_even_for_an_admin(): void
    {
        $userId = WPUserFactory::create(['user_pass' => 'a-real-secret']);
        wp_set_current_user(WPUserFactory::create(['role' => 'administrator']));

        $parser = new UserParser(['user_id' => $userId]);

        $this->assertSame('', $parser->parse('user_pass'));
        $this->assertSame('', $parser->parse('user_activation_key'));
    }

    public function test_unlisted_accessor_cannot_walk_into_other_parser_data(): void
    {
        $userId = WPUserFactory::create();
        $data = [
            'user_id' => $userId,
            'order'   => ['customer' => ['email' => 'victim@example.test']],
        ];

        $this->assertSame('', (new UserParser($data))->parse('order.customer.email'));
        $this->assertStringNotContainsString(
            'victim@example.test',
            ShortcodeTemplateBuilder::make('{{user.order.customer.email}}', $data)
        );
    }

    public function test_user_login_resolves_only_for_a_manage_options_viewer(): void
    {
        $userId = WPUserFactory::create(['user_login' => 'allowlist_login']);
        $parser = new UserParser(['user_id' => $userId]);

        $this->assertSame('', $parser->parse('user_login'), 'logged-out render must not show the username');

        wp_set_current_user(WPUserFactory::create(['role' => 'subscriber']));
        $this->assertSame('', (new UserParser(['user_id' => $userId]))->parse('user_login'));

        wp_set_current_user(WPUserFactory::create(['role' => 'administrator']));
        $this->assertSame('allowlist_login', (new UserParser(['user_id' => $userId]))->parse('user_login'));
    }

    public function test_advertised_profile_codes_still_resolve(): void
    {
        $userId = WPUserFactory::create([
            'user_email'   => 'listed@example.test',
            'display_name' => 'Listed Name',
            'first_name'   => 'Listed',
            'last_name'    => 'Person',
        ]);
        $data = ['user_id' => $userId];

        $this->assertSame((string) $userId, ShortcodeTemplateBuilder::make('{{user.ID}}', $data));
        $this->assertSame('Listed Name', ShortcodeTemplateBuilder::make('{{user.display_name}}', $data));
        $this->assertSame('Listed', ShortcodeTemplateBuilder::make('{{user.first_name}}', $data));
        $this->assertSame('Person', ShortcodeTemplateBuilder::make('{{user.last_name}}', $data));
        $this->assertSame('listed@example.test', ShortcodeTemplateBuilder::make('{{user.user_email}}', $data));
        $this->assertSame('listed@example.test', ShortcodeTemplateBuilder::make('{{user.email}}', $data));
    }
}
