<?php

namespace FluentCart\App\Models;

use FluentCart\App\Services\PlanUpgradeService;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class ScheduledAction extends Model
{
    protected $table = 'fct_scheduled_actions';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'scheduled_at',
        'action',
        'status',
        'group',
        'object_id',
        'object_type',
        'completed_at',
        'retry_count',
        'data',
        'response_note',
    ];

    public function setDataAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $this->attributes['data'] = json_encode($value);
        } else {
            $this->attributes['data'] = $value;
        }
    }

    public function getDataAttribute($value)
    {

        $decoded = json_decode($value, true);

        if ($decoded && is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

}
