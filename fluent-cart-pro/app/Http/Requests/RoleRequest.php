<?php

namespace FluentCartPro\App\Http\Requests;

use FluentCart\App\App;
use FluentCart\App\Models\User;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\Framework\Foundation\RequestGuard;

class RoleRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules(): array
    {

        return [
            'user_id'  => [
                'required',
                function ($attribute, $value) {
                    $userId = absint($value);
                    $user = User::query()->find($userId);
                    if (empty($user)) {
                        return __('User not found.', 'fluent-cart-pro');
                    }
                    return null;
                }
            ],
            'role_key' => [
                'required',
                'string',
                'max:50',
                function ($attribute, $value) {
                    $validRoles = array_keys(PermissionManager::getAllRoles());
                    if (!in_array($value, $validRoles)) {
                        return (__('Invalid role.', 'fluent-cart-pro'));
                    }
                    return null;
                }
            ],
        ];
    }

    /**
     * @return array
     */
    public function messages(): array
    {
        return [
            'user_id.required'  => esc_html__('Title is required.', 'fluent-cart-pro'),
            'role_key.required' => esc_html__('Key is required.', 'fluent-cart-pro'),
        ];
    }

    /**
     * @return array
     */
    public function sanitize(): array
    {
        return [
            'user_id' => 'absint',
            'role_key' => 'sanitize_text_field',
        ];
    }
}
