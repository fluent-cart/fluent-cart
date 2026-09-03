<?php

namespace FluentCart\Api\Resource;

use FluentCart\Api\Resource\BaseResourceApi;
use FluentCart\App\App;
use FluentCart\App\Helpers\HelperTrait;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Filter\AttrTermFilter;

class AttrTermResource extends BaseResourceApi
{
    use HelperTrait;

    public static function getQuery(): Builder
    {
        return AttributeTerm::query();
    }

    public static function get(array $params = [])
    {
        $filter = AttrTermFilter::make($params);
        $groupId = Arr::get($params, 'group_id');
        if ($groupId) {
            $filter->setGroupId((int) $groupId);
        }
        return $filter->paginate();
    }

    public static function find($id, $params = [])
    {
        return static::getQuery()->find($id);
    }

    public static function create($data, $params = [])
    {
        $groupId    = (int) Arr::get($params, 'group_id');
        $termInputs = Arr::get($data, 'terms', []);
        $group      = AttributeGroup::query()->find($groupId);

        if (!$group) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Attribute group not found.', 'fluent-cart')]
            ]);
        }

        $lastSerial = (int) AttributeTerm::query()->where('group_id', $group->id)->max('serial');

        // One query fetches all existing slugs that share a base with any incoming
        // term — covers uniqueness for every term without N per-term queries.
        $slugBases = array_map(function ($termInput) {
            $slugSource = !empty($termInput['slug']) ? $termInput['slug'] : $termInput['title'];
            return sanitize_title($slugSource);
        }, $termInputs);
        $slugQuery = AttributeTerm::query()->where('group_id', $group->id);
        foreach ($slugBases as $slugBase) {
            $slugQuery->orWhere('slug', 'LIKE', $slugBase . '%');
        }
        $takenSlugs = $slugQuery->pluck('slug')->toArray();

        $insertRows   = [];
        $insertedSlugs = [];
        foreach ($termInputs as $index => $termInput) {
            $slugSource  = !empty($termInput['slug']) ? $termInput['slug'] : $termInput['title'];
            $slugBase    = sanitize_title($slugSource);
            $uniqueSlug  = static::resolveUniqueSlugFromSet($slugBase, $takenSlugs);
            $takenSlugs[]    = $uniqueSlug;
            $insertedSlugs[] = $uniqueSlug;

            $encodedSettings = !empty($termInput['settings']) && is_array($termInput['settings'])
                ? json_encode($termInput['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            $insertRows[] = [
                'group_id' => $group->id,
                'title'    => $termInput['title'],
                'slug'     => $uniqueSlug,
                'serial'   => $lastSerial + $index + 1,
                'settings' => $encodedSettings,
            ];
        }

        AttributeTerm::query()->insert($insertRows);

        $createdTerms = AttributeTerm::query()
            ->where('group_id', $group->id)
            ->whereIn('slug', $insertedSlugs)
            ->get()
            ->toArray();

        if ($createdTerms) {
            return static::makeSuccessResponse(
                $createdTerms,
                __('Terms created successfully.', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Term creation failed.', 'fluent-cart')]
        ]);
    }

    public static function update($data, $termId, $params = [])
    {
        $groupId = Arr::get($params, 'group_id');

        // Wrap the lookup, slug regen, and update in a transaction with a
        // row lock on the term. Symmetric with create() / delete(). Without
        // the lock, the slug auto-regen path can read existing slugs, derive
        // "red-2", and then collide with a concurrent insert of "red-2"
        // between the read and the update.
        $db = App::db();
        $db->beginTransaction();
        try {
            $term = static::getQuery()
                ->where('id', $termId)
                ->where('group_id', $groupId)
                ->lockForUpdate()
                ->first();

            if (!$term) {
                $db->rollBack();
                return static::makeErrorResponse([
                    ['code' => 404, 'message' => __('Attribute term not found.', 'fluent-cart')]
                ]);
            }

            // Mirror create()'s auto-slug behaviour: when the client clears the slug field
            // intending the server to regenerate it, derive a unique slug from the title
            // instead of letting MySQL reject the NOT NULL column.
            if (array_key_exists('slug', $data) && empty($data['slug']) && !empty($data['title'])) {
                $data['slug'] = static::generateUniqueSlug($data['title'], (int) $term->group_id, (int) $term->id);
            }

            // Defence-in-depth: group_id is in AttributeTerm $fillable for the
            // create flow, but updating it would let a client reparent a term
            // into a different group via the wrong endpoint. The controller's
            // Arr::only allowlist already excludes group_id, but stripping it
            // here too means future controller refactors cannot regress this
            // boundary.
            unset($data['group_id']);

            // Pre-check the composite UNIQUE (group_id, slug) when the slug is
            // changing, so a clean 422 is returned without an UPDATE the DB
            // rejects (which prints a raw wpdb error ahead of the JSON). The
            // catch below stays as the concurrency backstop.
            if (!empty($data['slug'])) {
                $slugTaken = static::getQuery()
                    ->where('group_id', $term->group_id)
                    ->where('slug', $data['slug'])
                    ->where('id', '!=', $term->id)
                    ->count() > 0;
                if ($slugTaken) {
                    $db->rollBack();
                    return static::makeErrorResponse([
                        ['code' => 422, 'message' => __('A term with this slug already exists in the group.', 'fluent-cart')]
                    ]);
                }
            }

            $isUpdated = $term->update($data);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if (static::isUniqueViolation($e)) {
                return static::makeErrorResponse([
                    ['code' => 422, 'message' => __('A term with this slug already exists in the group.', 'fluent-cart')]
                ]);
            }
            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Term update failed.', 'fluent-cart')]
            ]);
        }

        if ($isUpdated) {
            return static::makeSuccessResponse(
                $isUpdated,
                __('Term Successfully updated!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Term update failed.', 'fluent-cart')]
        ]);
    }

    public static function delete($termId, $params = [])
    {
        $groupId = Arr::get($params, 'group_id');

        // Wrap the use-check and the delete in a transaction with row-level
        // locks so a concurrent variant attach between the check and the
        // delete cannot leave orphan relation rows pointing at a deleted
        // term. Without this, a TOCTOU race lets a relation row be inserted
        // after isUsed returned null and before $term->delete() ran.
        $db = App::db();
        $db->beginTransaction();
        try {
            $term = static::getQuery()->lockForUpdate()->find($termId);

            if (!$term || $term->group_id != $groupId) {
                $db->rollBack();
                return static::makeErrorResponse([
                    ['code' => 404, 'message' => __('Attribute term not found, failed to remove.', 'fluent-cart')]
                ]);
            }

            $isUsed = AttributeRelation::query()
                ->where('group_id', $groupId)
                ->where('term_id', $termId)
                ->lockForUpdate()
                ->first();

            if ($isUsed) {
                $db->rollBack();
                return static::makeErrorResponse([
                    ['code' => 403, 'message' => __('This term is already in use, can not be deleted.', 'fluent-cart')]
                ]);
            }

            $term->delete();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Failed to delete attribute term.', 'fluent-cart')]
            ]);
        }

        return static::makeSuccessResponse(
            '',
            __('Attribute term successfully deleted!', 'fluent-cart')
        );
    }

    public static function reorder($params = [])
    {
        $groupId = (int) Arr::get($params, 'group_id');
        $ids = array_slice(
            array_values(array_filter(array_map('intval', (array) Arr::get($params, 'ids', [])))),
            0,
            500
        );

        if (empty($ids)) {
            return static::makeErrorResponse([
                ['code' => 422, 'message' => __('No term IDs provided.', 'fluent-cart')]
            ]);
        }

        $ownedCount = AttributeTerm::query()
            ->whereIn('id', $ids)
            ->where('group_id', $groupId)
            ->count();

        if ($ownedCount !== count($ids)) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('One or more term IDs do not belong to this group.', 'fluent-cart')]
            ]);
        }

        $values = [];
        foreach ($ids as $index => $id) {
            $values[] = ['id' => $id, 'serial' => $index + 1];
        }

        $db = App::db();
        $db->beginTransaction();
        try {
            AttributeTerm::query()->batchUpdate($values);
            $db->commit();
            return static::makeSuccessResponse([], __('Terms reordered.', 'fluent-cart'));
        } catch (\Throwable $e) {
            $db->rollBack();
            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Failed to reorder terms.', 'fluent-cart')]
            ]);
        }
    }

    /**
     * MySQL/MariaDB UNIQUE constraint violation detector. SQLSTATE 23000 covers
     * duplicate-key on any UNIQUE index, including the composite (group_id, slug)
     * on terms and slug on groups. Used so concurrent inserts return a clean 422
     * instead of leaking a generic 500.
     */
    private static function isUniqueViolation(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return strpos($msg, '1062') !== false
            || strpos($msg, 'Duplicate entry') !== false
            || strpos($msg, 'SQLSTATE[23000]') !== false;
    }

    /**
     * Derive a unique slug from an in-memory set of already-taken slugs.
     * Used by createBulk() so multiple titles can be resolved in one pass
     * without a DB round-trip per title.
     */
    private static function resolveUniqueSlugFromSet(string $slugBase, array $takenSlugs): string
    {
        if (!in_array($slugBase, $takenSlugs, true)) {
            return $slugBase;
        }

        $highestSuffix = 1;
        foreach ($takenSlugs as $takenSlug) {
            if (preg_match('/^' . preg_quote($slugBase, '/') . '-(\d+)$/', $takenSlug, $matches)) {
                $highestSuffix = max($highestSuffix, (int) $matches[1]);
            }
        }

        return $slugBase . '-' . ($highestSuffix + 1);
    }

    /**
     * Generate a unique slug within a group, ignoring a specific term id (for update).
     * Mirrors the auto-slug logic in create() so the two endpoints stay in sync.
     */
    private static function generateUniqueSlug(string $title, int $groupId, ?int $ignoreId = null): string
    {
        $base = sanitize_title($title);

        $query = AttributeTerm::query()
            ->where('group_id', $groupId)
            ->where('slug', 'LIKE', $base . '%');

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        $taken = $query->pluck('slug')->toArray();

        if (!in_array($base, $taken, true)) {
            return $base;
        }

        $max = 1;
        foreach ($taken as $existing) {
            if (preg_match('/^' . preg_quote($base, '/') . '-(\d+)$/', $existing, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $base . '-' . ($max + 1);
    }
}
