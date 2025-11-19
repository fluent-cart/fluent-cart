<?php

namespace FluentCart\Api\Resource;

use BadMethodCallException;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Http\Response\Response;
use FluentCart\Framework\Pagination\AbstractPaginator;
use FluentCart\Framework\Support\Arr;
use WP_Error;


abstract class BaseResourceApi
{
    /**
     * Request Instance
     * @var Response $response
     */
    protected $response = null;

    public static function __callStatic($method, $params)
    {
        $class = static::class;

        if (method_exists($class, $method . 'Authorized')) {
            return call_user_func_array(array(new $class, $method . 'Authorized'), $params);
        } else {
            throw new BadMethodCallException();
        }

    }

    public function __construct()
    {
        $this->response = App::getInstance()['response'];
    }

    /*
     * @return mixed
     */
    abstract public static function get(array $params = []);

    abstract public static function find($id, $params = []);

    private static function findUsing($query): Builder
    {
        return static::getQuery()->search($query);
    }

    public static function search($query, ?callable $modifyQueryUsing = null, $asCollection = false)
    {
        $query = static::findUsing($query);
        if (is_callable($modifyQueryUsing)) {
            $modifiedQuery = $modifyQueryUsing($query);
            if (is_object($modifiedQuery) && get_class($query) === Builder::class) {
                $query = $modifiedQuery;
            }
        }

        if (is_subclass_of($query, AbstractPaginator::class)) {
            if ($asCollection) {
                return $query;
            }
            return $query->toArray();
        }
        return $asCollection ? $query->get() : $query->get()->toArray();
    }

    abstract public static function create($data, $params = []);

    abstract public static function update($data, $id, $params = []);

    abstract public static function delete($id, $params = []);

    abstract static function getQuery(): Builder;

    /**
     * @param $errors array|string = [
     *       [
     *           'code' => string|int,
     *           'message' => string,
     *           'data' => Optional. Error data. Default empty string.
     *       ],
     *       string
     *  ]
     * @return WP_Error
     */
    public static function makeErrorResponse($errors): WP_Error
    {
        $wpError = new WP_Error();

        if (is_array($errors)) {
            foreach ($errors as $key => $error) {
                if (is_array($error)) {
                    $wpError->add(
                        Arr::get($error, 'code', $key),
                        Arr::get($error, 'message'),
                        Arr::get($error, 'data', '')
                    );
                } else {
                    $wpError->add($key, $error);
                }
            }
        } else {
            $wpError->add($errors, $errors);
        }
        return $wpError;
    }

    /**
     * @param string $message
     * @param mixed $data
     * @return array
     */
    public static function makeSuccessResponse($data, string $message): array
    {
        return [
            'message' => $message,
            'data' => $data
        ];
    }

}