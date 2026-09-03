<?php

namespace FluentCart\Api\Resource;

use FluentCart\Api\Resource\BaseResourceApi;
use FluentCart\App\App;
use FluentCart\App\Helpers\HelperTrait;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Filter\AttrGroupFilter;

class AttrGroupResource extends BaseResourceApi
{
    use HelperTrait;

    public static function getQuery(): Builder
    {
        return AttributeGroup::query();
    }

    public static function get(array $params = [])
    {
        return AttrGroupFilter::make($params)->paginate();
    }

    public static function find($id, $params = [])
    {
        $with = Arr::get($params, 'with', []);

        $query = static::getQuery();

        if (empty($with)) {
            return $query->find($id);
        }

        $with = static::getArrValWithinEnum($with, ['terms'], 'terms');
        return $query->with($with)->find($id);
    }

    public static function create($data, $params = [])
    {
        if (empty($data['slug']) && !empty($data['title'])) {
            $data['slug'] = static::generateUniqueSlug($data['title']);
        }

        // Append new groups to the end of the merchant's manual order. max()+1
        // keeps the dense 1..N sequence going so the sidebar (orderBy serial ASC)
        // shows the new group last until the merchant drags it elsewhere — mirrors
        // AttrTermResource::create's per-group serial assignment.
        $data['serial'] = (int) AttributeGroup::query()->max('serial') + 1;

        try {
            $group = static::getQuery()->create($data);
        } catch (\Throwable $e) {
            // Concurrent POSTs with the same slug can both pass the unique
            // validator (TOCTOU) and collide at the DB UNIQUE on slug. Surface
            // a clean 422 instead of leaking the raw exception as a 500.
            if (static::isUniqueViolation($e)) {
                return static::makeErrorResponse([
                    ['code' => 422, 'message' => __('A group with this slug already exists.', 'fluent-cart')]
                ]);
            }
            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Group creation failed.', 'fluent-cart')]
            ]);
        }

        if ($group) {
            return static::makeSuccessResponse(
                $group,
                __('Successfully created!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Group creation failed.', 'fluent-cart')]
        ]);
    }

    public static function update($data, $groupId, $params = [])
    {
        // Wrap the lookup and update in a transaction with a row lock on
        // the group so concurrent writers can't split the read and the
        // mutation. Also lets us return a clean 422 on slug UNIQUE collision.
        // System-seeded templates (Color, Size, …) are editable now — the
        // earlier is_system rename block was rolled back per product
        // decision; in-use protection only fires on delete below.
        $connection = static::getQuery()->getConnection();
        $connection->beginTransaction();
        try {
            $group = static::getQuery()->lockForUpdate()->find($groupId);

            if (!$group) {
                $connection->rollBack();
                return static::makeErrorResponse([
                    ['code' => 404, 'message' => __('Attribute group not found.', 'fluent-cart')]
                ]);
            }

            $wasUpdated = $group->update($data);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            if (static::isUniqueViolation($e)) {
                return static::makeErrorResponse([
                    ['code' => 422, 'message' => __('A group with this slug already exists.', 'fluent-cart')]
                ]);
            }
            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Group info update failed.', 'fluent-cart')]
            ]);
        }

        if ($wasUpdated) {
            return static::makeSuccessResponse(
                $wasUpdated,
                __('Group updated successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Group info update failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Generate a unique slug for a new group. Mirrors AttrTermResource::generateUniqueSlug()
     * but scoped to the groups table (global slug UNIQUE, not per-group).
     */
    private static function generateUniqueSlug(string $title): string
    {
        $baseSlug = sanitize_title($title);

        $existingSlugs = AttributeGroup::query()
            ->where('slug', 'LIKE', "{$baseSlug}%")
            ->pluck('slug')
            ->toArray();

        if (!in_array($baseSlug, $existingSlugs, true)) {
            return $baseSlug;
        }

        $maxSuffix = 1;
        foreach ($existingSlugs as $existingSlug) {
            if (preg_match('/^' . preg_quote($baseSlug, '/') . '-(\d+)$/', $existingSlug, $matches)) {
                $maxSuffix = max($maxSuffix, (int) $matches[1]);
            }
        }

        return "{$baseSlug}-" . ($maxSuffix + 1);
    }

    /**
     * MySQL/MariaDB UNIQUE constraint violation detector. SQLSTATE 23000 covers
     * duplicate-key errors on slug UNIQUE. Used so concurrent inserts/updates
     * return a clean 422 instead of leaking a generic 500.
     */
    private static function isUniqueViolation(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return strpos($msg, '1062') !== false
            || strpos($msg, 'Duplicate entry') !== false
            || strpos($msg, 'SQLSTATE[23000]') !== false;
    }

    public static function delete($groupId, $params = [])
    {
        // Wrap the lookup, the in-use guard, and the cascading
        // delete in a single transaction with row-level locks. Without locking
        // the group row + the relations lookup, a concurrent variant attach
        // between the in-use check and the cascading delete could leave
        // orphan relation rows pointing at the deleted group. The AttributeGroup
        // deleting boot hook also cascades to terms in a separate query, so
        // the wrap ensures group + terms commit atomically.
        $connection = AttributeRelation::query()->getConnection();
        $connection->beginTransaction();
        try {
            $group = static::getQuery()->lockForUpdate()->find($groupId);

            if (!$group) {
                $connection->rollBack();
                return static::makeErrorResponse([
                    ['code' => 404, 'message' => __('Attribute group not found in database, failed to remove.', 'fluent-cart')]
                ]);
            }

            // System-vs-merchant distinction no longer blocks deletion; the
            // only protection that remains is the in-use guard below, which
            // catches the case where any product variant still references
            // this group via fct_atts_relations.
            $existingRelation = AttributeRelation::query()
                ->where('group_id', $groupId)
                ->lockForUpdate()
                ->first();

            if ($existingRelation) {
                $connection->rollBack();
                return static::makeErrorResponse([
                    ['code' => 403, 'message' => __('This group is already in use, can not be deleted.', 'fluent-cart')]
                ]);
            }

            $group->delete();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Failed to delete attribute group.', 'fluent-cart')]
            ]);
        }

        return static::makeSuccessResponse(
            '',
            __('Attribute group successfully deleted!', 'fluent-cart')
        );
    }

    /**
     * Persist the merchant's drag-reorder of attribute groups in the library.
     * Receives group IDs in the desired display order and writes a dense
     * serial (1-indexed) to each. Mirrors AttrTermResource::reorder, one level
     * up — the only difference is there's no parent group to scope ownership
     * to, so we just confirm every ID is a real group before writing.
     *
     * The sidebar paginates (Load More), so the submitted set is the loaded
     * prefix of the serial-ordered list; reassigning it to 1..k stays within
     * the prefix's own range and never collides with unloaded groups.
     */
    public static function reorder($params = [])
    {
        // IDs are already sanitized by the controller (int-cast, positive-only,
        // deduped, capped). Take them as-is here.
        $ids = (array) Arr::get($params, 'ids', []);

        if (empty($ids)) {
            return static::makeErrorResponse([
                ['code' => 422, 'message' => __('No group IDs provided.', 'fluent-cart')]
            ]);
        }

        $ownedCount = AttributeGroup::query()
            ->whereIn('id', $ids)
            ->count();

        if ($ownedCount !== count($ids)) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('One or more group IDs do not exist.', 'fluent-cart')]
            ]);
        }

        $values = [];
        foreach ($ids as $index => $id) {
            $values[] = ['id' => $id, 'serial' => $index + 1];
        }

        $db = App::db();
        $db->beginTransaction();
        try {
            AttributeGroup::query()->batchUpdate($values);
            $db->commit();
            return static::makeSuccessResponse([], __('Groups reordered.', 'fluent-cart'));
        } catch (\Throwable $e) {
            $db->rollBack();
            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Failed to reorder groups.', 'fluent-cart')]
            ]);
        }
    }
}
