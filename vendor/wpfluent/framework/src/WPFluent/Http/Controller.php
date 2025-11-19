<?php

namespace FluentCart\Framework\Http;

use WP_REST_Response;
use ReflectionException;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Validator\ValidationException;

abstract class Controller
{
    /**
     * Application Instance
     * @var \FluentCart\Framework\Foundation\Application
     */
    protected $app = null;

    /**
     * Request Instane
     * @var \FluentCart\Framework\Http\Request\Request
     */
    protected $request = null;

    /**
     * Response Instane
     * @var \FluentCart\Framework\Http\Response\Response
     */
    protected $response = null;

    /**
     * Validated data after validation has been passed
     * @var array
     */
    private $__validated = [];

    /**
     * Construct the controller instance
     */
    public function __construct($app = null)
    {
        $this->app = $app ?: App::getInstance();
        $this->request = $this->app['request'];
        $this->response = $this->app['response'];
    }

    /**
     * Validate the request data
     * @param  array $data
     * @param  array $rules
     * @param  array  $messages
     * @return array     
     */
    public function validate($data, $rules, $messages = [])
    {
        try {
            // @phpstan-ignore-next-line
            $validator = $this->app->validator->make($data, $rules, $messages);

            if ($validator->validate()->fails()) {
                throw new ValidationException(
                    'Unprocessable Entity!', 422, null, $validator->errors()
                );
            }

            $this->__validated = $this->request->validated($validator->validated());

            return $data;

        } catch (ValidationException $e) {

            if (defined('REST_REQUEST') && REST_REQUEST) {
                throw $e;
            };

            $this->app->doCustomAction('handle_exception', $e);
        }
    }

    /**
     * Get the valid data after validation has been passed.
     *
     * @return array
     */
    public function validated()
    {
        return (array) $this->__validated;
    }

    /**
     * Send json response
     * @param  array  $data
     * @param  integer $code
     * @return string|false The JSON encoded string, or false if it cannot be encoded.
     */
    public function json($data = [], $code = 200)
    {
        return $this->response->json($data, $code);
    }

    /**
     * Send json response
     * @param  array  $data
     * @param  integer $code
     * @return \WP_REST_Response
     */
    public function response($data = [], $code = 200)
    {
        return $this->send($data, $code);
    }

    /**
     * Send json response
     * @param  array  $data
     * @param  integer $code
     * @return \WP_REST_Response
     */
    public function send($data = [], $code = 200)
    {
        return $this->response->send($data, $code);
    }

    /**
     * Send a success json response.
     * 
     * @param  array  $data
     * @return \WP_REST_Response
     */
    public function sendSuccess($data = [])
    {
        return $this->response->sendSuccess($data, 200);
    }

    /**
     * Send an error json response
     * @param  array  $data
     * @param  integer $code
     * @return \WP_REST_Response
     */
    public function sendError($data = [], $code = 422)
    {
        return $this->response->sendError($data, $code);
    }

    /**
     * Dynamically Access components from container
     * @param  string $key
     * @return mixed
     * @throws ReflectionException
     */
    public function __get($key)
    {
        try {
            return App::getInstance($key);
        } catch(ReflectionException $e) {
            $class = get_class($this);
            wp_die("Undefined property {$key} in $class");
        }
    }
}
