<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\Framework\Support\Arr;

/**
 * Shop filters on variation attributes (Size, Colour...), keyed "attr_{groupId}".
 */
class ShopAttributeFilter
{
    const KEY_PREFIX = 'attr_';

    // Defaults for maxGroups() and maxTerms(); the ceilings keep a filtered limit bounded.
    const MAX_GROUPS = 10;

    const MAX_GROUPS_CEILING = 50;

    // As many as the sidebar can show, so every box a shopper checks reaches the query.
    const MAX_TERMS_PER_GROUP = 200;

    const MAX_TERMS_SHOWN = 200;

    const MAX_TERMS_CEILING = 1000;

    /** @var array<int, array{group: ?AttributeGroup, terms: AttributeTerm[]}> */
    private static $groupCache = [];

    /**
     * Most attribute filters one sidebar shows and a URL can select.
     * Filter: fluent_cart/shop_app/product_attribute/max_groups (int, default 10, 1–50).
     */
    public static function maxGroups(): int
    {
        $limit = (int) apply_filters('fluent_cart/shop_app/product_attribute/max_groups', self::MAX_GROUPS);

        return max(1, min(self::MAX_GROUPS_CEILING, $limit));
    }

    /**
     * Most values one attribute filter shows and a URL can select, kept equal so every shown box reaches the query.
     * Filter: fluent_cart/shop_app/product_attribute/max_terms (int, default 200, 1–1000).
     */
    public static function maxTerms(): int
    {
        $limit = (int) apply_filters('fluent_cart/shop_app/product_attribute/max_terms', self::MAX_TERMS_SHOWN);

        return max(1, min(self::MAX_TERMS_CEILING, $limit));
    }

    /** @var array<int, int> termId => product count */
    private static $countCache = [];

    /**
     * Attribute groups for editor pickers (Gutenberg and the page builder add-ons), library order.
     *
     * @return array<int, array{id: int, title: string, type: string}>
     */
    public static function groupOptions(): array
    {
        return AttributeGroup::query()
            ->orderBy('serial', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get(['id', 'title', 'settings'])
            ->map(function ($group) {
                return [
                    'id'    => (int) $group->id,
                    'title' => $group->title,
                    'type'  => (string) Arr::get((array) $group->settings, 'type', ''),
                ];
            })
            ->values()
            ->toArray();
    }

    public static function filterKey(int $groupId): string
    {
        return self::KEY_PREFIX . $groupId;
    }

    /**
     * The selected terms per group, from a shop "filters" payload.
     *
     * @param mixed $filters
     * @return array<int, int[]> groupId => termIds
     */
    public static function parseSelection($filters): array
    {
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }

        if (!is_array($filters)) {
            return [];
        }

        $maxGroups = self::maxGroups();
        $maxTerms = self::maxTerms();
        $selection = [];
        foreach ($filters as $key => $termIds) {
            $suffix = substr((string) $key, strlen(self::KEY_PREFIX));
            if (strpos((string) $key, self::KEY_PREFIX) !== 0 || !ctype_digit($suffix) || $suffix[0] === '0') {
                continue;
            }

            // A config entry ({enabled, group_id...}) shares the key but is not a selection.
            if (is_array($termIds) && Arr::isAssoc($termIds)) {
                continue;
            }

            $groupId = (int) $suffix;
            if (!isset($selection[$groupId]) && count($selection) >= $maxGroups) {
                continue;
            }

            $ids = [];
            foreach (array_slice((array) $termIds, 0, $maxTerms) as $termId) {
                if (is_scalar($termId) && (int) $termId > 0) {
                    $ids[] = (int) $termId;
                }
            }

            $ids = array_slice(array_values(array_unique(array_merge($selection[$groupId] ?? [], $ids))), 0, $maxTerms);
            if ($ids) {
                $selection[$groupId] = $ids;
            }
        }

        return $selection;
    }

    /**
     * Keep products that have one active variant matching every selected group.
     *
     * Terms within a group are OR, groups are AND, and both must hold on the same
     * variant: "M + Camel" must not match a product sold only in M Black and L Camel.
     */
    public static function apply($query, array $selection)
    {
        if (!$selection) {
            return $query;
        }

        return $query->whereHas('variants', function ($variantQuery) use ($selection) {
            $variantQuery->search(['item_status' => 'active']);

            foreach ($selection as $groupId => $termIds) {
                $variantQuery->whereHas('attrRelations', function ($relationQuery) use ($groupId, $termIds) {
                    $relationQuery->search([
                        'group_id' => $groupId,
                        'term_id'  => ['operator' => 'in', 'value' => $termIds],
                    ]);
                });
            }
        });
    }

    /**
     * Published products per term, across the given groups. Counted live, like WordPress
     * term counts are always current, so no write path has to clear a cache.
     *
     * @param int[] $groupIds
     * @return array<int, int> termId => product count
     */
    public static function termCounts(array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if (!$groupIds) {
            return [];
        }

        // A grouped join: counting distinct products per term has no relation equivalent.
        $rows = AttributeRelation::query()->from('fct_atts_relations as attr_rel')
            ->join('fct_product_variations as attr_var', 'attr_var.id', '=', 'attr_rel.object_id')
            ->join('posts as attr_post', 'attr_post.ID', '=', 'attr_var.post_id')
            ->search([
                'attr_rel.group_id'     => ['operator' => 'in', 'value' => $groupIds],
                'attr_var.item_status'  => 'active',
                'attr_post.post_type'   => FluentProducts::CPT_NAME,
                'attr_post.post_status' => 'publish',
            ])
            ->select('attr_rel.term_id')
            ->selectRaw('COUNT(DISTINCT attr_var.post_id) as total')
            ->groupBy('attr_rel.term_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->term_id] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Sidebar filter entries for groups named in code (shortcode custom_filters), by slug or ID.
     * Same shape the block editor saves, so the sidebar and the query need nothing else.
     *
     * @param string|array $groups "color,size" or ['color', 21]
     */
    public static function filterConfig($groups, bool $showCount = false, bool $showEmpty = false): array
    {
        if (!is_array($groups)) {
            $groups = explode(',', (string) $groups);
        }

        $maxGroups = self::maxGroups();
        $refs = [];
        foreach (array_slice($groups, 0, $maxGroups) as $ref) {
            $ref = is_scalar($ref) ? trim((string) $ref) : '';
            if ($ref !== '') {
                $refs[] = is_numeric($ref) ? (int) $ref : sanitize_title($ref);
            }
        }

        $ids = array_filter($refs, 'is_int');
        $slugs = array_diff($refs, $ids);
        if (!$refs) {
            return [];
        }

        // search() skips an empty list, so a names-only or IDs-only list needs no placeholder.
        $found = AttributeGroup::query()
            ->where(function ($query) use ($ids, $slugs) {
                $query->search([
                    'id'   => ['operator' => 'in', 'value' => array_values($ids)],
                    'slug' => ['operator' => 'or_in', 'value' => array_values($slugs)],
                ]);
            })
            ->limit($maxGroups * 2)
            ->get();

        $config = [];
        foreach ($refs as $ref) {
            foreach ($found as $group) {
                if (is_int($ref) ? (int) $group->id === $ref : strtolower($group->slug) === $ref) {
                    $config[self::filterKey((int) $group->id)] = [
                        'enabled'     => true,
                        'filter_type' => 'attribute',
                        'is_meta'     => false,
                        'group_id'    => (int) $group->id,
                        'show_count'  => $showCount,
                        'show_empty'  => $showEmpty,
                    ];
                    break;
                }
            }
        }

        return $config;
    }

    /**
     * Loads groups, their terms and counts in three queries, so a sidebar with
     * several attribute filters does not query once per group. Each render reloads.
     *
     * @param int[] $groupIds
     * @param int[] $groupIdsHidingUnusedTerms groups whose unused values are not shown
     */
    public static function preload(array $groupIds, array $groupIdsHidingUnusedTerms = []): void
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        $groupIds = array_slice($groupIds, 0, self::maxGroups());
        if (!$groupIds) {
            return;
        }
        $groupIdsHidingUnusedTerms = array_map('intval', $groupIdsHidingUnusedTerms);

        $groups = AttributeGroup::query()
            ->search(['id' => ['operator' => 'in', 'value' => $groupIds]])
            ->get()
            ->keyBy('id');
        foreach ($groupIds as $groupId) {
            self::$groupCache[$groupId] = ['group' => $groups->get($groupId), 'terms' => []];
        }

        $maxTerms = self::maxTerms();
        $productCountsByTerm = self::termCounts($groupIds);
        self::$countCache = $productCountsByTerm + self::$countCache;

        // Used values always load, so unused ones cannot fill a group's share before them.
        // Groups that show unused values add their own first terms, so one big group cannot use up another's.
        global $wpdb;
        $table = $wpdb->prefix . (new AttributeTerm())->getTable();
        $parts = [];
        foreach ($groupIds as $groupId) {
            if (!in_array($groupId, $groupIdsHidingUnusedTerms, true)) {
                $parts[] = $wpdb->prepare("(SELECT id FROM {$table} WHERE group_id = %d ORDER BY serial ASC, id ASC LIMIT %d)", $groupId, $maxTerms);
            }
        }
        $termIds = $parts ? array_map('intval', $wpdb->get_col(implode(' UNION ALL ', $parts))) : []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each part is prepared above.
        $termIds = array_values(array_unique(array_merge($termIds, array_keys($productCountsByTerm))));

        $terms = $termIds ? AttributeTerm::query()
            ->whereIn('id', $termIds)
            ->orderBy('serial', 'ASC')
            ->orderBy('id', 'ASC')
            ->get() : [];

        // Used values take a group's share first, so unused ones cannot push them out; the kept ones stay in library order.
        $usedTerms = [];
        $unusedTerms = [];
        foreach ($terms as $term) {
            $termGroupId = (int) $term->group_id;
            if (isset($productCountsByTerm[(int) $term->id])) {
                $usedTerms[$termGroupId][] = $term;
            } else {
                $unusedTerms[$termGroupId][] = $term;
            }
        }
        foreach ($groupIds as $groupId) {
            $keptTerms = array_slice($usedTerms[$groupId] ?? [], 0, $maxTerms);
            $keptTerms = array_merge($keptTerms, array_slice($unusedTerms[$groupId] ?? [], 0, $maxTerms - count($keptTerms)));
            usort($keptTerms, function ($firstTerm, $secondTerm) {
                return [(int) $firstTerm->serial, (int) $firstTerm->id] <=> [(int) $secondTerm->serial, (int) $secondTerm->id];
            });
            self::$groupCache[$groupId]['terms'] = $keptTerms;
        }
    }

    /**
     * One group as sidebar filter data, or null when it has nothing to show.
     *
     * @param int[] $checkedTermIds
     */
    public static function filterOptions(int $groupId, array $checkedTermIds = [], bool $hideEmpty = true): ?array
    {
        if (!isset(self::$groupCache[$groupId])) {
            self::preload([$groupId], $hideEmpty ? [$groupId] : []);
        }
        $group = Arr::get(self::$groupCache, $groupId . '.group');
        if (!$group) {
            return null;
        }

        $checkedTermIds = array_map('intval', $checkedTermIds);

        $options = [];
        foreach (self::$groupCache[$groupId]['terms'] as $term) {
            $count = Arr::get(self::$countCache, $term->id, 0);
            if ($hideEmpty && !$count) {
                continue;
            }

            $settings = (array) $term->settings;
            $options[] = [
                'value'   => (int) $term->id,
                'label'   => $term->title,
                'count'   => $count,
                'color'   => (string) Arr::get($settings, 'color', ''),
                'image'   => (string) Arr::get($settings, 'image', ''),
                'checked' => in_array((int) $term->id, $checkedTermIds, true),
            ];
        }

        if (!$options) {
            return null;
        }

        return [
            'group_id'   => (int) $group->id,
            'group_type' => (string) Arr::get((array) $group->settings, 'type', ''),
            'label'      => (string) $group->title,
            'options'    => $options,
        ];
    }
}
