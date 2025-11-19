<?php

namespace FluentCart\App\Models;

use FluentCart\Framework\Database\Orm\Relations\MorphTo;

/**
 *  Label Relationship Model - DB Model for Label Relationship table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class LabelRelationship extends Model
{
	protected $table = 'fct_label_relationships';

	protected $fillable = [
		'label_id',
		'labelable_id',
		'labelable_type',
	];

    protected $casts = [
        'label_id'   => 'integer',
    ];

    /**
     * Get the parent labelable model (order etc).
     */
    public function labelable(): MorphTo
    {
        return $this->morphTo();
    }
}
