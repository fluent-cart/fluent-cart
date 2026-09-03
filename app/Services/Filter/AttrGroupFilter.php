<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Services\Filter\BaseFilter;
use FluentCart\App\Services\Filter\Concerns\HasIdTitleSlugSearch;

class AttrGroupFilter extends BaseFilter
{
    use HasIdTitleSlugSearch;

    // Groups render in the merchant's manual drag-order (fct_atts_groups.serial),
    // the same contract terms use. Forced here so the list the sidebar drags
    // matches the order the reorder endpoint persists.
    public string $defaultSortBy = 'serial';
    public string $defaultSortType = 'asc';

    protected function parseSortBy(): string
    {
        return 'serial';
    }

    protected function parseSortType(): string
    {
        return 'asc';
    }

    public function applySelect(): void
    {
        $this->query->select(['id', 'title', 'settings', 'serial', 'is_system', 'created_at', 'updated_at']);
    }

    protected function applyWith(): void
    {
        parent::applyWith();
        $this->query = $this->query->withCount(['terms', 'usedTerms']);
    }

    protected function buildCommonQuery()
    {
        parent::buildCommonQuery();

        // Deterministic tie-breaker so two groups with the same sort key
        // (created_at, title, etc.) paginate in a stable order across requests.
        // Appended AFTER parent::buildCommonQuery() so applySort runs first
        // and id ASC trails as the secondary key. If this ran inside
        // applyWith() instead, ORDER BY clauses register in the wrong order
        // and the user's sort silently becomes the tie-breaker.
        $this->query->orderBy('id', 'ASC');
    }

    public function tabsMap(): array
    {
        return [
            'dropdown' => 'styling',
            'button'   => 'styling',
        ];
    }

    public function getModel(): string
    {
        return AttributeGroup::class;
    }

    public static function getFilterName(): string
    {
        return 'attr_groups';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'id'         => ['label' => __('ID', 'fluent-cart'), 'column' => 'id'],
            'title'      => ['label' => __('Title', 'fluent-cart'), 'column' => 'title'],
            'created_at' => ['label' => __('Created at', 'fluent-cart'), 'column' => 'created_at'],
        ];
    }

    /**
     * Intentionally empty, and verified so: AttrGroupsTable.js sends no `with`
     * at all, so there is nothing to allow. Add an entry only when a caller
     * actually needs it — do not pre-open relations.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [];
    }

    public function applyActiveViewFilter(?string $activeView = null): void
    {
        $activeView = $activeView ?? $this->activeView;
        $this->query->when($activeView, function ($query, $activeView) {
            $this->whereStyling($query, sanitize_text_field($activeView));
        });
    }

    private function whereStyling($query, string $styling): void
    {
        $query->whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(settings, '$.styling')) = ?",
            [$styling]
        );
    }
}
