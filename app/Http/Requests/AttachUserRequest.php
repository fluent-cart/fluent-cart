<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\App;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\User;
use FluentCart\Framework\Foundation\RequestGuard;

class AttachUserRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules(): array
    {
        $userId = absint(App::request()->get('user_id'));
        $user = User::query()->with('customer')->find($userId);


        return [
            'user_id' => [
                'required', 'string', 'maxLength:50',
                function ($attribute, $value) use ($user) {

                    if (empty($user)) {
                        return __('User not found.', 'fluent-cart');
                    }

                    if (!empty($user->customer)) {
                        return (__('User already linked to a customer.', 'fluent-cart'));
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
            'user_id.required' => esc_html__('User is required.', 'fluent-cart'),
            'user_id.exists'   => esc_html__('User not found.', 'fluent-cart'),
        ];
    }

    /**
     * @return array
     */
    public function sanitize(): array
    {
        return [
            'user_id' => 'absint',
        ];
    }
}
