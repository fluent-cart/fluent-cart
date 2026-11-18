<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\AbilitiesRegistrar;
use FluentCart\App\Modules\MCP\MCPInit;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use Tests\Support\DatabaseTestCase;

/**
 * The "Connect with one URL" card on the MCP settings screen reads FluentHub through two
 * filters (fluent-toolkit docs/mcp-product-connect.md) and never through its classes.
 */
class HubConnectTest extends DatabaseTestCase
{
    private function hubState(array $overrides = []): array
    {
        return array_replace_recursive([
            'version'          => 1,
            'hub'              => ['enabled' => true, 'supported' => true, 'reason' => '', 'endpoint_url' => 'https://store.test/wp-json/fluent-kit/mcp', 'server_name' => 'fluent-store-test'],
            'sign_in'          => ['enabled' => true, 'available' => true, 'reason' => '', 'https' => true],
            'product'          => ['known' => true, 'included' => true],
            'ready'            => true,
            'user_can_connect' => true,
            'can_manage'       => true,
            'manage_url'       => 'https://store.test/wp-admin/admin.php?page=fluent-hub#/mcp',
            'profile_url'      => 'https://store.test/wp-admin/profile.php',
            'products'         => [
                ['slug' => 'fluent-cart', 'name' => 'FluentCart', 'active' => true, 'mcp_enabled' => true, 'included' => true, 'served' => true, 'tools_count' => 34, 'can_enable' => false],
                ['slug' => 'fluent-crm', 'name' => 'FluentCRM', 'active' => true, 'mcp_enabled' => false, 'included' => true, 'served' => false, 'tools_count' => 31, 'can_enable' => true],
            ],
            'clients'          => [
                ['key' => 'claude-code', 'label' => 'Claude Code', 'note' => 'Run this in a terminal.', 'snippet' => 'claude mcp add --transport http fluent-store-test https://store.test/wp-json/fluent-kit/mcp'],
            ],
        ], $overrides);
    }

    private function withState($state, callable $run)
    {
        $filter = function () use ($state) {
            return $state;
        };
        add_filter('fluent_kit/mcp_hub_state', $filter);
        try {
            return $run();
        } finally {
            remove_filter('fluent_kit/mcp_hub_state', $filter);
        }
    }

    public function test_no_fluenthub_or_an_unknown_contract_version_gives_no_card(): void
    {
        $this->assertNull(MCPInit::hubState(), 'FluentHub is not active: nothing answers the filter');

        $this->assertNull($this->withState($this->hubState(['version' => 2]), [MCPInit::class, 'hubState']), 'A version this code does not know is not guessed at');
        $this->assertNull($this->withState('yes', [MCPInit::class, 'hubState']));
    }

    public function test_a_ready_hub_is_passed_to_the_screen_with_its_clients_as_given(): void
    {
        $hub = $this->withState($this->hubState(), [MCPInit::class, 'hubState']);

        $this->assertTrue($hub['ready']);
        $this->assertTrue($hub['user_can_connect']);
        $this->assertSame('https://store.test/wp-json/fluent-kit/mcp', $hub['endpoint_url']);
        $this->assertSame([['key' => 'claude-code', 'label' => 'Claude Code', 'note' => 'Run this in a terminal.', 'snippet' => 'claude mcp add --transport http fluent-store-test https://store.test/wp-json/fluent-kit/mcp']], $hub['clients']);
        $this->assertSame(['slug' => 'fluent-crm', 'name' => 'FluentCRM', 'served' => false, 'can_enable' => true, 'tools_count' => 31], $hub['products'][1]);
    }

    public function test_malformed_parts_of_the_state_do_not_break_the_screen(): void
    {
        $state = $this->hubState();
        $state['product'] = new \stdClass();
        $this->assertNull($this->withState($state, [MCPInit::class, 'hubState']), 'A block of the wrong type means no card');

        $state = $this->hubState();
        $state['clients'][] = ['key' => 'odd', 'snippet' => ['not', 'text']];
        $hub = $this->withState($state, [MCPInit::class, 'hubState']);
        $this->assertSame(['claude-code'], array_column($hub['clients'], 'key'), 'A snippet that is not text is left out');
    }

    public function test_the_reasons_reach_the_screen(): void
    {
        $off = $this->withState($this->hubState(['ready' => false, 'hub' => ['enabled' => false, 'reason' => 'off'], 'sign_in' => ['available' => false, 'reason' => 'hub_off']]), [MCPInit::class, 'hubState']);
        $this->assertFalse($off['ready']);
        $this->assertSame('off', $off['hub_reason']);

        $permalinks = $this->withState($this->hubState(['ready' => false, 'sign_in' => ['available' => false, 'reason' => 'plain_permalinks']]), [MCPInit::class, 'hubState']);
        $this->assertSame('plain_permalinks', $permalinks['sign_in_reason']);

        $leftOut = $this->withState($this->hubState(['ready' => false, 'product' => ['included' => false]]), [MCPInit::class, 'hubState']);
        $this->assertFalse($leftOut['included']);

        $state = $this->hubState();
        $state['clients'] = [['key' => 'no-snippet']];
        $noClients = $this->withState($state, [MCPInit::class, 'hubState']);
        $this->assertFalse($noClients['ready'], 'Ready needs something to show');
    }

    /**
     * @dataProvider enableResults
     */
    public function test_turning_on_maps_each_fluenthub_answer($answer, bool $ok, int $status): void
    {
        $seen   = [];
        $filter = function ($result, $caller, $target) use ($answer, &$seen) {
            $seen = [$caller, $target];
            return $answer;
        };
        add_filter('fluent_kit/mcp_hub_enable', $filter, 10, 3);
        try {
            $result = MCPInit::enableHub('fluent-crm');
        } finally {
            remove_filter('fluent_kit/mcp_hub_enable', $filter, 10);
        }

        $this->assertSame(['fluent-cart', 'fluent-crm'], $seen, 'FluentCart names itself and the target');
        $this->assertSame($ok, $result['ok']);
        $this->assertSame($status, $result['status']);
        $this->assertNotSame('', $result['message']);
    }

    public function enableResults(): array
    {
        return [
            'FluentHub not active' => [null, false, 422],
            'done'                 => [['status' => 'done'], true, 200],
            'forbidden'            => [['status' => 'forbidden', 'reason' => 'capability'], false, 403],
            'refused by a plugin'  => [['status' => 'forbidden', 'reason' => 'product_refused', 'message' => 'Not allowed here.'], false, 403],
            'unavailable'          => [['status' => 'unavailable', 'reason' => 'adapter_outdated'], false, 422],
        ];
    }

    public function test_a_store_user_is_admitted_to_fluentcart_only_and_only_while_mcp_is_on(): void
    {
        $admin      = self::factory()->user->create_and_get(['role' => 'administrator']);
        $subscriber = self::factory()->user->create_and_get(['role' => 'subscriber']);
        wp_set_current_user($admin->ID);
        $wasOn = PermissionGate::isEnabled();

        try {
            PermissionGate::setEnabled(true);
            $this->assertTrue(MCPInit::vouchForStoreUser(false, 'fluent-cart', $admin), 'A user FluentCart\'s own MCP gate accepts');
            $this->assertFalse(MCPInit::vouchForStoreUser(false, 'fluent-cart', $subscriber), 'No FluentCart role');
            $this->assertFalse(MCPInit::vouchForStoreUser(false, 'fluent-crm', $admin), 'Another plugin is not ours to answer for');
            $this->assertTrue(MCPInit::vouchForStoreUser(true, 'fluent-crm', $subscriber), 'A yes we are handed stays a yes');

            PermissionGate::setEnabled(false);
            $this->assertFalse(MCPInit::vouchForStoreUser(false, 'fluent-cart', $admin), 'FluentCart MCP is off');
        } finally {
            PermissionGate::setEnabled($wasOn);
        }
    }

    public function test_the_connection_name_carries_the_site_and_fits_the_tool_name_limit(): void
    {
        $this->assertSame('cart-cart-lab', MCPInit::clientServerName('https://cart.lab'));
        $this->assertSame('cart-shop-com', MCPInit::clientServerName('https://www.shop.com/'), 'www adds nothing');

        $this->assertNotSame(MCPInit::clientServerName('http://localhost:8080'), MCPInit::clientServerName('http://localhost:8081'), 'Two local sites on one host differ by port');

        $first  = MCPInit::clientServerName('https://my-long-store-name.example.com');
        $second = MCPInit::clientServerName('https://my-long-store-name.example.org/shop');
        $this->assertNotSame($first, $second, 'Two long addresses with the same start still differ');

        // An AI app names a tool mcp__{connection}__{tool} and refuses more than 64 characters.
        $longest = max(array_map('strlen', array_keys(AbilitiesRegistrar::getDefinitions())));
        foreach (['https://cart.lab', 'http://localhost:8080', 'https://my-long-store-name.example.com', 'https://a.b.c.d.e.f.example.co.uk/sub/dir'] as $url) {
            $this->assertLessThanOrEqual(64, strlen('mcp__' . MCPInit::clientServerName($url) . '__') + $longest, $url);
        }
    }
}
