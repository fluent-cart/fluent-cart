<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\App;
use FluentCart\App\Modules\MCP\Support\AdvancedSearch;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\Framework\Support\Arr;

/**
 * Advanced-search discovery.
 *
 * The admin UI's advanced filter catalogs are large (30+ properties across
 * entities, each with operators, options and value formats) — inlining them
 * into every list tool's input_schema would bloat every session's context.
 * Instead the list tools carry ONE lean advanced_filters parameter, and this
 * tool serves the full per-entity reference on demand: the agent fetches the
 * schema for the one entity it is about to search, exactly like the existing
 * get-store-context → list-reference-data progressive-disclosure split.
 */
class SearchTools
{
    public static function definitions()
    {
        $entities = AdvancedSearch::entityNames();

        return [
            'fluent-cart/get-search-schema' => [
                'label'       => 'Get Advanced Search Schema',
                'description' => sprintf(
                    'The reference for the advanced_filters parameter of the list tools. It is the same condition-group search the admin "advanced filter" UI offers. Returns every filterable property of one entity (operators, value types, enum options), the payload format, and a worked example. Call this once before passing advanced_filters to a list tool. Entities: %1$s. Requires FluentCart Pro.',
                    implode(', ', $entities)
                ),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entity' => [
                            'type'        => 'string',
                            'enum'        => $entities,
                            'description' => 'The entity whose search schema to return.',
                        ],
                    ],
                    'required' => ['entity'],
                ],
                'execute_callback'    => [self::class, 'getSearchSchema'],
                'permission_callback' => function () {
                    return PermissionGate::canAny(PermissionGate::readRoleCaps());
                },
                'annotations' => ['readonly' => true],
            ],
        ];
    }

    public static function getSearchSchema($params = [])
    {
        $entity   = (isset($params['entity']) && is_scalar($params['entity'])) ? (string) $params['entity'] : '';
        $entities = AdvancedSearch::entities();

        if (!isset($entities[$entity])) {
            return MCPHelper::error(
                'unknown_entity',
                sprintf(
                    'Unknown entity "%1$s". Valid entities: %2$s.',
                    $entity,
                    implode(', ', array_keys($entities))
                ),
                ['entities' => array_keys($entities)]
            );
        }

        // Schema visibility follows data visibility: if the caller can't read
        // the entity's rows, don't hand it the entity's search surface either.
        $permission = Arr::get($entities[$entity], 'permission');
        if ($permission && !PermissionGate::can($permission)) {
            return MCPHelper::error(
                'forbidden',
                sprintf(
                    'Your role cannot read %1$s (requires %2$s).',
                    $entity,
                    $permission
                ),
                ['required_permission' => $permission]
            );
        }

        // Fail here, not after the agent has built a filter that list tools
        // will reject anyway.
        if (!App::isProActive()) {
            return MCPHelper::error(
                'pro_required',
                'Advanced search requires FluentCart Pro. The named filters on the list tools remain available.',
                ['entity' => $entity]
            );
        }

        $schema = AdvancedSearch::schemaFor($entity);
        if (is_wp_error($schema)) {
            return $schema;
        }

        $count = isset($schema['properties']) ? count($schema['properties']) : 0;

        return MCPHelper::envelope(
            sprintf(
                $count === 1 ? '%1$d filterable property for %2$s — pass conditions via advanced_filters on %3$s.' : '%1$d filterable properties for %2$s — pass conditions via advanced_filters on %3$s.',
                $count,
                $entity,
                Arr::get($entities[$entity], 'list_tool', 'the list tool')
            ),
            $schema
        );
    }
}
