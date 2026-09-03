<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Services\Filter\BaseFilter;
use FluentCart\App\Services\Filter\Concerns\HasIdTitleSlugSearch;

class AttrTermFilter extends BaseFilter
{
    use HasIdTitleSlugSearch;
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

    protected ?int $groupId = null;

    public function setGroupId(int $groupId): self
    {
        $this->groupId = $groupId;
        return $this;
    }

    public function applySelect(): void
    {
        $this->query->select(['id', 'title', 'settings', 'serial', 'created_at']);
    }

    protected function buildCommonQuery()
    {
        if ($this->groupId) {
            $this->query->where('group_id', $this->groupId);
        }

        parent::buildCommonQuery();

        // Default sort is serial ASC, but two terms can share the same serial
        // (the swap-on-reorder logic guarantees uniqueness within a group at
        // the moment of reorder, but bulk imports or partial states can leave
        // ties). Append id ASC as a deterministic tie-breaker so paginated
        // listings stay stable across requests.
        $this->query->orderBy('id', 'ASC');
    }

    public function tabsMap(): array
    {
        return [];
    }

    public function getModel(): string
    {
        return AttributeTerm::class;
    }

    public static function getFilterName(): string
    {
        return 'attr_terms';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'serial'     => ['label' => __('Serial', 'fluent-cart'), 'column' => 'serial'],
            'title'      => ['label' => __('Title', 'fluent-cart'), 'column' => 'title'],
            'created_at' => ['label' => __('Created at', 'fluent-cart'), 'column' => 'created_at'],
        ];
    }

    /**
     * Intentionally empty, and verified so: AttrTermsTable.js sends no `with`
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
        // Terms have no tab views; this hook is intentionally a no-op.
    }
}
