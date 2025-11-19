<?php

namespace FluentCart\App\Models;

use FluentCart\App\Models\Concerns\CanSearch;

class DynamicModel extends Model
{
    use CanSearch;

    public function __construct($attributes = [], $table = null)
    {
        parent::__construct($attributes);
        $this->table = $table;
    }

    protected $guarded = [];
}