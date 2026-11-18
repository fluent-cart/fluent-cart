<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\AbilitiesRegistrar;
use FluentCart\App\Modules\MCP\MCPInit;
use Tests\Support\DatabaseTestCase;

/**
 * The MCP adapter sends a tool result as JSON text and again as structuredContent,
 * and some AI apps give the model both. FluentCart's own endpoint sends it once, as text.
 */
class SingleResultCopyTest extends DatabaseTestCase
{
    // The REST route of FluentCart's own MCP server, as MCPInit::getEndpointUrl() builds it
    private function route(): string
    {
        return '/fluent-cart/mcp';
    }

    private function send(MCPInit $init, $data, string $route)
    {
        $out = $init->sendResultOnce(new \WP_REST_Response($data), null, new \WP_REST_Request('POST', $route));

        return json_decode(wp_json_encode($out->get_data()), true);
    }

    private function toolResult(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'content'           => [['type' => 'text', 'text' => '{"summary":"x","data":{}}']],
            'structuredContent' => ['summary' => 'x', 'data' => new \stdClass()],
        ]];
    }

    public function test_a_tool_result_on_the_fluentcart_route_keeps_only_its_text(): void
    {
        $out = $this->send(new MCPInit(), $this->toolResult(), $this->route());

        $this->assertArrayNotHasKey('structuredContent', $out['result']);
        $this->assertSame('{"summary":"x","data":{}}', $out['result']['content'][0]['text']);
    }

    public function test_a_batch_is_handled_message_by_message(): void
    {
        $out = $this->send(new MCPInit(), [$this->toolResult(), $this->toolResult()], $this->route());

        $this->assertArrayNotHasKey('structuredContent', $out[0]['result']);
        $this->assertArrayNotHasKey('structuredContent', $out[1]['result']);
    }

    public function test_other_responses_are_left_alone(): void
    {
        $init = new MCPInit();

        // Another route, for example the FluentHub router that ran a FluentCart tool
        $out = $this->send($init, $this->toolResult(), '/fluent-kit/mcp');
        $this->assertArrayHasKey('structuredContent', $out['result']);

        // A result with no text to fall back on
        $noText = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [], 'structuredContent' => ['a' => 1]]];
        $this->assertArrayHasKey('structuredContent', $this->send($init, $noText, $this->route())['result']);

        // tools/list and errors have no structuredContent to drop and keep their shape
        $list = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [['name' => 'fluent-cart-list-orders']]]];
        $this->assertSame($list, $this->send($init, $list, $this->route()));
        $error = ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'bad']];
        $this->assertSame($error, $this->send($init, $error, $this->route()));
    }

    public function test_the_response_hook_is_added_only_after_a_fluentcart_tool_ran(): void
    {
        $init   = new MCPInit();
        $server = function ($id) {
            return new class($id) {
                private $id;
                public function __construct($id) { $this->id = $id; }
                public function get_server_id() { return $this->id; }
            };
        };
        $armed = function () use ($init) {
            return (bool) has_filter('rest_post_dispatch', [$init, 'sendResultOnce']);
        };

        $this->assertSame(['r' => 1], $init->armSingleResultCopy(['r' => 1], [], 'x', null, $server('fluent-crm')), 'The tool result is returned as is');
        $this->assertFalse($armed(), 'Another product\'s tool does not add the hook');

        add_filter('fluent_cart/mcp_single_result_copy', '__return_false');
        $init->armSingleResultCopy([], [], 'x', null, $server('fluent-cart'));
        $this->assertFalse($armed(), 'A site can turn it off');
        remove_filter('fluent_cart/mcp_single_result_copy', '__return_false');

        $init->armSingleResultCopy([], [], 'x', null, $server('fluent-cart'));
        $this->assertTrue($armed());
        remove_filter('rest_post_dispatch', [$init, 'sendResultOnce'], 20);
    }

    public function test_no_tool_declares_an_output_schema(): void
    {
        // A tool with an output schema must send structuredContent, so its result could not go out once.
        foreach (AbilitiesRegistrar::getDefinitions() as $name => $definition) {
            $this->assertArrayNotHasKey('output_schema', $definition, $name);
        }
    }
}
