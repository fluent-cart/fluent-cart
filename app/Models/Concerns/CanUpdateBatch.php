<?php

namespace FluentCart\App\Models\Concerns;

use FluentCart\App\Models\BatchQuery\Batch;
use FluentCart\Framework\Database\Orm\Builder;

trait CanUpdateBatch
{
    public static function scopeBatchUpdate(Builder $query, $values, $index = null)
    {
        $model = new static();
        $index = $index ?? $model->getKeyName();
        return (new Batch())->update($model, $values,$index);
    }
}