<?php

namespace FluentCart\App\Models\Concerns;

use FluentCart\App\Models\Activity;
use FluentCart\Framework\Database\Orm\Relations\MorphMany;

trait HasActivity
{
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'module')
            ->orderBy(sanitize_sql_orderby('created_at'), sanitize_sql_orderby('DESC'))
            ->orderBy(sanitize_sql_orderby('id'), sanitize_sql_orderby('DESC'));
    }
}