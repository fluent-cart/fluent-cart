<?php

namespace FluentCartPro\App\Http\Controllers;


use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\Framework\Http\Request\Request;

use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCartPro\App\Http\Requests\RoleRequest;
use FluentCartPro\App\Models\User;

class RoleController extends Controller
{
    public function index(Request $request): \WP_REST_Response
    {
        $roles = Collection::make(PermissionManager::getAllRoles())->map(function ($role) {
            return [
                'title'       => $role['title'],
                'description' => $role['descriptions']
            ];
        })->all();

        return $this->sendSuccess([
            'roles' => $roles
        ]);
    }

    public function find(Request $request, $key)
    {
        return;
    }

    public function create(RoleRequest $request)
    {

        $data = $request->getSafe(
            $request->sanitize()
        );

        $isUpdated = PermissionManager::attachRole(
            $data['user_id'],
            $data['role_key'],
        );

        if ($isUpdated instanceof \WP_Error) {
            return $this->sendError([
                'message' => $isUpdated->get_error_message()
            ]);
        } else {
            return $this->sendSuccess([
                'message'    => __('Role synced successfully', 'fluent-cart-pro'),
                'is_updated' => $isUpdated
            ]);
        }


    }

    public function update(Request $request, $key)
    {

    }

    public function delete(Request $request, $key)
    {
        if (!$key) {
            return $this->sendError([
                'message' => __('Role key is required', 'fluent-cart-pro')
            ]);
        }

        $data = [
            'user_id'  => Arr::get($request->all(), 'user_id'),
            'role_key' => sanitize_text_field($key)
        ];

        $isUpdated = PermissionManager::detachRole(
            $data['user_id'],
            $data['role_key'],
        );

        if ($isUpdated instanceof \WP_Error) {
            return $this->sendError([
                'message' => $isUpdated->get_error_message()
            ]);
        } else {
            return $this->sendSuccess([
                'message' => __('Role deleted successfully', 'fluent-cart-pro')
            ]);
        }
    }

    public function managers(): \WP_REST_Response
    {
        return $this->sendSuccess([
            'managers' => PermissionManager::getUsersWithShopRole()
        ]);
    }

    public function userList(Request $request): \WP_REST_Response
    {

        $search = sanitize_text_field($request->get('search'));
        $userIds = sanitize_text_field($request->get('user_ids'));
        $userIds = Arr::wrap($userIds);

        $users = User::query()->select(['ID', 'display_name as name', 'user_email as email'])
            ->when($search, function ($query, $search) {
                return $query->where('display_name', 'like', '%' . $search . '%')
                    ->orWhere('user_email', 'like', '%' . $search . '%');
            })
            ->when($userIds, function ($query, $userIds) {
                return $query->orWhereIn('ID', $userIds);
            })
            ->whereDoesntHave('adminRole')
            ->paginate();

        return $this->sendSuccess([
            'users' => $users
        ]);
    }

}
