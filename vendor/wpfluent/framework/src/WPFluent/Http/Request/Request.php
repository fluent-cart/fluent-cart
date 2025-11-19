<?php

namespace FluentCart\Framework\Http\Request;

use Closure;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Helper;
use FluentCart\Framework\Support\MacroableTrait;
use FluentCart\Framework\Foundation\Application;
use FluentCart\Framework\Validator\ValidationException;

class Request
{
    use InteractsWithCleaningTrait,
        InteractsWithHeadersTrait,
        InputHelperMethodsTrait,
        InteractsWithFilesTrait,
        MacroableTrait {
            __call as macroCall;
        }

    /**
     * The application instance
     * @var \FluentCart\Framework\Foundation\Application
     */
    protected $app = null;

    /**
     * Validator instance.
     * 
     * @var \FluentCart\Framework\Validator\Validator
     */
    protected $validator = null;

    /**
     * PHP header variables
     * @var array
     */
    protected $headers = [];

    /**
     * PHP server variables
     * @var array
     */
    protected $server = [];

    /**
     * PHP cookie variables
     * @var array
     */
    protected $cookie = [];

    /**
     * The content of the request
     * @var mixed
     */
    protected $content = null;

    /**
     * The JSON payload of the request
     * @var array
     */
    protected $json = [];

    /**
     * PHP $_GET Superglobal
     * @var array
     */
    protected $get = [];


    /**
     * PHP $_POST Superglobal
     * @var array
     */
    protected $post = [];

    /**
     * PHP $_GET and $_POST Superglobals
     * @var array
     */
    protected $request = [];

    /**
     * WP_REST_Request instance
     * @var \WP_REST_Request
     */
    protected $wpRestRequest = false;

    /**
     * Validated data after validation has been passed
     * @var array
     */
    protected $validated = [];

    /**
     * $safe Determines the input source when data retrieval methods get called.
     * If true, the data will be returned from the $validated array.
     * If false, the data will be returned from the $request array.
     * 
     * @var boolean
     */
    protected $safe = false;

    /**
     * Construct the request instance
     * @param \FluentCart\Framework\Foundation\Application $app
     * @param $_GET $get
     * @param $_POST $post
     */
    public function __construct(Application $app, $get, $post)
    {
        $this->app = $app;
        $this->server = $_SERVER;
        $this->cookie = $_COOKIE;
        
        $this->request = array_merge(
            $this->get = $this->clean($get),
            $this->post = $this->clean($post)
        );
    }

    /**
     * Variable exists
     * @param  string $key
     * @return bool
     */
    public function exists($key)
    {
        return Arr::has($this->inputs(), $key);
    }

    /**
     * Variable exists and has truthy value
     * @param  string $key
     * @return bool
     */
    public function has($key)
    {
        $inputs = $this->inputs();
        
        return isset($inputs[$key]) && !empty($inputs[$key]);
    }

    /**
     * Any variable exists and has truthy value
     * @param  array|string $keys
     * @return bool
     */
    public function hasAny($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        if ($data = $this->only($keys)) {
            return (bool) count(array_filter($data));
        }

        return false;
    }

    /**
     * Calls a callback if has value, otherwise
     *  calls another/second callback if given.
     * 
     * @param  string $key
     * @param  \Closure $has
     * @param  \Closure|null $hasnot
     * @return mixed
     */
    public function whenHas($key, Closure $has, ?Closure $hasnot = null)
    {
        if ($this->has($key)) {
            return $has($key, $this->get($key));
        }

        return ($hasnot ? $hasnot($key) : null);
    }

    /**
     * Checks if a key is missing in the request.
     * 
     * @param  string $key
     * @return bool
     */
    public function missing($key)
    {
        return !$this->has($key);
    }

    /**
     * Calls the given callback if the provided key is missing.
     * 
     * @param  string $key
     * @param  \Closure $callback
     * @return mixed
     */
    public function whenMissing($key, Closure $callback)
    {
        if ($this->missing($key)) {
            return $callback($key, $this);
        }

        return $this;
    }

    /**
     * Set an item into the request inputs
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function set($key, $value)
    {
        Arr::set($this->request, $key, $value);

        return $this;
    }

    /**
     * Retrive all the items from the request inputs
     * @return array
     */
    public function all()
    {
        return $this->get();
    }

    /**
     * Retrieve an item from the request inputs
     * @param  string|null $key
     * @param  mixed $default
     * @return mixed
     */
    public function get($key = null, $default = null)
    {
        return Helper::dataGet($this->inputs(), $key, $default);
    }

    /**
     * Check the content-type for JSON
     * 
     * @return boolean
     */
    public function isJson()
    {
        if (!($isJson = $this->is_json_content_type())) {
            if (!$isJson) {
                if ($body = $this->get_body()) {
                    json_decode($body);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $isJson = true;
                    }
                } elseif ($this->isRest()) {
                    $isJson = true;
                }
            }
        }

        if (
            !$isJson &&
            isset($_SERVER['CONTENT_TYPE']) &&
            strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false
        ) {
            $requestBody = file_get_contents('php://input');

            if (!empty($requestBody)) {
                $this->json = json_decode($requestBody, true);
                $isJson = json_last_error() === JSON_ERROR_NONE;
            }
        }

        return $isJson;
    }

    /**
     * Check if current request wants JSON response.
     * 
     * @return boolean
     */
    public function wantsJson()
    {
        $wants = $this->header('accept');

        if ($wants === '*/*') {
            return $this->isJson();
        }

        return $wants === 'application/json';
    }

    /**
     * Check if current request is a Rest request
     * 
     * @return boolean
     */
    public function isRest()
    {
        $isRest = false;

        if ($this->app->isUnitTesting()) {
            return $isRest;
        }

        $url = $this->url();

        $niddle = $this->app->config->get(
            'app.rest_namespace'
        ).'/__endpoints';

        if (str_contains($url, $niddle)) {
            return $isRest;
        }

        $isRest = defined('REST_REQUEST') && REST_REQUEST;

        if (!$isRest) {
            if (!get_option('permalink_structure')) {
                $isRest = $this->query('rest_route', false);
            } else {
                $parsed = parse_url($url);
                $path = isset($parsed['path']) ? $parsed['path'] : '';
                $isRest = str_starts_with($path, '/wp-json');
            }
        }

        return $isRest;
    }

    /**
     * Determine if the request is initiated by WordPress.
     * 
     * @return boolean
     */
    public function isInternal()
    {
        return $GLOBALS['wp_rest_server']->is_dispatching();
    }

    /**
     * Retrieve an item from the json payload of the request.
     * 
     * @param  string $key
     * @param  string $default
     * @return mixed
     */
    public function json($key = null, $default = null)
    {
        if (!$this->isJson()) return;
        
        if (!isset($this->json)) {
            $json = $this->get_json_params() ?: $this->getContent();
            
            $this->json = (array) json_decode($json, true);
        }

        if (is_null($key)) {
            return $this->json;
        }

        return Helper::dataGet($this->json, $key, $default);
    }

    /**
     * Retrieve an item from the PHP $_SERVER array
     * @param  string $key
     * @param  string $default
     * @return mixed
     */
    public function server($key = null, $default = null)
    {
        return $key ? Arr::get($this->server, $key, $default) : $this->server;
    }

    /**
     * Retrieve an item from the cookie
     * @param  string $key
     * @param  mixed $default
     * @return mixed
     */
    public function cookie($key = null, $default = null)
    {
        $cookie = $key ? Arr::get(
            $this->cookie, $key, $default
        ) : $this->cookie;

        return json_decode(base64_decode($cookie, true));
    }

    /**
     * Get an item from the PHP $_GET array
     * @param  string $key
     * @param  mixed $default
     * @return mixed
     */
    public function query($key = null, $default = null)
    {
        return $key ? Arr::get($this->get, $key, $default) : $this->get;
    }

    /**
     * Get an item from the PHP $_POST array
     * @param  string $key
     * @param  mixed $default
     * @return mixed
     */
    public function post($key = null, $default = null)
    {
        return $key ? Arr::get($this->post, $key, $default) : $this->post;
    }

    /**
     * Return the only items given in the args
     * @param  array $keys
     * @return array
     */
    public function only($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        return Arr::only($this->inputs(), $keys);
    }

    /**
     * Return a subset of the request inputs except the given keys
     * @param  array $keys
     * @return array
     */
    public function except($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        
        return Arr::except($this->inputs(), $keys);
    }

    /**
     * Merge array with the request inputs
     * @param  array  $data
     * @return self
     */
    public function merge(array $data = [])
    {
        $this->request = array_replace($this->inputs(), $data);

        return $this;
    }

    /**
     * Merge array with the request inputs if the
     * key(s) is missing from the request.
     * 
     * @param  array  $data
     * @return self
     */
    public function mergeIfMissing(array $data = [])
    {
        $all = $this->inputs();

        $this->merge(Arr::mergeMissing($data, $all));

        return $this;
    }

    /**
     * Merge new input into the request's input, but only when
     * that key is present in the request but value is missing.
     *
     * @param  array  $input
     * @return $this
     */
    public function mergeMissing(array $input)
    {
        return $this->merge(Helper::collect($input)
            ->filter(function($value, $key) {
                return $this->missing($key);
            })->toArray()
        );
    }

    /**
     * Returns the request body content.
     *
     * @return mixed
     */
    public function getContent()
    {
        if (null === $this->content || false === $this->content) {
            $this->content = file_get_contents('php://input');
        }

        return $this->content;
    }

    /**
     * Merges the input arrays from the WP_REST_Request.
     * 
     * @param  \WP_REST_Request $wpRestRequest
     * @return void
     */
    public function mergeInputsFromRestRequest($wpRestRequest)
    {
        $this->request = array_merge(
            $this->request, $wpRestRequest->get_params()
        );
        
        $this->post = array_merge(
            $this->post, $wpRestRequest->get_body_params()
        );

        $this->get = array_merge(
            $this->get, $wpRestRequest->get_query_params()
        );

        $this->mergerHeaders($wpRestRequest);

        $this->wpRestRequest = true;
    }

    /**
     * Merge the headers from the WP_REST_Request.
     * 
     * @param  \WP_REST_Request $wpRestRequest
     * @return void
     */
    protected function mergerHeaders($wpRestRequest)
    {
        $headers = [];

        foreach ($wpRestRequest->get_headers() as $key => $header) {
            $headers[strtoupper($key)] = reset($header);
        }

        $this->headers = array_merge($this->headers, $headers);

        if (
            !isset($this->headers['CONTENT_TYPE']) || (
                isset($this->headers['CONTENT_TYPE']) && 
                $this->headers['CONTENT_TYPE'] !== true
        )) {
            if ($this->isJson()) {
                $this->headers['CONTENT_TYPE'] = 'application/json';
            }
        }
    }

    /**
     * Retrieve an input item from the request.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function input($key = null, $default = null)
    {
        return Arr::get($this->inputs(), $key, $default);
    }

    /**
     * Remove a key(s) from the $request array
     * @param  mixed $key
     * @return self
     */
    public function forget($key)
    {
        Arr::forget($this->request, $key);

        return $this;
    }

    /**
     * Get all inputs
     * 
     * @return array
     */
    protected function inputs()
    {
        if (!$this->wpRestRequest) {
            if ($this->app->bound('wprestrequest')) {
                // @phpstan-ignore-next-line
                $this->mergeInputsFromRestRequest($this->app->wprestrequest);
            }
        }

        return $this->safe === true ? $this->validated : $this->request;
    }

    /**
     * To get item(s) from validated inputs
     *
     * @return self
     */
    public function safe()
    {
        $clone = clone $this;

        $clone->safe = true;

        return $clone;
    }

    /**
     * Get user ip address
     * @return string
     */
    public function getIp()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $this->server('HTTP_CLIENT_IP');
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $this->server('HTTP_X_FORWARDED_FOR');
        } else {
            $ip = $this->server('REMOTE_ADDR');
        }

        return $ip;
    }

    /**
     * Get the request method.
     * 
     * @return string
     */
    public function method()
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    /**
     * Get the URL (no query string) for the request.
     *
     * @return string
     */
    public function url()
    {
        return preg_replace('/\?.*/', '', $this->getFullUrl());
    }

    /**
     * Get the full URL for the request.
     *
     * @return string
     */
    public function getFullUrl()
    {
        return get_site_url() . rtrim($_SERVER['REQUEST_URI'], '/');
    }

    /**
     * Validate the request.
     *
     * @param  array $rules
     * @param  array $messages
     * @return mixed
     * @throws \FluentCart\Framework\Validator\ValidationException
     */
    public function validate(array $rules, array $messages = [])
    {
        $this->validator = $this->app->make('validator')->make(
            $data = $this->all(), $rules, $messages
        );

        if ($this->validator->validate()->fails()) {
            throw new ValidationException(
                'Unprocessable Entity!', 422, null, $this->validator->errors()
            );
        }

        $this->validated = $this->validator->validated();

        return $data;
    }

    /**
     * Get the validator instance.
     * 
     * @return \FluentCart\Framework\Validator\Validator
     */
    public function getValidator()
    {
        return $this->validator;
    }

    /**
     * Get the valid data after validation has been passed.
     *
     * @return array
     */
    public function validated($data = [])
    {
        if ($data) {
            return $this->validated = $data;
        }

        return (array) $this->validated;
    }

    /**
     * Abort the request.
     * 
     * @param  integer $status
     * @param  string  $message
     * @return \WP_REST_Response
     */
    public function abort($status = 403, $message = null)
    {
        $this->maybeThrowValidationException($status);

        if (!$message && !is_numeric($status) && is_string($status)) {
            $message = $status;
            $status = 403;
        }

        $message = $message ?: 'Request has benn aborted.';

        return new \WP_REST_Response(
            is_array($message) ? $message : ['message' => (string) $message], $status
        );
    }

    /**
     * Terminate the request.
     * 
     * @param  integer $status
     * @param  string  $message
     * @return \WP_REST_Response
     */
    public function terminate($status = 200, $message = null)
    {
        $this->maybeThrowValidationException($status);

        if (!$message && !is_numeric($status) && is_string($status)) {
            $message = $status;
            $status = 403;
        }

        $message = $message
            ?: "Request has benn terminated with status {$status}.";

        return wp_send_json(
            is_array($message) ? $message : ['message' => (string) $message], $status
        );
    }

    /**
     * Throw a validation exception if status is validation exception.
     * @param  mixed $status
     * @return void
     * @throws \FluentCart\Framework\Validator\ValidationException
     */
    protected function maybeThrowValidationException($status)
    {
        if (is_object($status)) {
            if (method_exists($status, 'errors')) {
                throw new ValidationException(
                    'Unprocessable Entity!', 422, null, $status->errors()
                );
            }
        }
    }

    /**
     * Get an input element from the request.
     *
     * @param  string $key
     * @return mixed
     */
    public function __get($key)
    {
        if ($value = $this->get($key)) {
            return $value;
        }

        return $this->file($key);
    }

    /**
     * Retrieves the currently logged in user.
     * 
     * @return \FluentCart\Framework\Http\Request\WPUserProxy
     */
    public function user()
    {
        return $this->app->user();
    }

    /**
     * Dynamyc method calls (specially for WP_rest_request)
     * @param  string $method
     * @param  array $params
     * @return mixed
     */
    public function __call($method, $params = [])
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $params);
        }

        if ($method == 'route') {
            if ($params) {
                // @phpstan-ignore-next-line
                return $this->app->route->{$params[0]};
            }
            // @phpstan-ignore-next-line
            return $this->app->route;
        }
        
        if ($this->app->bound('wprestrequest')) {
            // @phpstan-ignore-next-line
            if (!method_exists($this->app->wprestrequest, $method)) {
                $method = strtolower(
                    preg_replace([
                        '/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'
                    ], '$1_$2', $method)
                );
            }

            return call_user_func_array([
                // @phpstan-ignore-next-line
                $this->app->wprestrequest, $method], $params
            );
        }
    }
}
