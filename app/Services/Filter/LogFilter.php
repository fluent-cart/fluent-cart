<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\Activity;
use FluentCart\Framework\Support\Str;

class LogFilter extends BaseFilter
{

    public function applySimpleFilter()
    {
        $this->query->when($this->search, function ($query, $search) {
            return $query
                ->where(function ($query) use ($search) {
                    $searchOptions = [];
                    if (Str::of($search)->contains('#')) {
                        $searchableColumns = ['id'];
                        $search = Str::of($search)->remove('#')->toString();
                    } else {
                        $searchableColumns = ['id', 'title', 'content', 'module'];
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


    public function applyActiveViewFilter()
    {
        $tabsMap = $this->tabsMap();

        $this->query->when($this->activeView, function ($query, $activeView) use ($tabsMap) {
            $query->where($tabsMap[$activeView], $activeView);
        });
    }
}
