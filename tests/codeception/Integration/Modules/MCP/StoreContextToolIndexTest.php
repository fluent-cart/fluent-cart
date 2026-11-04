<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\AbilitiesRegistrar;
use FluentCart\App\Modules\MCP\Tools\ContextTools;
use Tests\Support\DatabaseTestCase;

/**
 * get-store-context ships a tool_index: a task → tool routing table so an agent
 * picks the right ability among ~30 without trial and error.
 *
 * The load-bearing guarantee is COMPLETENESS — the index is derived from the
 * live registry, so this test fails the moment a newly registered core tool is
 * not annotated, catching drift at CI time instead of leaving an agent unable to
 * discover a tool.
 */
class StoreContextToolIndexTest extends DatabaseTestCase
{
    private function flattenIndex(array $toolIndex): array
    {
        $flat = [];
        foreach ($toolIndex as $group => $tools) {
            foreach ($tools as $short => $hint) {
                $flat[$short] = $group;
            }
        }
        return $flat;
    }

    public function test_tool_index_covers_every_registered_ability(): void
    {
        $res  = ContextTools::getContext([]);
        $data = $res['data'];

        $this->assertArrayHasKey('tool_index', $data);
        $flat = $this->flattenIndex($data['tool_index']);

        foreach (array_keys(AbilitiesRegistrar::getDefinitions()) as $name) {
            $short = str_replace('fluent-cart/', '', $name);
            $this->assertArrayHasKey($short, $flat, "tool_index is missing the registered ability '{$short}' — add a hint in ContextTools::toolIndex().");
        }

        // No tool should fall through to the uncurated 'other' bucket.
        $this->assertArrayNotHasKey('other', $data['tool_index'], 'Every core tool should have a curated category, not fall into "other".');
    }

    public function test_new_and_key_tools_are_categorized(): void
    {
        $data = ContextTools::getContext([])['data'];
        $flat = $this->flattenIndex($data['tool_index']);

        // The new ledger tool is discoverable under find.
        $this->assertSame('find', $flat['list-transactions'] ?? null);
        // A representative from each intent bucket.
        $this->assertSame('discovery', $flat['get-store-context'] ?? null);
        $this->assertSame('analytics', $flat['get-sales-report'] ?? null);
        $this->assertSame('write', $flat['refund-order'] ?? null);
    }
}
