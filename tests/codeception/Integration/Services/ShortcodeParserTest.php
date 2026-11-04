<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\App\Services\ShortCodeParser\SmartCodeParser;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Tests for ShortcodeTemplateBuilder + SmartCodeParser + ValueTransformer trait.
 */
class ShortcodeParserTest extends WPTestCase
{
    // =========================================================================
    // Spec 125: ShortcodeTemplateBuilder::make returns parsed template
    // =========================================================================

    public function test_make_resolves_template_via_smart_code_parser(): void
    {
        update_option('blogname', 'Wonder Mart');
        $rendered = ShortcodeTemplateBuilder::make('Site: {{wp.site_name}}', []);
        $this->assertSame('Site: Wonder Mart', $rendered);
    }

    // =========================================================================
    // Spec 126: makeFromTemplates concatenates parsed output
    // =========================================================================

    public function test_make_from_templates_concatenates(): void
    {
        update_option('blogname', 'X');
        $rendered = ShortcodeTemplateBuilder::makeFromTemplates(['A:{{wp.site_name}}', '|B:{{wp.site_name}}'], []);
        $this->assertSame('A:X|B:X', $rendered);
    }

    // =========================================================================
    // Spec 127: makeFromTemplatesArray recurses
    // =========================================================================

    public function test_make_from_templates_array_recurses(): void
    {
        update_option('blogname', 'Z');
        $input = [
            'top' => 'Hi {{wp.site_name}}',
            'nested' => [
                'inner' => 'Inner {{wp.site_name}}',
            ],
        ];
        $out = ShortcodeTemplateBuilder::makeFromTemplatesArray($input, []);
        $this->assertSame('Hi Z', $out['top']);
        $this->assertSame('Inner Z', $out['nested']['inner']);
    }

    // =========================================================================
    // Spec 128: parseTemplate resolves wp.user_email via UserParser
    // =========================================================================

    public function test_parse_template_resolves_user_smartcode(): void
    {
        $userId = static::factory()->user->create([
            'role'       => 'subscriber',
            'user_email' => 'parser-test@example.test',
        ]);
        $rendered = ShortcodeTemplateBuilder::make('To: {{user.user_email}}', ['user_id' => $userId]);
        $this->assertSame('To: parser-test@example.test', $rendered);
    }

    // =========================================================================
    // Spec 129: template wrapper start `{{` end `}}` from config template_string
    // =========================================================================

    public function test_template_wrapper_start_end(): void
    {
        $parser = new SmartCodeParser();
        $wrapper = $parser->getTemplateWrapper();
        $this->assertSame('{{', $wrapper['start']);
        $this->assertSame('}}', $wrapper['end']);
    }

    // =========================================================================
    // Spec 130: ##code## syntax also matched
    // =========================================================================

    public function test_double_hash_delimiter_also_matched(): void
    {
        update_option('blogname', 'HashShop');
        $rendered = ShortcodeTemplateBuilder::make('Hello ##wp.site_name##', []);
        $this->assertSame('Hello HashShop', $rendered);
    }

    // =========================================================================
    // Spec 131: empty `{{}}` routed to smartcode_fallback filter
    // =========================================================================

    public function test_empty_smartcode_routes_to_fallback_filter(): void
    {
        $captured = [];
        add_filter('fluent_cart/smartcode_fallback', static function ($value, $code = null, $data = null, $conditions = null) use (&$captured) {
            $captured[] = func_get_args();
            return 'EMPTY_FALLBACK';
        }, 10, 4);
        try {
            $rendered = ShortcodeTemplateBuilder::make('Before {{}} After', []);
            $this->assertStringContainsString('EMPTY_FALLBACK', $rendered);
            $this->assertNotEmpty($captured);
        } finally {
            remove_all_filters('fluent_cart/smartcode_fallback');
        }
    }

    // =========================================================================
    // Spec 132: unknown parser group preserves the original code
    // =========================================================================

    public function test_unknown_parser_group_preserves_code(): void
    {
        $rendered = ShortcodeTemplateBuilder::make('Test {{unknown_group.foo}}', []);
        $this->assertSame('Test {{unknown_group.foo}}', $rendered);
    }

    // =========================================================================
    // Spec 133: single-segment code (no dot) routes through smartcode_fallback
    // =========================================================================

    public function test_single_segment_code_routes_to_fallback(): void
    {
        add_filter('fluent_cart/smartcode_fallback', static function ($value) {
            return $value === 'foo' ? 'FALLBACK_FOO' : $value;
        });
        try {
            $rendered = ShortcodeTemplateBuilder::make('A {{foo}} B', []);
            $this->assertSame('A FALLBACK_FOO B', $rendered);
        } finally {
            remove_all_filters('fluent_cart/smartcode_fallback');
        }
    }

    // =========================================================================
    // Spec 134: getParseHandlerReference resolves parser/accessor/handler
    // =========================================================================

    public function test_get_parse_handler_reference_for_order_id(): void
    {
        $parser = new SmartCodeParser();
        $ref = $parser->getParseHandlerReference('order.id');
        $this->assertSame('order', $ref['parser']);
        $this->assertSame('id', $ref['accessor']);
        $this->assertSame(\FluentCart\App\Services\ShortCodeParser\Parsers\OrderParser::class, $ref['handler']);
    }

    // =========================================================================
    // Spec 135: getParseHandlerReference caches in static $reference
    // =========================================================================

    public function test_get_parse_handler_reference_caches(): void
    {
        $parser = new SmartCodeParser();
        $first = $parser->getParseHandlerReference('order.status');
        $second = $parser->getParseHandlerReference('order.status');
        $this->assertSame($first, $second);
    }

    // =========================================================================
    // Spec 136: parseCode calls transform — title_case capitalizes
    // =========================================================================

    public function test_parse_code_applies_transformer(): void
    {
        update_option('blogname', 'lower case shop');
        // {{wp.site_name||title_case}}
        $rendered = ShortcodeTemplateBuilder::make('{{wp.site_name||title_case}}', []);
        $this->assertSame('Lower Case Shop', $rendered);
    }

    // =========================================================================
    // Spec 137: wrapSmartCode wraps in {{ }}
    // =========================================================================

    public function test_wrap_smart_code(): void
    {
        $parser = new SmartCodeParser();
        $this->assertSame('{{order.id}}', $parser->wrapSmartCode('order.id'));
    }

    // =========================================================================
    // Spec 138: evaluateCondition with transformer + default
    // =========================================================================

    public function test_evaluate_condition_full_pipeline(): void
    {
        $parser = new SmartCodeParser();
        $cond = $parser->evaluateCondition('order.status||title_case|fallback');
        $this->assertSame('title_case', $cond['transformer']);
        $this->assertSame('fallback', $cond['default_value']);
        $this->assertSame('order.status', $cond['accessor']);
    }

    // =========================================================================
    // Spec 139: evaluateCondition default-only
    // =========================================================================

    public function test_evaluate_condition_default_only(): void
    {
        $parser = new SmartCodeParser();
        $cond = $parser->evaluateCondition('foo|bar');
        $this->assertSame('bar', $cond['default_value']);
        $this->assertNull($cond['transformer']);
        $this->assertSame('foo', $cond['accessor']);
    }

    // =========================================================================
    // Spec 140: callable transformers (trim/ucfirst/strtolower/strtoupper/ucwords)
    // =========================================================================

    public function test_callable_transformers_run_php_funcs(): void
    {
        update_option('blogname', '  hello world  ');
        $this->assertSame('  HELLO WORLD  ', ShortcodeTemplateBuilder::make('{{wp.site_name||strtoupper}}', []));
        $this->assertSame('hello world', trim(ShortcodeTemplateBuilder::make('{{wp.site_name||trim}}', [])));

        update_option('blogname', 'hello');
        $this->assertSame('Hello', ShortcodeTemplateBuilder::make('{{wp.site_name||ucfirst}}', []));

        update_option('blogname', 'HELLO');
        $this->assertSame('hello', ShortcodeTemplateBuilder::make('{{wp.site_name||strtolower}}', []));

        update_option('blogname', 'hello world');
        $this->assertSame('Hello World', ShortcodeTemplateBuilder::make('{{wp.site_name||ucwords}}', []));
    }

    // =========================================================================
    // Spec 141: concat_first prepends default
    // =========================================================================

    public function test_concat_first_prepends_default(): void
    {
        update_option('blogname', 'World');
        // Note: evaluateCondition trims default; transform also trims combined value.
        $rendered = ShortcodeTemplateBuilder::make('{{wp.site_name||concat_first|Hello-}}', []);
        $this->assertSame('Hello-World', $rendered);
    }

    // =========================================================================
    // Spec 142: concat_last appends default
    // =========================================================================

    public function test_concat_last_appends_default(): void
    {
        update_option('blogname', 'World');
        $rendered = ShortcodeTemplateBuilder::make('{{wp.site_name||concat_last|!}}', []);
        $this->assertSame('World!', $rendered);
    }

    // =========================================================================
    // Spec 143: title_case empty value returns unchanged
    // =========================================================================

    public function test_title_case_empty_value_unchanged(): void
    {
        // Use a known-empty smartcode path: settings.store_logo when no logo set
        delete_option('fluent_cart_settings');
        $rendered = ShortcodeTemplateBuilder::make('{{settings.store_logo||title_case}}', []);
        $this->assertSame('', $rendered);
    }

    // =========================================================================
    // Spec 144: headline transformer
    // =========================================================================

    public function test_headline_transformer(): void
    {
        update_option('blogname', 'hello_world_test');
        $rendered = ShortcodeTemplateBuilder::make('{{wp.site_name||headline}}', []);
        // Str::headline('hello_world_test') => 'Hello World Test'
        $this->assertSame('Hello World Test', $rendered);
    }

    // =========================================================================
    // Spec 145: show_if returns default when value non-empty, else ''
    // =========================================================================

    public function test_show_if_with_value(): void
    {
        update_option('blogname', 'X');
        $rendered = ShortcodeTemplateBuilder::make('{{wp.site_name||show_if|VISIBLE}}', []);
        $this->assertSame('VISIBLE', $rendered);
    }

    // =========================================================================
    // Spec 146: empty value + empty default falls to smartcode_fallback
    // =========================================================================

    public function test_empty_value_and_empty_default_routes_to_fallback(): void
    {
        $captured = [];
        add_filter('fluent_cart/smartcode_fallback', static function ($value, $code = null, $data = null, $conditions = null) use (&$captured) {
            $captured[] = $code;
            return 'FB';
        }, 10, 4);
        try {
            $rendered = ShortcodeTemplateBuilder::make('{{settings.store_address}}', []);
            // settings.store_address resolves but typically empty -> fallback fires
            $this->assertNotEmpty($captured);
            $this->assertSame('FB', $rendered, 'the fallback filter result is what renders');
        } finally {
            remove_all_filters('fluent_cart/smartcode_fallback');
        }
    }
}
