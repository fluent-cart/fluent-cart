<?php

namespace FluentCart\App\Models;


use FluentCart\App\Services\Permission\PermissionManager;

class User extends Model
{
    protected $table = 'users';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'ID';

    protected $guarded = ['password'];

    /**
     * Credential columns of the WordPress `users` table that must never be
     * serialized into an API response.
     *
     * `$guarded` above is mass-assignment protection only (GuardsAttributes) and
     * has no effect on serialization — and it names `password`, which is not even
     * a real column. Serialization hiding lives here (HidesAttributes), and it is
     * applied by HasAttributes::getArrayableItems(), so it covers toArray(),
     * toJson() and every `with('wpUser')` eager load at once.
     *
     * This affects serialization ONLY. Direct property access ($user->user_pass)
     * still works, so internal reads are unaffected.
     *
     * @var array
     */
    protected $hidden = [
        'user_pass',            // bcrypt/phpass password hash
        'user_activation_key',  // password-reset / new-user activation token
    ];


    /**
     * Check if the user has a specific permission.
     * @param string|array $permission
     * @return bool
     */
    public function userCan($permission): bool
    {
        return PermissionManager::hasPermission($permission, $this->ID);
    }

    /**
     * Check if the user has a specific permission.
     * @param string|array $permission
     * @return bool
     */
    public function userCanAny($permission): bool
    {
        return PermissionManager::hasAnyPermission($permission, $this->ID);
    }

    /**
     * @todo: Move this to Pro Plugin's Controller
     */
    public function setStoreRole($role)
    {
        $wpUser = get_user_by('ID', $this->ID);

        if (user_can($wpUser, 'manage_options')) {
            return new \WP_Error('super_admin', __('The user already have all the accesses as part of Administrator Role', 'fluent-cart'));
        }

        return update_user_meta($this->ID, '_fluent_cart_admin_role', $role);
    }

    public function customer(): \FluentCart\Framework\Database\Orm\Relations\HasOne
    {
        return $this->hasOne(Customer::class, 'user_id');
    }

}
