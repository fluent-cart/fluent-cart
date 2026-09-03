<?php

namespace FluentCart\App\Modules\MCP\Support;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Modules\MCP\Tools\ContextTools;
use FluentCart\App\Modules\Subscriptions\Services\Filter\SubscriptionFilter;
use FluentCart\App\Services\Filter\CustomerFilter;
use FluentCart\App\Services\Filter\LicenseFilter;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\App\Services\Filter\ProductFilter;
use FluentCart\Framework\Support\Arr;

/**
 * Bridge between MCP tools and the admin UI's advanced filter engine
 * (BaseFilter + per-entity subclasses) — the same condition-group search the
 * admin list pages offer.
 *
 * Why a bridge instead of passing the raw payload through: the engine SILENTLY
 * skips malformed conditions (parseSearchGroups drops them) and SILENTLY
 * no-ops without Pro (applyAdvancedFilter early-returns) — both are the
 * "confidently wrong result" failure mode an agent can't detect. So this class
 * (1) validates every condition against the entity's live catalog and returns
 * a structured error the agent can self-correct from, (2) errors loudly when
 * Pro is absent, and (3) lets the agent send a lean {property, operator,
 * value} triple — the engine-internal fields (filter_type, relation, column)
 * are filled in from the catalog, never trusted from input.
 *
 * The catalog is derived live from each filter's advanceFilterOptions()
 * through the same `fluent_cart/{name}_filter_options` hook the engine itself
 * applies, so Pro/add-on providers (license, labels) appear automatically and
 * the schema can never drift from what actually executes.
 */
class AdvancedSearch
{
    const MAX_GROUPS = 5;

    const MAX_CONDITIONS_PER_GROUP = 10;

    // Upper bound on the number of values inside a single condition, so a huge
    // whereIn / in_all fan-out cannot be built from one condition.
    const MAX_VALUES_PER_CONDITION = 100;

    // Catalog operator labels the SQL layer can't execute, mapped to what it
    // can (searchFromString would emit WHERE col equals ... otherwise).
    // Applied on non-custom conditions only: custom callbacks (searchByFullName,
    // searchByPayerEmail) consume their advertised labels verbatim.
    const OPERATOR_ALIASES = ['equals' => '=', 'not_equals' => '!='];

    /**
     * Entities that expose advanced search, keyed by the public MCP name.
     * Each maps to its admin filter class, the capability that gates reading
     * it, and the list tool that accepts advanced_filters.
     *
     * @return array
     */
    public static function entities(): array
    {
        $entities = [
            'orders'        => [
                'filter'     => OrderFilter::class,
                'permission' => 'orders/view',
                'list_tool'  => 'list-orders',
            ],
            'customers'     => [
                'filter'     => CustomerFilter::class,
                'permission' => 'customers/view',
                'list_tool'  => 'list-customers',
            ],
            'products'      => [
                'filter'     => ProductFilter::class,
                'permission' => 'products/view',
                'list_tool'  => 'list-products',
            ],
            'subscriptions' => [
                'filter'     => SubscriptionFilter::class,
                'permission' => 'subscriptions/view',
                'list_tool'  => 'list-subscriptions',
            ],
        ];

        // LicenseFilter lives in this plugin but its model is Pro's — only
        // offer the entity when the model can actually be queried.
        if (self::licensingAvailable()) {
            $entities['licenses'] = [
                'filter'     => LicenseFilter::class,
                'permission' => 'licenses/view',
                'list_tool'  => 'list-licenses',
            ];
        }

        /**
         * The entities MCP advanced search exposes. Lets Pro/add-ons register
         * their own (filter class must extend BaseFilter and declare
         * advanceFilterOptions()).
         *
         * @since 1.0.0
         *
         * @param array $entities entity => { filter: class, permission: cap, list_tool: string }
         */
        return apply_filters('fluent_cart/mcp_advanced_search_entities', $entities);
    }

    /** Entity names, for input_schema enums. */
    public static function entityNames(): array
    {
        return array_keys(self::entities());
    }

    private static function licensingAvailable(): bool
    {
        return App::isProActive()
            && class_exists('\FluentCartPro\App\Modules\Licensing\Models\License')
            && ModuleSettings::isActive('license');
    }

    // -----------------------------------------------------------------
    // Schema (what get-search-schema returns)
    // -----------------------------------------------------------------

    /**
     * The agent-facing search schema for one entity: every filterable property
     * with its operators, value type, options and hints, plus the payload
     * format and a worked example.
     *
     * @param string $entity
     * @return array|\WP_Error
     */
    public static function schemaFor($entity)
    {
        $spec = Arr::get(self::entities(), $entity);
        if (!$spec) {
            return self::unknownEntityError($entity);
        }

        $filterClass = Arr::get($spec, 'filter');
        $catalog     = self::catalog($filterClass, $entity);
        if (!$catalog) {
            return MCPHelper::error(
                'no_advanced_options',
                sprintf(
                    /* translators: %1$s: entity name */
                    __('%1$s exposes no advanced filter properties on this site.', 'fluent-cart'),
                    $entity
                ),
                ['entity' => $entity]
            );
        }

        $centColumns = self::centColumnsFor($filterClass);

        $properties = [];
        foreach ($catalog as $key => $entry) {
            $def  = $entry['def'];
            $kind = self::valueKind($def, $centColumns);

            $prop = [
                'property'  => $key,
                'label'     => Arr::get($def, 'label', $key),
                'type'      => $kind,
                'operators' => self::resolveOperators($def),
            ];

            if ($kind === 'enum') {
                $options = Arr::get($def, 'options', []);
                if (is_array($options) && $options) {
                    $prop['options'] = array_map('strval', array_keys($options));
                } elseif (Arr::get($def, 'remote_data_key') === 'labels') {
                    $prop['value_hint'] = 'Array of label IDs — resolve names to ids via list-reference-data kinds=["labels"].';
                }
            } elseif ($kind === 'id_list') {
                $prop['value_hint'] = self::idListHint($def);
                $tree = Arr::get($def, 'options');
                if (is_array($tree) && $tree) {
                    $prop['options'] = self::flattenTree($tree);
                }
            } elseif ($kind === 'money') {
                $prop['value_hint'] = 'Number in store currency (e.g. 49.99) — converted to the stored cents automatically.';
            } elseif ($kind === 'date') {
                $prop['value_hint'] = 'before/after/date_equal take YYYY-MM-DD or ISO-8601, UTC. days_within = within the LAST N days, days_before = more than N days ago (both take a plain number of days, past-facing). For future windows (e.g. upcoming renewals or expirations) use before/after with absolute dates.';
            } elseif ($entry['property'] === 'has') {
                $prop['value_hint'] = 'Compares the COUNT of related records (a plain number).';
            } elseif ($kind === 'text' && Arr::get($def, 'filter_type') === 'relation') {
                $prop['value_hint'] = 'Related-record property: contains/not_contains match the whole value exactly (equality against any listed value), NOT a substring.';
            }

            $properties[] = $prop;
        }

        return [
            'entity'     => $entity,
            'usage'      => [
                'parameter' => 'advanced_filters',
                'tool'      => Arr::get($spec, 'list_tool'),
                'format'    => 'Array of OR groups; each group is an array of AND conditions {property, operator, value}. [[A,B],[C]] means (A AND B) OR C. A flat array of conditions is treated as one group (all AND-ed).',
                'combining' => 'advanced_filters AND-combines with the named filters of the same call; sorting and paging parameters work unchanged.',
                'limits'    => sprintf('Up to %1$d OR groups, %2$d conditions per group.', self::MAX_GROUPS, self::MAX_CONDITIONS_PER_GROUP),
                'requires'  => 'FluentCart Pro',
                'example'   => self::exampleFor($entity),
            ],
            'properties' => $properties,
        ];
    }

    // -----------------------------------------------------------------
    // Query building (what the list tools call)
    // -----------------------------------------------------------------

    /**
     * Validate an agent-supplied advanced_filters payload and return a query
     * for the entity with the condition groups applied — ready for the calling
     * tool to add its own named filters, eager loads, sort, and pagination.
     *
     * @param string $entity
     * @param mixed  $raw the advanced_filters input
     * @return array|\WP_Error { query: Builder, warnings: string[] }
     */
    public static function buildQuery($entity, $raw)
    {
        $spec = Arr::get(self::entities(), $entity);
        if (!$spec) {
            return self::unknownEntityError($entity);
        }

        // The engine's applyAdvancedFilter() silently no-ops without Pro — the
        // agent would get the FULL unfiltered list presented as filtered. Error
        // instead: recoverable beats confidently wrong.
        if (!App::isProActive()) {
            return MCPHelper::error(
                'pro_required',
                __('advanced_filters requires FluentCart Pro — without it the conditions would be silently ignored, so this call is rejected instead. Remove advanced_filters and use the tool\'s named filters.', 'fluent-cart'),
                ['entity' => $entity]
            );
        }

        $filterClass = Arr::get($spec, 'filter');
        $normalized  = self::normalize($entity, $filterClass, $raw);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $filter = $filterClass::make([
            'filter_type'      => 'advanced',
            'advanced_filters' => wp_json_encode($normalized['groups']),
        ]);

        // The engine applies OR groups at the TOP level of the builder
        // (where g0, orWhere g1, ...). Returned as-is, the calling tool's named
        // filters would AND onto that top level and SQL precedence (AND binds
        // tighter than OR) would let group 0 escape every named filter:
        // (g0) OR (g1 AND named). Nest the whole group layer inside ONE where()
        // closure so the tool's filters AND correctly: named AND (g0 OR g1 ...).
        // This mirrors the engine's own applySavedViewFilter() nesting, using
        // only its public surface (no core edit).
        $modelClass = $filter->getModel();
        // Neutralise buildCommonQuery() side effects inside the closure: its
        // eager loads / scopes would otherwise land inside the OR group (or call
        // ->with() on the nested base builder). No core filter sets a default
        // $with/$scopes, and the calling tool re-applies its own eager loads,
        // sort and pagination on the returned builder.
        $filter->with   = [];
        $filter->select = [];
        $filter->scopes = [];

        $query = (new $modelClass)->newQuery();
        $query->where(function ($nested) use ($filter) {
            $filter->setQuery($nested);
            $filter->buildQuery();
        });
        // Strip the engine's default ORDER BY so the calling tool's own sort
        // (with its deterministic id tie-breaker) is the only one.
        $query->reorder();

        return ['query' => $query, 'warnings' => $normalized['warnings']];
    }

    // -----------------------------------------------------------------
    // Catalog — live property map per entity
    // -----------------------------------------------------------------

    /**
     * Status properties whose admin dropdown is a curated subset of the values
     * the column can actually hold, mapped to the canonical enum that completes
     * them. The admin UI ships the short list on purpose (those are the statuses
     * a merchant filters by day to day); an agent needs the full set, because
     * "find the failed orders" is a normal question and the engine executes
     * WHERE status IN (...) against the raw column either way.
     *
     * MCP-side only — the shared fluent_cart/{name}_filter_options hook is left
     * untouched so the admin dropdowns keep their curated lists.
     */
    const CANONICAL_OPTIONS = [
        'orders.order.status'                 => 'order_statuses',
        'orders.order.payment_status'         => 'payment_statuses',
        'orders.order.type'                   => 'order_types',
        'subscriptions.subscription.status'   => 'subscription_statuses',
        'subscriptions.subscription.billing_interval' => 'billing_intervals',
    ];

    /**
     * provider.property => { provider, property, def } for one filter class,
     * through the same fluent_cart/{name}_filter_options hook the engine
     * applies, so Pro/add-on providers appear here exactly as they execute.
     *
     * $entity is used only to complete curated status option lists from the
     * canonical enums (see CANONICAL_OPTIONS); pass it so the schema the agent
     * reads and the values normalize() accepts stay the same list.
     */
    private static function catalog($filterClass, $entity = ''): array
    {
        if (!$filterClass || !class_exists($filterClass) || !is_callable([$filterClass, 'advanceFilterOptions'])) {
            return [];
        }

        $filterName = $filterClass::getFilterName();
        $providers  = apply_filters("fluent_cart/{$filterName}_filter_options", $filterClass::advanceFilterOptions());
        if (!is_array($providers)) {
            return [];
        }

        $map = [];
        foreach ($providers as $providerKey => $provider) {
            $children = Arr::get((array) $provider, 'children', []);
            if (!is_array($children)) {
                continue;
            }
            foreach ($children as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $property = Arr::get($child, 'value');
                if (!is_string($property) || $property === '') {
                    continue;
                }
                $key = $providerKey . '.' . $property;
                // Core lists subscription.status twice — first wins.
                if (isset($map[$key])) {
                    continue;
                }
                $map[$key] = [
                    'provider' => (string) $providerKey,
                    'property' => $property,
                    'def'      => self::completeOptions($entity, $key, $child),
                ];
            }
        }

        return $map;
    }

    /**
     * Union a curated status dropdown with its canonical enum, preserving the
     * catalog's labels for the values it already had. Returns the definition
     * unchanged for every property without a canonical counterpart.
     */
    private static function completeOptions($entity, $key, array $def): array
    {
        $enumKey = Arr::get(self::CANONICAL_OPTIONS, $entity . '.' . $key);
        if (!$enumKey) {
            return $def;
        }
        // Only selection widgets carry an options map; leave anything else alone.
        if (Arr::get($def, 'type') !== 'selections') {
            return $def;
        }

        $enums = ContextTools::enums();
        if (empty($enums[$enumKey])) {
            return $def;
        }

        $options = (array) Arr::get($def, 'options', []);
        foreach ($enums[$enumKey] as $value) {
            if (!array_key_exists($value, $options)) {
                $options[$value] = $value;
            }
        }
        $def['options'] = $options;

        return $def;
    }

    /** Money columns for the entity, so values can be documented/validated as store-currency decimals. */
    private static function centColumnsFor($filterClass): array
    {
        try {
            $instance = new $filterClass([]);
            return (array) $instance->centColumns();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The column the engine will actually compare (relation column, explicit column, or the property itself). */
    private static function effectiveColumn(array $def)
    {
        $column = Arr::get($def, 'column');

        return $column ? $column : Arr::get($def, 'value');
    }

    /** Normalize the catalog's UI widget type into an agent-facing value kind. */
    private static function valueKind(array $def, array $centColumns): string
    {
        $type = Arr::get($def, 'type');

        if ($type === 'dates') {
            return 'date';
        }
        if ($type === 'selections') {
            return 'enum';
        }
        if ($type === 'remote_tree_select' || $type === 'cascading_select') {
            return 'id_list';
        }
        if ($type === 'numeric') {
            return in_array(self::effectiveColumn($def), $centColumns, true) ? 'money' : 'numeric';
        }

        return 'text';
    }

    /**
     * The operators a property accepts — the same resolution the admin filter
     * UI applies (custom operator map first, then by widget type), constrained
     * to what the SQL layer actually executes.
     */
    private static function resolveOperators(array $def): array
    {
        $customOps = Arr::get($def, 'operators');
        if (is_array($customOps) && $customOps) {
            $ops = array_map('strval', array_keys($customOps));
            if (Arr::get($def, 'filter_type') !== 'custom') {
                foreach ($ops as $i => $op) {
                    if (isset(self::OPERATOR_ALIASES[$op])) {
                        $ops[$i] = self::OPERATOR_ALIASES[$op];
                    }
                }
            }
            return array_values(array_unique($ops));
        }

        $isCustom = Arr::get($def, 'filter_type') === 'custom';
        $type     = Arr::get($def, 'type');

        if ($type === 'numeric') {
            return ['>', '<', '>=', '<=', '=', '!='];
        }
        if ($type === 'dates') {
            return ['before', 'after', 'date_equal', 'days_before', 'days_within'];
        }
        if ($type === 'selections') {
            // Custom selection callbacks (stock_status, b2b_purchase) read a
            // single scalar value and ignore richer operators.
            return $isCustom ? ['in'] : ['in', 'not_in'];
        }
        if ($type === 'remote_tree_select' || $type === 'cascading_select') {
            $ops = ['in', 'not_in'];
            if (Arr::get($def, 'filter_type') === 'relation') {
                // "has ALL of these" is only meaningful across related rows.
                // not_in_all is deliberately NOT offered: the engine compiles it
                // as whereDoesntHave(one related row = a AND = b), which is
                // always true for 2+ distinct ids — a silent match-everything
                // no-op. in_all (one whereHas per id) is correct.
                $ops[] = 'in_all';
            }
            return $ops;
        }

        return ['=', '!=', 'contains', 'not_contains'];
    }

    // -----------------------------------------------------------------
    // Validation + translation to the engine payload
    // -----------------------------------------------------------------

    /**
     * Validate the raw agent payload and translate it into the exact structure
     * BaseFilter::parseSearchGroups() consumes. Returns
     * { groups: array, warnings: string[] } or a WP_Error naming the first
     * offending condition — never a silently-trimmed payload.
     */
    private static function normalize($entity, $filterClass, $raw)
    {
        if (!is_array($raw)) {
            return self::structureError($entity);
        }

        // Accept three shapes: full groups [[c,c],[c]], a flat condition list
        // [c,c] (one AND group), or a single condition object {property,...}.
        if (isset($raw['property']) || isset($raw['source'])) {
            $raw = [[$raw]];
        } elseif (self::isConditionList($raw)) {
            $raw = [$raw];
        }

        if (count($raw) > self::MAX_GROUPS) {
            return self::limitError($entity);
        }

        $catalog     = self::catalog($filterClass, $entity);
        $centColumns = self::centColumnsFor($filterClass);

        $groups   = [];
        $warnings = [];

        $groupNo = 0;
        foreach ($raw as $group) {
            $groupNo++;
            if (!is_array($group)) {
                return self::structureError($entity);
            }
            if (count($group) > self::MAX_CONDITIONS_PER_GROUP) {
                return self::limitError($entity);
            }

            $engineGroup = [];
            $conditionNo = 0;
            foreach ($group as $condition) {
                $conditionNo++;
                $item = self::normalizeCondition($entity, $condition, $catalog, $centColumns, $groupNo, $conditionNo, $warnings);
                if (is_wp_error($item)) {
                    return $item;
                }
                $engineGroup[] = $item;
            }

            if ($engineGroup) {
                self::warnOrMergedRelations($engineGroup, $warnings);
                $groups[] = $engineGroup;
            }
        }

        if (!$groups) {
            return MCPHelper::error(
                'empty_filters',
                __('advanced_filters contained no conditions. Provide at least one {property, operator, value} condition, or omit the parameter.', 'fluent-cart'),
                ['entity' => $entity]
            );
        }

        return ['groups' => $groups, 'warnings' => $warnings];
    }

    /** True when every element looks like a condition object (flat list form). */
    private static function isConditionList(array $raw): bool
    {
        foreach ($raw as $element) {
            if (!is_array($element) || (!isset($element['property']) && !isset($element['source']))) {
                return false;
            }
        }

        return (bool) $raw;
    }

    /**
     * Validate one condition and build the engine item. The engine-internal
     * fields (filter_type / relation / column) always come from the catalog
     * definition, never from the caller.
     *
     * @return array|\WP_Error
     */
    private static function normalizeCondition($entity, $condition, array $catalog, array $centColumns, $groupNo, $conditionNo, array &$warnings)
    {
        if (!is_array($condition)) {
            return self::structureError($entity);
        }

        $property = Arr::get($condition, 'property');
        // Also accept the admin UI's own wire format: source: [provider, property].
        if (!$property) {
            $source = Arr::get($condition, 'source');
            if (is_array($source) && count($source) === 2) {
                $source = array_values($source);
                if (is_scalar($source[0]) && is_scalar($source[1])) {
                    $property = $source[0] . '.' . $source[1];
                }
            }
        }

        if (!is_string($property) || $property === '') {
            return MCPHelper::error(
                'invalid_structure',
                sprintf(
                    /* translators: 1: group number, 2: condition number */
                    __('The condition in group %1$d position %2$d has no property. Each condition needs {property, operator, value}.', 'fluent-cart'),
                    $groupNo,
                    $conditionNo
                ),
                ['entity' => $entity, 'group' => $groupNo, 'condition' => $conditionNo]
            );
        }

        if (!isset($catalog[$property])) {
            return MCPHelper::error(
                'unknown_property',
                sprintf(
                    /* translators: 1: property name, 2: group number, 3: condition number, 4: entity name, 5: valid property names */
                    __('Unknown property "%1$s" (group %2$d, condition %3$d). Valid properties for %4$s: %5$s. Call get-search-schema with entity=%4$s for operators and value formats.', 'fluent-cart'),
                    $property,
                    $groupNo,
                    $conditionNo,
                    $entity,
                    implode(', ', array_keys($catalog))
                ),
                ['entity' => $entity, 'property' => $property, 'valid_properties' => array_keys($catalog)]
            );
        }

        $entry = $catalog[$property];
        $def   = $entry['def'];

        $rawOperator = Arr::get($condition, 'operator', '');
        if (!is_scalar($rawOperator)) {
            return MCPHelper::error(
                'invalid_operator',
                sprintf(
                    /* translators: 1: property name */
                    __('The operator for property "%1$s" must be a string.', 'fluent-cart'),
                    $property
                ),
                ['entity' => $entity, 'property' => $property]
            );
        }
        $operator = (string) $rawOperator;
        if (Arr::get($def, 'filter_type') !== 'custom' && isset(self::OPERATOR_ALIASES[$operator])) {
            $operator = self::OPERATOR_ALIASES[$operator];
        }
        $allowed = self::resolveOperators($def);
        if (!in_array($operator, $allowed, true)) {
            return MCPHelper::error(
                'invalid_operator',
                sprintf(
                    /* translators: 1: operator, 2: property name, 3: allowed operators */
                    __('Operator "%1$s" is not valid for property "%2$s". Allowed: %3$s.', 'fluent-cart'),
                    $operator,
                    $property,
                    implode(', ', $allowed)
                ),
                ['entity' => $entity, 'property' => $property, 'operator' => $operator, 'allowed' => $allowed]
            );
        }

        if (!array_key_exists('value', $condition)) {
            return MCPHelper::error(
                'missing_value',
                sprintf(
                    /* translators: 1: property name */
                    __('The condition on "%1$s" has no value.', 'fluent-cart'),
                    $property
                ),
                ['entity' => $entity, 'property' => $property]
            );
        }

        $value = self::normalizeValue($def, $centColumns, $property, $operator, $condition['value'], $warnings);
        if (is_wp_error($value)) {
            return $value;
        }

        $item = [
            'source'   => [$entry['provider'], $entry['property']],
            'operator' => $operator,
            'value'    => $value,
        ];

        $filterType = Arr::get($def, 'filter_type');
        if ($filterType) {
            $item['filter_type'] = $filterType;
        }
        if ($filterType === 'relation') {
            $item['relation'] = Arr::get($def, 'relation', $entry['property']);
            $item['column']   = Arr::get($def, 'column', 'id');
        }

        return $item;
    }

    /**
     * Coerce/validate the value for the property's kind. Numeric scalars are
     * stringified deliberately: HandleRelationalFilter silently drops values
     * that are neither string nor array, so an integer 0 through a relation
     * filter would otherwise vanish without a trace.
     *
     * @return mixed|\WP_Error
     */
    private static function normalizeValue(array $def, array $centColumns, $property, $operator, $value, array &$warnings)
    {
        $kind = self::valueKind($def, $centColumns);

        if ($kind === 'money' || $kind === 'numeric') {
            if (!is_numeric($value)) {
                return self::valueError($property, __('a number', 'fluent-cart'));
            }
            // Reject non-finite / out-of-range magnitudes: "9e18" overflows the
            // BIGINT cents column to a negative and silently matches all rows;
            // "1e400" is +INF and throws deep in the SQL grammar.
            $num = (float) $value;
            if (!is_finite($num) || abs($num) > 1000000000000) {
                return self::valueError($property, __('a number within a sensible range', 'fluent-cart'));
            }
            return (string) $value;
        }

        if ($kind === 'date') {
            if ($operator === 'days_before' || $operator === 'days_within') {
                if (!is_numeric($value)) {
                    return self::valueError($property, __('a number of days', 'fluent-cart'));
                }
                // Beyond ~100 years the engine's now-minus-N-days underflows past
                // year 0000 into a malformed DATETIME the DB rejects (uncaught).
                $days = (int) $value;
                if ($days < 0 || $days > 36500) {
                    return self::valueError($property, __('a number of days between 0 and 36500', 'fluent-cart'));
                }
                return (string) $days;
            }
            // strtotime() maps junk like "GMT" / "T" / " " (and even a null byte)
            // to the CURRENT time, which would silently match "everything before
            // now". Require an actual date literal shape first.
            if (!is_string($value)
                || !preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value)
                || strtotime($value) === false) {
                return self::valueError($property, __('a date (YYYY-MM-DD or ISO-8601, UTC)', 'fluent-cart'));
            }
            return $value;
        }

        if ($kind === 'enum') {
            $options = Arr::get($def, 'options', []);
            $known   = (is_array($options) && $options) ? array_map('strval', array_keys($options)) : [];

            if (Arr::get($def, 'filter_type') === 'custom') {
                // Custom selection callbacks read one scalar.
                if (is_array($value)) {
                    if (count($value) !== 1) {
                        return self::valueError($property, __('a single value', 'fluent-cart'));
                    }
                    $value = reset($value);
                }
                if (!is_scalar($value)) {
                    return self::valueError($property, __('a single value', 'fluent-cart'));
                }
                $value = (string) $value;
                self::warnUnknownOption($property, [$value], $known, $warnings);
                return $value;
            }

            if (!is_array($value)) {
                $value = [$value];
            }
            $value = self::capValueArray($property, $value, $warnings);
            $flat = [];
            foreach ($value as $entryValue) {
                if (!is_scalar($entryValue)) {
                    return self::valueError($property, __('a value or array of values', 'fluent-cart'));
                }
                $flat[] = (string) $entryValue;
            }
            if (!$flat) {
                return self::valueError($property, __('a non-empty array of values', 'fluent-cart'));
            }
            self::warnUnknownOption($property, $flat, $known, $warnings);
            return $flat;
        }

        if ($kind === 'id_list') {
            if (!is_array($value)) {
                $value = [$value];
            }
            $value = self::capValueArray($property, $value, $warnings);
            $ids = [];
            foreach ($value as $id) {
                if (!is_numeric($id)) {
                    return self::valueError($property, __('an array of numeric ids', 'fluent-cart'));
                }
                $ids[] = (int) $id;
            }
            if (!$ids) {
                return self::valueError($property, __('a non-empty array of numeric ids', 'fluent-cart'));
            }
            return $ids;
        }

        // text
        if (!is_scalar($value)) {
            return self::valueError($property, __('a single text value', 'fluent-cart'));
        }
        $value = (string) $value;
        // On a relation-backed property the engine silently drops an empty
        // string (HandleRelationalFilter bails on empty), returning the full
        // unfiltered list as if filtered. Reject it here instead.
        if ($value === '' && Arr::get($def, 'filter_type') === 'relation') {
            return self::valueError($property, __('a non-empty value', 'fluent-cart'));
        }

        return $value;
    }

    /** Cap a per-condition value array so a huge whereIn / in_all fan-out can't be built. */
    private static function capValueArray($property, array $values, array &$warnings): array
    {
        if (count($values) > self::MAX_VALUES_PER_CONDITION) {
            $warnings[] = sprintf(
                /* translators: 1: property name, 2: cap, 3: received count */
                __('Property %1$s: only the first %2$d values were applied (received %3$d).', 'fluent-cart'),
                $property,
                self::MAX_VALUES_PER_CONDITION,
                count($values)
            );
            $values = array_slice($values, 0, self::MAX_VALUES_PER_CONDITION);
        }
        return $values;
    }

    /**
     * The engine's mergeRelationFilters() unions same relation+property 'in'
     * conditions within a group into one whereIn (OR), which contradicts the
     * "conditions within a group are AND-ed" contract. Warn once per property so
     * the agent knows to use in_all for AND semantics.
     */
    private static function warnOrMergedRelations(array $engineGroup, array &$warnings)
    {
        $seen   = [];
        $warned = [];
        foreach ($engineGroup as $item) {
            if (Arr::get($item, 'filter_type') !== 'relation') {
                continue;
            }
            $op = Arr::get($item, 'operator');
            // The engine's mergeRelationFilters() OR-merges same-property
            // conditions for all of these operators within a group.
            if (!in_array($op, ['in', 'not_in', 'contains', 'not_contains'], true)) {
                continue;
            }
            $source = Arr::get($item, 'source', []);
            $prop   = is_array($source) ? implode('.', $source) : (string) $source;
            $key    = $prop . '|' . $op;
            if (isset($seen[$key]) && !isset($warned[$key])) {
                $warnings[] = sprintf(
                    /* translators: 1: property name, 2: operator */
                    __('Property %1$s: multiple "%2$s" conditions on the same related property in one group are combined as OR (match any), not AND. Split them across separate OR groups, or use in_all for id-list AND matching.', 'fluent-cart'),
                    $prop,
                    $op
                );
                $warned[$key] = true;
            }
            $seen[$key] = true;
        }
    }

    /** A value outside the documented options isn't fatal (the engine matches it as-is) — but say so. */
    private static function warnUnknownOption($property, array $values, array $known, array &$warnings)
    {
        if (!$known) {
            return;
        }
        $unknown = [];
        foreach ($values as $value) {
            if (!in_array($value, $known, true)) {
                $unknown[] = $value;
            }
        }
        if (!$unknown) {
            return;
        }
        // One warning per property, listing every off-catalog value, rather than
        // one warning per value (a capped-but-large list would flood the meta).
        $warnings[] = sprintf(
            /* translators: 1: unrecognized values, 2: property name, 3: known option values */
            __('Values "%1$s" on %2$s are not among the documented options (%3$s); they were matched literally, so unintended values return zero rows.', 'fluent-cart'),
            implode(', ', $unknown),
            $property,
            implode(', ', $known)
        );
    }

    // -----------------------------------------------------------------
    // Errors & hints
    // -----------------------------------------------------------------

    private static function unknownEntityError($entity)
    {
        return MCPHelper::error(
            'unknown_entity',
            sprintf(
                /* translators: 1: entity name, 2: valid entity names */
                __('Unknown entity "%1$s". Valid entities: %2$s.', 'fluent-cart'),
                (string) $entity,
                implode(', ', self::entityNames())
            ),
            ['entities' => self::entityNames()]
        );
    }

    private static function structureError($entity)
    {
        return MCPHelper::error(
            'invalid_structure',
            __('advanced_filters must be an array of OR groups, each an array of AND conditions {property, operator, value}. A flat array of conditions is also accepted and treated as one group.', 'fluent-cart'),
            [
                'entity' => $entity,
                'hint'   => 'Example: [[{"property":"order.status","operator":"in","value":["completed"]}]] — call get-search-schema for the full reference.',
            ]
        );
    }

    private static function limitError($entity)
    {
        return MCPHelper::error(
            'too_many_conditions',
            sprintf(
                /* translators: 1: max groups, 2: max conditions per group */
                __('advanced_filters allows at most %1$d OR groups with %2$d conditions each.', 'fluent-cart'),
                self::MAX_GROUPS,
                self::MAX_CONDITIONS_PER_GROUP
            ),
            ['entity' => $entity, 'max_groups' => self::MAX_GROUPS, 'max_conditions_per_group' => self::MAX_CONDITIONS_PER_GROUP]
        );
    }

    private static function valueError($property, $expected)
    {
        return MCPHelper::error(
            'invalid_value',
            sprintf(
                /* translators: 1: property name, 2: what the value should be */
                __('The value for "%1$s" must be %2$s.', 'fluent-cart'),
                $property,
                $expected
            ),
            ['property' => $property]
        );
    }

    private static function idListHint(array $def): string
    {
        $remoteKey = Arr::get($def, 'remote_data_key');
        if ($remoteKey === 'product_variations') {
            return 'Array of VARIATION ids (not product ids) — find them via get-product (variations[].id) or list-products.';
        }
        if ($remoteKey === 'labels') {
            return 'Array of label ids — resolve names to ids via list-reference-data kinds=["labels"].';
        }
        if (Arr::get($def, 'relation') === 'wpTerms') {
            return 'Array of term ids — see options, or list-reference-data kinds=["product_categories"].';
        }

        return 'Array of numeric ids.';
    }

    /** Flatten a nested {value,label,children} option tree to a bounded [{id,label}] list. */
    private static function flattenTree($tree, $cap = 100): array
    {
        $out = [];
        foreach ((array) $tree as $option) {
            if (count($out) >= $cap) {
                break;
            }
            if (!is_array($option)) {
                continue;
            }
            $out[] = ['id' => Arr::get($option, 'value'), 'label' => Arr::get($option, 'label')];
            $children = Arr::get($option, 'children', []);
            if (is_array($children) && $children) {
                foreach (self::flattenTree($children, $cap - count($out)) as $child) {
                    $out[] = $child;
                }
            }
        }

        return array_slice($out, 0, $cap);
    }

    /** A worked, copy-adaptable example per core entity (null for add-on entities). */
    private static function exampleFor($entity)
    {
        $examples = [
            'orders'        => [
                'meaning' => '(completed or processing orders from the last 90 days over 100 in store currency) OR (any renewal order)',
                'value'   => [
                    [
                        ['property' => 'order.status', 'operator' => 'in', 'value' => ['completed', 'processing']],
                        ['property' => 'order.created_at', 'operator' => 'days_within', 'value' => 90],
                        ['property' => 'order.total_amount', 'operator' => '>', 'value' => 100],
                    ],
                    [
                        ['property' => 'order.type', 'operator' => 'in', 'value' => ['renewal']],
                    ],
                ],
            ],
            'customers'     => [
                'meaning' => 'customers with LTV over 500 who purchased within the last 180 days',
                'value'   => [
                    [
                        ['property' => 'customer.ltv', 'operator' => '>', 'value' => 500],
                        ['property' => 'order.last_purchase_date', 'operator' => 'days_within', 'value' => 180],
                    ],
                ],
            ],
            'products'      => [
                'meaning' => 'simple products priced 50 or more',
                'value'   => [
                    [
                        ['property' => 'pricing.min_price', 'operator' => '>=', 'value' => 50],
                        ['property' => 'variations.variation_type', 'operator' => 'in', 'value' => ['simple']],
                    ],
                ],
            ],
            'subscriptions' => [
                'meaning' => 'active or trialing subscriptions that have been billed at least 3 times',
                'value'   => [
                    [
                        ['property' => 'subscription.status', 'operator' => 'in', 'value' => ['active', 'trialing']],
                        ['property' => 'subscription.bill_count', 'operator' => '>=', 'value' => 3],
                    ],
                ],
            ],
            'licenses'      => [
                'meaning' => 'active licenses with at least one activation',
                'value'   => [
                    [
                        ['property' => 'license.status', 'operator' => 'in', 'value' => ['active']],
                        ['property' => 'license.activation_count', 'operator' => '>', 'value' => 0],
                    ],
                ],
            ],
        ];

        return Arr::get($examples, $entity);
    }
}
