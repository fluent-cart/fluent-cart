<?php

namespace FluentCart\App\Services\Filter\Concerns;

use FluentCart\Framework\Support\Str;

trait HasIdTitleSlugSearch
{
    public function applySimpleFilter(?string $search = null): void
    {
        // First honor the shared "column operator value" syntax (e.g. title = Red,
        // id > 5, created_at :: from - to) that BaseFilter provides and every other
        // FluentCart table filter uses. Returns true when an operator search was
        // applied; otherwise fall through to the id/title/slug free-text search.
        if ($this->applySimpleOperatorFilter($search)) {
            return;
        }

        $search = $search ?? $this->search;
        $this->query->when($search, function ($query, $search) {
            return $query
                ->where(function ($query) use ($search) {
                    $searchOptions = [];
                    if (Str::of($search)->contains('#')) {
                        $searchableColumns = ['id'];
                        $search = Str::of($search)->remove('#')->toString();
                    } else {
                        $searchableColumns = ['id', 'title', 'slug'];
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
}
