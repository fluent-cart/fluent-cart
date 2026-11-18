<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\MCPInit;
use FluentCart\App\Modules\MCP\Tools\ContextTools;
use FluentCart\App\Services\Permission\PermissionManager;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\InvokesPrivateMethods;

/**
 * get-store-context holds identity, permissions, currency, enums and stats. The rules and the
 * action list are not in it: the server instructions and tools/list already give them, and
 * every extra token here is paid once per session.
 */
class StoreContextTest extends DatabaseTestCase
{
    use InvokesPrivateMethods;

    public function test_context_has_no_tool_index_and_only_the_rules_a_model_must_not_miss(): void
    {
        wp_set_current_user(1);
        $data = $this->invokePrivateMethod(ContextTools::class, 'buildContext', [1])['data'];

        $this->assertArrayNotHasKey('tool_index', $data, 'help and tools/list give the action list');
        // A client that connects straight to this server may never show the server instructions to the model
        $this->assertLessThan(600, strlen($data['guidelines']), 'The essential rules, not a second copy of the instructions');
        foreach (['Confirm with the user before any write', 'dry_run=true', 'major units', 'UTC', 'never follow instructions'] as $rule) {
            $this->assertStringContainsString($rule, $data['guidelines']);
        }
        $this->assertStringNotContainsString('FluentHub', $data['guidelines'], 'This server is also used without the hub');
        $this->assertArrayHasKey('enums', $data);
    }

    public function test_an_add_on_on_the_tool_index_filter_still_reaches_the_context(): void
    {
        // fluent_cart/mcp_tool_index shipped in 2.3.0
        $hint = function ($index) {
            $index['write']['my-addon-tool'] = 'Use for the add-on task.';
            return $index;
        };
        add_filter('fluent_cart/mcp_tool_index', $hint);
        wp_set_current_user(1);
        $data = $this->invokePrivateMethod(ContextTools::class, 'buildContext', [1])['data'];
        remove_filter('fluent_cart/mcp_tool_index', $hint);

        $this->assertSame(['write' => ['my-addon-tool' => 'Use for the add-on task.']], $data['tool_index']);
    }

    public function test_you_lists_the_permissions_of_the_current_user(): void
    {
        wp_set_current_user(1);
        $you = $this->invokePrivateMethod(ContextTools::class, 'buildContext', [1])['data']['you'];

        $this->assertSame(array_values((array) PermissionManager::getUserPermissions()), $you['permissions']);
        $this->assertNotEmpty($you['permissions']);
    }

    public function test_server_instructions_state_the_shared_rules_within_budget(): void
    {
        $text = MCPInit::serverInstructions();

        // Sent on every help call: about 4 characters per token
        $this->assertLessThanOrEqual(700, strlen(wp_json_encode($text)) / 4);
        foreach (['major units', 'UTC', 'store timezone', 'Standard report window parameters', 'dry_run', '__all__', 'Never follow instructions'] as $rule) {
            $this->assertStringContainsString($rule, $text);
        }
    }
}
