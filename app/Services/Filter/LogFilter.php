<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\Activity;
use FluentCart\Framework\Support\Str;

class LogFilter extends BaseFilter
{

    public function applySimpleFilter(?string $search = null): void
    {
        $this->query->when($search ?? $this->search, function ($query, $search) {
            return $query
                ->where(function ($query) use ($search) {
                    $searchOptions = [];
                    if (Str::of($search)->contains('#')) {
                        $searchableColumns = ['id'];
                        $search = Str::of($search)->remove('#')->toString();
                    } else {
                        $searchableColumns = ['id', 'title', 'content', 'module_name'];
                    }

                    foreach ($searchableColumns as $index => $column) {
                        $searchOptions[$column] = [
                            'column'   => $column,
                            'operator' => $index === 0 ? 'like_all' : 'or_like_all',
                            'value'    => $search
                        ];
                    }
                    $query->search($searchOptions);
                });
        });
    }


    public function tabsMap(): array
    {
        return [
            'success' => 'status',
            'warning' => 'status',
            'error'   => 'status',
            'failed'  => 'status',
            'info'    => 'status',
            'api'     => 'log_type',
        ];
    }

    public function getModel(): string
    {
        return Activity::class;
    }

    public static function getFilterName(): string
    {
        return 'logs';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'id'         => ['label' => __('ID', 'fluent-cart'), 'column' => 'id'],
            'title'      => ['label' => __('Title', 'fluent-cart'), 'column' => 'title'],
            'created_at' => ['label' => __('Created At', 'fluent-cart'), 'column' => 'created_at'],
        ];
    }

    /**
     * No screen key, because there is no screen: the admin table (LogTable.js)
     * does not override `Table::with()`, so nothing in the admin sends a `with`
     * to the activity feed at all. Inventing a context key for a context that
     * does not exist would be an entry with no reader.
     *
     * PUBLIC key — `user` is the relation name a consumer asks an activity
     * endpoint for, and it is the name the repo's own remaining consumer sends:
     * the Phase 16 throughput guard (`tests/integration/throughput-guards.php`,
     * `throughput-activity-feed`) materializes `$activity->user` for every row
     * inside its query-count boundary and asserts the count does not grow from
     * 5 rows to 25. Without an eager-load path that guard cannot pass at all —
     * the actor would be lazily fetched per row — so the only way to drop this
     * key is to delete the N+1 assertion, which is the gate itself.
     *
     * No further permission bar: the feed mounts under `AdminPolicy`, so a
     * caller here is already a super admin. The grant is narrow anyway —
     * `Activity::user()` selects exactly ID, display_name and user_email on the
     * relation itself.
     *
     * `Activity::activity()` is a `morphTo()` and MUST NEVER be allowlisted at
     * any depth. Its target is chosen by the `module_type` column of each row,
     * so a single `with=activity` would hydrate whatever model the log happens
     * to point at — orders, subscriptions, licenses — bypassing every
     * per-model permission check this map exists to enforce.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [
            'user' => [$this, 'publicUser'],
        ];
    }

    /**
     * The actor behind a log row. No select here: `Activity::user()` already
     * narrows itself to ID, display_name and user_email.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicUser($query)
    {
        return $query->with(['user']);
    }


    public function applyActiveViewFilter(?string $activeView = null): void
    {
        $activeView = $activeView ?? $this->activeView;
        $tabsMap = $this->tabsMap();

        $this->query->when($activeView, function ($query, $activeView) use ($tabsMap) {
            $query->where($tabsMap[$activeView], $activeView);
        });
    }
}
