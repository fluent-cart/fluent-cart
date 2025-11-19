<?php

namespace FluentCart\Api\Resource;

use FluentCart\Api\User;
use FluentCart\Framework\Database\Orm\Builder;

class UserResource extends BaseResourceApi
{
    public static function getQuery(): Builder
    {
        return \FluentCart\App\Models\User::query();
    }
    
    public static function get(array $params = [])
    {
        // TODO: Implement get() method.
    }

    public static function find($id, $params = [])
    {
        // TODO: Implement find() method.
    }

    /**
     * Create a new user with the given data
     *
     * @param array $data   Required. Array containing the necessary parameters
     *        [
     *              'first_name'  => (string) Required. The first name of the user,user
     *              'last_name'   => (string) Required. The last name of the user,
     *              'email'       => (string) Required. The email of the user,
     *              'password'    => (string) Optional. The password of the user,
     *        ]
     * @param array $params Optional. Additional parameters for creating an user.
     *        [
     *              // Include optional parameters, if any.
     *        ]
     * 
     */
    public static function create($data, $params = [])
    {
        (new User())->register($data);
    }

    public static function login($data)
    {
        (new User())->login($data);
    }

    public static function update($data, $id, $params = [])
    {
        // TODO: Implement update() method.
    }

    public static function delete($id, $params = [])
    {
        // TODO: Implement delete() method.
    }
}
