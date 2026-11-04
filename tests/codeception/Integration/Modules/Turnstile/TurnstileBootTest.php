<?php

namespace Tests\Integration\Modules\Turnstile;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Modules\Turnstile\TurnstileBoot;
use Tests\Support\DatabaseTestCase;

/**
 * Optimisers that delay or rewrite api.js leave checkout without a token, so the
 * tag carries each optimiser's opt-out attribute.
 */
class TurnstileBootTest extends DatabaseTestCase
{
    /** @var TurnstileBoot */
    private $boot;

    private $originalSettings;

    public function setUp(): void
    {
        parent::setUp();
        $this->boot = new TurnstileBoot();
        add_filter('wp_script_attributes', [$this->boot, 'addScriptAttributes'], 10, 1);
        $this->originalSettings = get_option(ModuleSettings::MODULE_SETTINGS_OPTION, null);
        $this->useKeys('site-key', 'secret-key');
        wp_dequeue_script('cloudflare-turnstile');
        wp_deregister_script('cloudflare-turnstile');
    }

    public function tearDown(): void
    {
        remove_filter('wp_script_attributes', [$this->boot, 'addScriptAttributes'], 10);
        wp_dequeue_script('cloudflare-turnstile');
        wp_deregister_script('cloudflare-turnstile');
        wp_deregister_script('fct-test-app');
        if ($this->originalSettings === null) {
            delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        } else {
            update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $this->originalSettings);
        }
        ModuleSettings::getAllSettings(false);
        parent::tearDown();
    }

    private function useKeys(string $siteKey, string $secretKey): void
    {
        $settings = is_array($this->originalSettings) ? $this->originalSettings : [];
        $settings['turnstile'] = ['active' => 'yes', 'site_key' => $siteKey, 'secret_key' => $secretKey];
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $settings);
        ModuleSettings::getAllSettings(false);
    }

    public function test_a_checkout_rendered_outside_the_checkout_page_loads_the_turnstile_script(): void
    {
        // A checkout block, builder widget or Pro inline checkout on any page fires this hook.
        ob_start();
        (new TurnstileBoot())->renderTurnstileWidget([]);
        $html = ob_get_clean();

        $this->assertStringContainsString('cf-turnstile', $html);
        $this->assertTrue(wp_script_is('cloudflare-turnstile', 'enqueued'), 'widget rendered without api.js');
    }

    public function test_turnstile_without_both_keys_is_off_for_the_checkout_script(): void
    {
        $this->useKeys('site-key', '');

        $data = (new TurnstileBoot())->localizeTurnstileData(['fluentcart_checkout_vars' => []]);

        $this->assertFalse($data['fluentcart_checkout_vars']['turnstile']['enabled']);
    }

    public function test_the_turnstile_script_opts_out_of_js_optimisers(): void
    {
        wp_register_script('cloudflare-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', [], null, true);
        // A "before" inline script shares the tag string; only the src tag may be marked.
        wp_add_inline_script('cloudflare-turnstile', 'window.fctBefore = 1;', 'before');

        $out = get_echo([wp_scripts(), 'do_item'], ['cloudflare-turnstile']);

        $this->assertMatchesRegularExpression('/<script id="cloudflare-turnstile-js-before">/', $out);

        // Rocket Loader only honours data-cfasync when it comes before src.
        $this->assertMatchesRegularExpression('/<script data-cfasync="false"[^>]* src="https:\/\/challenges\.cloudflare\.com/', $out);
        foreach (['nowprocket', 'data-no-optimize="1"', 'data-noptimize="1"', 'nitro-exclude'] as $attribute) {
            $this->assertMatchesRegularExpression('/<script [^>]*' . preg_quote($attribute, '/') . '[^>]* src=/', $out);
        }
    }

    public function test_other_scripts_are_left_alone(): void
    {
        wp_register_script('fct-test-app', 'https://shop.test/app.js', [], null, true);

        $out = get_echo([wp_scripts(), 'do_item'], ['fct-test-app']);

        $this->assertStringNotContainsString('data-cfasync', $out);
        $this->assertStringNotContainsString('nowprocket', $out);
    }
}
