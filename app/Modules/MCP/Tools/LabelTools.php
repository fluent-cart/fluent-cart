<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;

/**
 * Label tools — the cross-entity tagging system.
 *
 * apply-labels attaches/detaches existing labels to an order, customer, or
 * subscription. It works on label IDs (from list-reference-data with
 * kind=labels), so it never needs to know how a label's value is stored.
 *
 * Parameter design: explicit add/remove delta arrays rather than a "set the
 * full list" payload — the agent rarely knows the current set, and deltas are
 * what it actually intends ("tag this order VIP"). We return the resulting set
 * so the agent can confirm.
 *
 * Managing label DEFINITIONS (create/rename/delete the labels themselves) is
 * deliberately not exposed yet — the underlying value column is a serialized
 * blob whose shape we don't want an agent guessing at.
 */
class LabelTools
{
    const TYPE_MAP = ['order' => 'Order', 'customer' => 'Customer', 'subscription' => 'Subscription'];

    public static function definitions()
    {
        return [
            'fluent-cart/apply-labels' => [
                'label'       => __('Apply Labels', 'fluent-cart'),
                'description' => __('Add or remove labels on an order, customer, or subscription. Pass add_label_ids and/or remove_label_ids; use list-reference-data with kind=labels to find label ids. Returns the resulting label set so you can confirm.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entity_type'      => ['type' => 'string', 'enum' => ['order', 'customer', 'subscription']],
                        'entity_id'        => ['type' => 'integer'],
                        'add_label_ids'    => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'remove_label_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                    'required' => ['entity_type', 'entity_id'],
                ],
                'execute_callback'    => [self::class, 'applyLabels'],
                'permission_callback' => function () {
                    return PermissionGate::can('labels/manage');
                },
                // Mutating but reversible (labels can be removed) and re-applying
                // the same set is a no-op — idempotent. 'bulk' was a non-standard
                // hint the MCP client could not read; readonly:false is the real
                // "this mutates" signal.
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public static function applyLabels($params = [])
    {
        $type = isset($params['entity_type']) ? sanitize_text_field($params['entity_type']) : '';
        if (!isset(self::TYPE_MAP[$type])) {
            return MCPHelper::error('invalid_entity_type', __('entity_type must be one of: order, customer, subscription.', 'fluent-cart'));
        }

        $entityId = isset($params['entity_id']) ? (int) $params['entity_id'] : 0;
        if (!$entityId) {
            return MCPHelper::error('missing_identifier', __('entity_id is required.', 'fluent-cart'));
        }

        $modelClass = 'FluentCart\\App\\Models\\' . self::TYPE_MAP[$type];
        if (!class_exists($modelClass) || !$modelClass::query()->where('id', $entityId)->exists()) {
            return MCPHelper::error('entity_not_found', __('No matching record found for entity_type and entity_id.', 'fluent-cart'));
        }

        $rel = 'FluentCart\\App\\Models\\LabelRelationship';
        if (!class_exists($rel)) {
            return MCPHelper::error('not_available', __('Labels are not available on this install.', 'fluent-cart'));
        }

        $add    = array_values(array_unique(array_map('intval', (array) (isset($params['add_label_ids']) ? $params['add_label_ids'] : []))));
        $remove = array_values(array_unique(array_map('intval', (array) (isset($params['remove_label_ids']) ? $params['remove_label_ids'] : []))));

        if (!$add && !$remove) {
            return MCPHelper::error('missing_param', __('Provide add_label_ids and/or remove_label_ids.', 'fluent-cart'), ['fields' => ['add_label_ids', 'remove_label_ids']]);
        }

        // The same id in both lists is a contradictory instruction (add then
        // remove nets to nothing yet reports as both added and removed). Reject
        // so the agent clarifies intent.
        $overlap = array_values(array_intersect($add, $remove));
        if ($overlap) {
            return MCPHelper::error(
                'conflicting_labels',
                __('A label id cannot be in both add_label_ids and remove_label_ids.', 'fluent-cart'),
                ['fields' => ['add_label_ids', 'remove_label_ids'], 'conflicting_label_ids' => $overlap]
            );
        }

        // Reject label IDs to ADD that don't exist, so entities can't be tagged
        // with phantom labels that render with a null title. (Removal of an
        // unknown id is harmless — it just no-ops.)
        if ($add && class_exists('\FluentCart\App\Models\Label')) {
            $known   = array_map('intval', (array) \FluentCart\App\Models\Label::query()->whereIn('id', $add)->pluck('id')->toArray());
            $unknown = array_values(array_diff($add, $known));
            if ($unknown) {
                return MCPHelper::error(
                    'label_not_found',
                    __('One or more label IDs do not exist. Use list-reference-data with kind=labels to find valid ids.', 'fluent-cart'),
                    ['fields' => ['add_label_ids'], 'unknown_label_ids' => $unknown]
                );
            }
        }

        $added = [];
        foreach ($add as $labelId) {
            $exists = $rel::query()
                ->where('label_id', $labelId)
                ->where('labelable_id', $entityId)
                ->where('labelable_type', $modelClass)
                ->exists();
            if (!$exists) {
                $rel::query()->create([
                    'label_id'       => $labelId,
                    'labelable_id'   => $entityId,
                    'labelable_type' => $modelClass,
                ]);
                $added[] = $labelId;
            }
        }

        $removed = [];
        foreach ($remove as $labelId) {
            $deleted = $rel::query()
                ->where('label_id', $labelId)
                ->where('labelable_id', $entityId)
                ->where('labelable_type', $modelClass)
                ->delete();
            if ($deleted) {
                $removed[] = $labelId;
            }
        }

        $current = array_map('intval', (array) $rel::query()
            ->where('labelable_id', $entityId)
            ->where('labelable_type', $modelClass)
            ->pluck('label_id')
            ->toArray());

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: labels added, 2: labels removed */
                __('Labels updated: %1$d added, %2$d removed.', 'fluent-cart'),
                count($added),
                count($removed)
            ),
            [
                'entity_type'       => $type,
                'entity_id'         => $entityId,
                'added'             => $added,
                'removed'           => $removed,
                'current_label_ids' => $current,
                'current_labels'    => self::resolveLabels($current),
            ]
        );
    }

    /**
     * Resolve label IDs to {id, title} so the caller doesn't need a follow-up
     * reference-data lookup. fct_label keeps its name in a (maybe-serialized)
     * `value` column — a plain string or an array {title|value, color}.
     */
    private static function resolveLabels(array $ids)
    {
        if (!$ids) {
            return [];
        }

        $labelClass = 'FluentCart\\App\\Models\\Label';
        if (!class_exists($labelClass)) {
            return array_map(function ($id) {
                return ['id' => $id, 'title' => null];
            }, $ids);
        }

        $titles = [];
        foreach ($labelClass::query()->whereIn('id', $ids)->get() as $label) {
            $val   = $label->value;
            $title = is_array($val)
                ? (isset($val['title']) ? $val['title'] : (isset($val['value']) ? $val['value'] : null))
                : $val;
            $titles[(int) $label->id] = $title;
        }

        $out = [];
        foreach ($ids as $id) {
            $out[] = ['id' => $id, 'title' => isset($titles[$id]) ? $titles[$id] : null];
        }
        return $out;
    }
}
