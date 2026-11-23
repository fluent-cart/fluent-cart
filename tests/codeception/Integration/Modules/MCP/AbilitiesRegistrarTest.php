<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\AbilitiesRegistrar;
use FluentCart\App\Modules\MCP\MCPInit;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Traits\InvokesPrivateMethods;

/**
 * AbilitiesRegistrar cross-cutting behavior:
 *  - WordPress ability annotations remain intact for the Abilities API; the
 *    adapter translates them for MCP clients.
 *  - a param the tool does not declare is rejected BEFORE the callback runs
 *    (a confidently unfiltered result is worse for an agent than an error),
 *    with the accepted params listed so the agent can self-correct.
 *  - a per_page request above the tool's ceiling gets a runtime meta.warnings
 *    entry stating what was actually returned.
 *
 * All are private helpers (implementation detail of register()), exercised via
 * reflection so the observable contract is pinned down.
 */
class AbilitiesRegistrarTest extends WPTestCase
{
    use InvokesPrivateMethods;

    public function test_registered_read_ability_keeps_wordpress_annotations(): void
    {
        // WordPress fires the abilities init actions once per process and refuses
        // registration outside them, so start from fresh registries.
        foreach ([\WP_Abilities_Registry::class, \WP_Ability_Categories_Registry::class] as $registry) {
            (new \ReflectionProperty($registry, 'instance'))->setValue(null, null);
        }
        add_action('wp_abilities_api_categories_init', [new MCPInit(), 'registerCategory']);
        add_action('wp_abilities_api_init', [AbilitiesRegistrar::class, 'register']);

        $ability = wp_get_ability('fluent-cart/list-orders');
        $annotations = $ability->get_meta_item('annotations');

        $this->assertTrue($annotations['readonly'], 'Read-only abilities must be marked readonly for WordPress.');
        $this->assertTrue($annotations['readOnlyHint'], 'Read-only abilities must expose the MCP hint for compatible adapters.');
    }

    // --- annotation mapping ------------------------------------------------

    public function test_readonly_keeps_wordpress_and_mcp_annotation_names(): void
    {
        $out = $this->invokePrivateMethod(AbilitiesRegistrar::class, 'mapAnnotations', [['readonly' => true]]);

        $this->assertTrue($out['readonly'], 'WordPress readonly annotation is preserved.');
        $this->assertTrue($out['readOnlyHint'], 'readonly maps to the MCP hint.');
        $this->assertFalse($out['destructiveHint'], 'Read-only tools are non-destructive for MCP clients.');
    }

    public function test_write_annotations_keep_wordpress_and_mcp_annotation_names(): void
    {
        $out = $this->invokePrivateMethod(AbilitiesRegistrar::class, 'mapAnnotations', [['readonly' => false, 'destructive' => true]]);

        $this->assertFalse($out['readonly'], 'WordPress readonly annotation is preserved.');
        $this->assertFalse($out['readOnlyHint'], 'readonly false maps through.');
        $this->assertTrue($out['destructive'], 'WordPress destructive annotation is preserved.');
        $this->assertTrue($out['destructiveHint'], 'destructive true maps through.');
    }

    public function test_unknown_annotation_key_is_dropped(): void
    {
        $out = $this->invokePrivateMethod(AbilitiesRegistrar::class, 'mapAnnotations', [['bulk' => true]]);

        $this->assertSame([], $out, 'Unknown annotation keys do not leak.');
    }

    // --- unknown-param rejection -------------------------------------------

    public function test_unknown_param_is_rejected_with_accepted_list(): void
    {
        // variation_id is a portable param this tool does not declare.
        $out = $this->invokePrivateMethod(
            AbilitiesRegistrar::class,
            'rejectUnknownParams',
            ['fluent-cart/list-products', ['variation_id' => 5], ['product_id']]
        );

        $this->assertInstanceOf(\WP_Error::class, $out, 'Undeclared param yields a WP_Error, not silent execution.');
        $this->assertSame('unknown_param', $out->get_error_code(), 'Stable error code for agent branching.');
        $this->assertStringContainsString('variation_id', $out->get_error_message(), 'Rejected param is named.');
        $this->assertStringContainsString('product_id', $out->get_error_message(), 'Accepted params are listed for self-correction.');

        $data = $out->get_error_data();
        $this->assertSame(['variation_id'], $data['fields'], 'error data names the unknown fields.');
        $this->assertSame(['product_id'], $data['accepted'], 'error data carries the accepted params.');
        $this->assertSame($data['accepted'], $data['allowed'], 'the same list under the key the other Fluent MCPs use.');
    }

    public function test_declared_params_are_not_rejected(): void
    {
        $out = $this->invokePrivateMethod(
            AbilitiesRegistrar::class,
            'rejectUnknownParams',
            ['fluent-cart/list-products', ['product_id' => 5], ['product_id']]
        );

        $this->assertNull($out, 'A fully declared param set passes.');
    }

    public function test_non_array_params_are_not_rejected(): void
    {
        $out = $this->invokePrivateMethod(
            AbilitiesRegistrar::class,
            'rejectUnknownParams',
            ['fluent-cart/get-store-info', null, []]
        );

        $this->assertNull($out, 'Non-array params (no-arg invocation) pass through.');
    }

    public function test_rejection_for_no_param_tool_says_it_takes_none(): void
    {
        $out = $this->invokePrivateMethod(
            AbilitiesRegistrar::class,
            'rejectUnknownParams',
            ['fluent-cart/get-store-info', ['per_page' => 10], []]
        );

        $this->assertInstanceOf(\WP_Error::class, $out, 'Param sent to a no-param tool is rejected.');
        $this->assertStringContainsString('takes no parameters', $out->get_error_message(), 'Message states the tool accepts nothing.');
    }

    // --- input validation errors ------------------------------------------

    public function test_a_schema_validation_error_has_the_json_shape_of_a_tool_error(): void
    {
        foreach ([\WP_Abilities_Registry::class, \WP_Ability_Categories_Registry::class] as $registry) {
            (new \ReflectionProperty($registry, 'instance'))->setValue(null, null);
        }
        add_action('wp_abilities_api_categories_init', [new MCPInit(), 'registerCategory']);
        add_action('wp_abilities_api_init', [AbilitiesRegistrar::class, 'register']);
        add_filter('wp_ability_validate_input', [AbilitiesRegistrar::class, 'structuredInputError'], 10, 3);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $out = wp_get_ability('fluent-cart/list-orders')->execute(['status' => 'bogus']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $error = json_decode($out->get_error_message(), true)['error'];
        $this->assertSame('invalid_param', $error['code']);
        $this->assertSame(['status'], $error['fields']);
        $this->assertFalse($error['retryable']);
        $this->assertStringContainsString('completed', $error['message'], 'The message lists the accepted values');

        $out = wp_get_ability('fluent-cart/list-reference-data')->execute([]);
        $error = json_decode($out->get_error_message(), true)['error'];
        $this->assertSame(['kinds'], $error['fields'], 'A missing required parameter is named');
    }

    public function test_the_validation_error_of_another_plugins_ability_is_left_alone(): void
    {
        $theirs = new \WP_Error('ability_invalid_input', 'Ability "other/tool" has invalid input.');

        $this->assertSame($theirs, AbilitiesRegistrar::structuredInputError($theirs, [], 'other/tool'));
        $this->assertTrue(AbilitiesRegistrar::structuredInputError(true, [], 'fluent-cart/list-orders'));
    }

    // --- tool exceptions ---------------------------------------------------

    public function test_a_query_exception_hides_its_sql_and_is_not_retryable(): void
    {
        $failing = function () {
            return \FluentCart\App\App::db()->table('fct_orders')->selectRaw('no_such_column')->first();
        };
        $wrapped = $this->invokePrivateMethod(AbilitiesRegistrar::class, 'wrapExecuteCallback', ['fluent-cart/list-orders', $failing, []]);

        // wpdb prints the failed query when WP_DEBUG is on: that must not reach the response either
        ob_start();
        $out = $wrapped([]);
        $printed = ob_get_clean();

        $this->assertSame('', $printed, 'Nothing is printed before the JSON response');

        $this->assertInstanceOf(\WP_Error::class, $out);
        $error = json_decode($out->get_error_message(), true)['error'];
        $this->assertSame('tool_failed', $error['code']);
        $this->assertFalse($error['retryable'], 'The same query fails again');
        $this->assertStringNotContainsString('no_such_column', $out->get_error_message(), 'No SQL reaches the agent');
        $this->assertStringNotContainsString('fct_orders', $out->get_error_message(), 'No table or schema name reaches the agent');
    }

    public function test_another_exception_keeps_its_message_and_stays_retryable(): void
    {
        $wrapped = $this->invokePrivateMethod(AbilitiesRegistrar::class, 'wrapExecuteCallback', ['fluent-cart/list-orders', function () {
            throw new \RuntimeException('gateway timed out');
        }, []]);

        $error = json_decode($wrapped([])->get_error_message(), true)['error'];
        $this->assertSame('gateway timed out', $error['message']);
        $this->assertTrue($error['retryable']);
    }

    public function test_a_php_error_hides_its_file_path_and_is_not_retryable(): void
    {
        $wrapped = $this->invokePrivateMethod(AbilitiesRegistrar::class, 'wrapExecuteCallback', ['fluent-cart/list-orders', function () {
            $typed = function (array $rows) {
                return $rows;
            };
            return $typed('not an array');
        }, []]);

        $out = $wrapped([]);
        $error = json_decode($out->get_error_message(), true)['error'];

        $this->assertSame('tool_failed', $error['code']);
        $this->assertFalse($error['retryable'], 'A code error fails the same way on retry');
        $this->assertStringNotContainsString(basename(__FILE__), $out->get_error_message(), 'No file path reaches the agent');
        $this->assertStringNotContainsString('.php', $out->get_error_message(), 'No file path reaches the agent');
    }

    // --- clamped per_page warnings -----------------------------------------

    public function test_clamped_per_page_appends_warning(): void
    {
        $result = ['summary' => 'x', 'data' => [], 'meta' => ['page' => ['per_page' => 100]]];

        $out = $this->invokePrivateMethod(AbilitiesRegistrar::class, 'annotateClampedPerPage', [$result, ['per_page' => 500]]);

        $this->assertArrayHasKey('warnings', $out['meta'], 'Clamp is surfaced as a runtime warning.');
        $this->assertCount(1, $out['meta']['warnings'], 'Exactly one clamp warning is appended.');
        $this->assertStringContainsString('500', $out['meta']['warnings'][0], 'Warning names the requested per_page.');
        $this->assertStringContainsString('100', $out['meta']['warnings'][0], 'Warning names what was actually returned.');
    }

    public function test_existing_warnings_are_preserved(): void
    {
        $result = [
            'summary' => 'x',
            'data'    => [],
            'meta'    => ['warnings' => ['pre-existing'], 'page' => ['per_page' => 100]],
        ];

        $out = $this->invokePrivateMethod(AbilitiesRegistrar::class, 'annotateClampedPerPage', [$result, ['per_page' => 500]]);

        $this->assertContains('pre-existing', $out['meta']['warnings'], 'Prior warnings survive.');
        $this->assertCount(2, $out['meta']['warnings'], 'The new warning is appended, not replaced.');
    }

    public function test_per_page_within_cap_is_not_warned(): void
    {
        $result = ['summary' => 'x', 'data' => [], 'meta' => ['page' => ['per_page' => 100]]];

        $out = $this->invokePrivateMethod(AbilitiesRegistrar::class, 'annotateClampedPerPage', [$result, ['per_page' => 50]]);

        $this->assertSame($result, $out, 'A request within the cap is untouched.');
    }

    public function test_error_result_is_untouched(): void
    {
        $err = new \WP_Error('some_error', 'boom');

        $out = $this->invokePrivateMethod(AbilitiesRegistrar::class, 'annotateClampedPerPage', [$err, ['per_page' => 500]]);

        $this->assertSame($err, $out, 'A WP_Error passes through unchanged.');
    }
}
