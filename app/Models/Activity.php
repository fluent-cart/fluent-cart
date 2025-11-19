<?php

namespace FluentCart\App\Models;

use FluentCart\Framework\Database\Orm\Relations\MorphTo;
use FluentCart\Framework\Database\Orm\Relations\hasOne;
use FluentCart\App\Models\Concerns\CanSearch;

class Activity extends Model
{
    use CanSearch;

    protected $table = 'fct_activity';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'status',
        'log_type',
        'module_id',
        'module_type',
        'module_name',
        'title',
        'content',
        'user_id',
        'read_status',
        'created_by'
    ];

    protected $casts = [
        'module_id'   => 'integer',
    ];

    /**
     * Get the parent activity model (order etc).
     */
    public function activity(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'ID', 'user_id')
                ->select(['ID', 'display_name', 'user_email']);
    }
}
