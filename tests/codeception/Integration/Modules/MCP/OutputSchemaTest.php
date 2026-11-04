<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\AbilitiesRegistrar;
use Tests\Support\DatabaseTestCase;

/**
 * Flagship read/report tools advertise an output_schema so an agent knows the
 * response shape — envelope, money object, units — up front, without a probe
 * call. The adapter forwards it verbatim and does NOT validate results against
 * it, so it is documentation: it must be a well-formed, permissive JSON Schema
 * (declared keys optional, since fields[] and summary_only prune the payload).
 */
class OutputSchemaTest extends DatabaseTestCase
{
    private function schemaFor(string $ability): array
    {
        $defs = AbilitiesRegistrar::getDefinitions();
        return isset($defs[$ability]['output_schema']) ? $defs[$ability]['output_schema'] : [];
    }

    public function test_flagship_tools_declare_the_envelope(): void
    {
        $tools = [
            'fluent-cart/get-sales-report',
            'fluent-cart/query-orders',
            'fluent-cart/query-products',
            'fluent-cart/query-customers',
            'fluent-cart/query-subscriptions',
            'fluent-cart/query-sources',
            'fluent-cart/list-transactions',
        ];

        foreach ($tools as $ability) {
            $schema = $this->schemaFor($ability);
            $this->assertNotEmpty($schema, "{$ability} declares an output_schema");
            $this->assertSame('object', $schema['type']);

            // Adapter validity: properties + required must be arrays (McpToolValidator).
            $this->assertIsArray($schema['properties']);
            $this->assertIsArray($schema['required']);

            // The canonical envelope.
            $this->assertArrayHasKey('summary', $schema['properties']);
            $this->assertArrayHasKey('data', $schema['properties']);
            $this->assertArrayHasKey('meta', $schema['properties']);
            $this->assertSame(['summary', 'data', 'meta'], $schema['required']);

            // Data keys are NOT required — fields[]/summary_only may prune them.
            $this->assertArrayNotHasKey('required', $schema['properties']['data']);
        }
    }

    public function test_money_object_shape_is_declared(): void
    {
        $schema = $this->schemaFor('fluent-cart/get-sales-report');
        // The money metrics are declared once via additionalProperties (rather than
        // inlining the money object per metric) to keep the schema token-lean.
        $money = $schema['properties']['data']['properties']['metrics']['additionalProperties'];

        $this->assertSame('object', $money['type']);
        foreach (['amount', 'amount_cents', 'currency', 'display'] as $key) {
            $this->assertArrayHasKey($key, $money['properties'], "money object declares {$key}");
        }
    }

    public function test_transactions_summary_totals_declare_money(): void
    {
        $schema = $this->schemaFor('fluent-cart/list-transactions');
        $total  = $schema['properties']['data']['properties']['summary']['properties']['amount_by_currency']['items']['properties']['total'];

        $this->assertArrayHasKey('amount_cents', $total['properties']);
        $this->assertArrayHasKey('display', $total['properties']);
    }

    public function test_meta_schema_documents_shared_keys(): void
    {
        $meta = $this->schemaFor('fluent-cart/query-orders')['properties']['meta'];
        foreach (['schema_version', 'currency', 'warnings'] as $key) {
            $this->assertArrayHasKey($key, $meta['properties']);
        }
        // Per-tool meta keys injected for the query family.
        $this->assertArrayHasKey('page', $meta['properties']);
        $this->assertArrayHasKey('mode', $meta['properties']);
    }
}
