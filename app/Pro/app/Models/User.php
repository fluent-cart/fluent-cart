<?php

namespace FluentCartPro\App\Models;

use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\App\Models\User as FluentCartUser;

class User extends FluentCartUser
{
    public function capabilities(): \FluentCart\Framework\Database\Orm\Relations\HasOne
    {
        return $this->hasOne(UserMeta::class, 'user_id', 'ID')
            ->where('meta_key', 'wp_capabilities');
    }

    public function adminRole(): \FluentCart\Framework\Database\Orm\Relations\HasOne
    {
        return $this->hasOne(UserMeta::class, 'user_id', 'ID')
            ->where('meta_key', PermissionManager::$metaKey);
    }
}