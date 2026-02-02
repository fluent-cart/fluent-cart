<?php

namespace FluentCartPro\App\Models;

class UserMeta extends Model
{
    protected $table = 'usermeta';
    protected $primaryKey = 'umeta_id';

    protected $fillable = [
        'user_id',
        'meta_key',
        'meta_value'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'ID');
    }
}