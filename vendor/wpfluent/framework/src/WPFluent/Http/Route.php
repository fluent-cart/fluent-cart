<?php

namespace FluentCart\Framework\Http;

use Closure;
use Exception;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use ReflectionClass;
use BadMethodCallException;
use InvalidArgumentException;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\Framework\Support\Pipeline;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Http\Request\WPUserProxy;
use FluentCart\Framework\Http\SubstituteParameters;
use FluentCart\Framework\Http\Middleware\RateLimiter;
use FluentCart\Framework\Validator\ValidationException;
use FluentCart\Framework\Database\Orm\ModelNotFoundException;
use FluentCart\Framework\Http\Response\Response as WPFluentResponse;

class Route
{
    use SubstituteParameters;

    /**
     * Application Instance
     * @var \FluentCart\Framework\Foundation\Application
     */
    protected $app = null;

    /**
     * Route name
     * @var string
     */
    protected $name = null;

    /**
     * Rest namespace from config
     * @var string
     */
    protected $restNamespace = null;

    /**
     * Full URI
     * @var string
     */
    protected $uri = null;

    /**
     * Compiled rest endpoint
     * @var string
     */
    protected $compiled = null;

    /**
     * Route meta data
     * @var array
     */
    protected $meta = [];

    /**
     * Rest Handler/Callback before parsing
     * @var string
     */
    protected $handler = null;

    /**
     * Rest Handler/Callback after parsing
     * @var callable|string
     */
    protected $action = null;

    /**
     * Rest route action info after parsing
     * @var array
     */
    protected $actionInfo = [];

    /**
     * Policy Handler/Callback after parsing
     * @var string
     */
    protected $permissionHandler = [];

    /**
     * HTTP Methods
     * @var string
     */
    protected $method = null;

    /**
     * Rest options
     * @var array
     */
    protected $options = [];

    /**
     * Route where constraints
     * @var array
     */
    protected $wheres = [];

    /**
     * Rest namespace
     * @var string
     */
    protected $namespace = null;

    /**
     * Policy Handler/Callback after parsing
     * @var callable|string
     */
    protected $policyHandler = null;

    /**
     * Route Middleware
     * @var array
     */
    protected $middleware = [
        'before' => [],
        'after' => []
    ];

    /**
     * Skips middlewar if true
     *
     * @var boolean
     */
    protected $skipMiddleware = false;

    /**
     * Predefined Regex foe where constraints
     * @var array
     */
    protected $predefinedNamedRegx = [
        'int' => '[0-9]+',
        'alpha' => '[a-zA-Z]+',
        'alpha_num' => '[a-zA-Z0-9]+',
        'alpha_num_dash' => '[a-zA-Z0-9-_]+'
    ];

    /**
     * Route parameters
     * @var null|array
     */
    protected $parameters = null;

    /**
     * Route substituted parameters
     *
     * @var null|array
     */
    protected $substitutedParameters = [];

    /**
     * Is route signed
     * 
     * @var boolean
     */
    protected $signed = false;

    /**
     * Route signature.
     * 
     * @var array
     */
    protected $endpointSignature = [];

    /**
     * Construct the route instance
     *
     * @param \FluentCart\Framework\Foundation\Application $app
     * @param string $restNamespace
     * @param string $uri
     * @param string $handler
     * @param string $method
     */
    public function __construct($app, $restNamespace, $uri, $handler, $method)
    {
        $this->app = $app;
        $this->restNamespace = $restNamespace;
        $this->uri = $uri;
        $this->handler = $handler;
        $this->method = $method;

        $this->preparefrontendHandlers($handler);
    }

    /**
     * Map the route to be used in front-end.
     *
     * @param mixed $handler
     * @return null
     */
    protected function preparefrontendHandlers($handler)
    {
        $endpointsUrl = $this->app->config->get('app.slug') . '/__endpoints';

        if (get_option('permalink_structure')) {
            $url = $this->app->request->url();
        } else {
            $url = $this->app->request->query('rest_route');
        }

        if (
            !str_contains($url ?? '', $endpointsUrl)
            || $handler instanceof Closure
        ) {
            return;
        }

        $action = trim($this->app->parseRestHandler($handler), '\\');

        if (!str_contains($action, '@')) {
            $action .= '@__invoke';
        }

        [$controller, $cb] = Str::parseCallback($action);

        $this->endpointSignature = [$controller, "_{$cb}"];

        $controller = str_replace('\\', '.', $controller);

        // @phpstan-ignore-next-line
        $endpoints = $this->app->endpoints;

        $endpoints[$controller]["_{$cb}"] = [
            'uri' => $this->uri,
            'methods' => explode(',', $this->method)
        ];

        // @phpstan-ignore-next-line
        $this->app->endpoints = $endpoints;
    }

    /**
     * Alternative constructor
     *
     * @param \FluentCart\Framework\Foundation\Application $app
     * @param string $namespace
     * @param string $uri
     * @param string $handler
     * @param string $method
     * @return self
     */
    public static function create($app, $namespace, $uri, $handler, $method)
    {
        return new static($app, $namespace, $uri, $handler, $method);
    }

    /**
     * Set route meta
     *
     * @param string|array $key
     * @param mixed $value
     * @return self
     */
    public function meta($key, $value = null)
    {
        $meta = is_array($key) ? $key : [$key => $value];

        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * Get route meta
     *
     * @param string $key
     * @return mixed
     */
    public function getMeta($key = '')
    {
        if (isset($this->meta[$key])) {
            return $this->meta[$key];
        }

        return $this->meta;
    }

    /**
     * Get route options
     *
     * @return mixed
     */
    public function getOptions()
    {
        return $this->getOption();
    }

    /**
     * Get route options
     *
     * @param string $key
     * @return mixed
     */
    public function getOption($key = null)
    {
        return $key ? $this->options[$key] : $this->options;
    }

    /**
     * Get route action information
     * @param string $key
     * @return mixed
     */
    public function getAction($key = '')
    {
        if ($key && array_key_exists($key, $this->actionInfo)) {
            return $this->actionInfo[$key];
        }

        return $this->actionInfo;
    }

    /**
     * Set a where constrain into the route
     *
     * @param string $identifier
     * @param string $value
     * @return self
     */
    public function where($identifier, $value = null)
    {
        if (!is_null($value)) {
            $this->wheres[$identifier] = $this->getValue($value);
        } else {
            foreach ($identifier as $key => $value) {
                $this->wheres[$key] = $this->getValue($value);
            }
        }

        return $this;
    }

    /**
     * Add an integer type route constraint
     *
     * @param string $identifiers
     * @return self
     */
    public function int($identifiers)
    {
        $identifiers = is_array($identifiers) ? $identifiers : func_get_args();

        foreach ($identifiers as $identifier) {
            $this->wheres[$identifier] = '[0-9]+';
        }

        return $this;
    }

    /**
     * Add an alpha type route constraint
     *
     * @param string $identifiers
     * @return self
     */
    public function alpha($identifiers)
    {
        $identifiers = is_array($identifiers) ? $identifiers : func_get_args();

        foreach ($identifiers as $identifier) {
            $this->wheres[$identifier] = '[a-zA-Z]+';
        }

        return $this;
    }

    /**
     * Add an alphanum type route constraint
     *
     * @param string $identifiers
     * @return self
     */
    public function alphaNum($identifiers)
    {
        $identifiers = is_array($identifiers) ? $identifiers : func_get_args();

        foreach ($identifiers as $identifier) {
            $this->wheres[$identifier] = '[a-zA-Z0-9]+';
        }

        return $this;
    }

    /**
     * Add an alphanumdash type route constraint
     *
     * @param string $identifiers
     * @return self
     */
    public function alphaNumDash($identifiers)
    {
        $identifiers = is_array($identifiers) ? $identifiers : func_get_args();

        foreach ($identifiers as $identifier) {
            $this->wheres[$identifier] = '[a-zA-Z0-9-_]+';
        }

        return $this;
    }

    /**
     * Set the route before middleware
     *
     * @param array|string $middleware
     * @return self
     */
    public function before(...$middleware)
    {
        return $this->middleware('before', ...$middleware);
    }

    /**
     * Set the route after middleware
     *
     * @param array|string $middleware
     * @return self
     */
    public function after(...$middleware)
    {
        return $this->middleware('after', ...$middleware);
    }

    /**
     * Set the route middleware
     * @param array $middleware
     * @return self
     */
    public function middleware($type = 'before', ...$middleware)
    {
        if (is_array($middleware[0])) {
            $middleware = reset($middleware);
        }

        $this->middleware[$type] = array_merge(
            $this->middleware[$type], $middleware
        );

        return $this;
    }

    /**
     * Set the default route policy.
     * 
     * @return self
     */
    public function withDefaultPolicy()
    {
        return $this->withPolicy(
            // @phpstan-ignore-next-line
            $this->app->__namespace__.'\\App\\Http\\Policies\\Policy'
        );
    }

    /**
     * Set the route policy
     *
     * @param mixed $handler
     * @param string|null $method
     * @return self
     */
    public function withPolicy($handler, $method = null)
    {
        if (is_array($handler = $method ? func_get_args() : $handler)) {
            $handler = implode('@', $handler);
        }

        $this->policyHandler = $handler;

        if (is_string($handler) && !$this->app->hasNamespace($handler)) {
            $this->setPolicyHandlerWithNamespace(
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4)
            );
        }

        return $this->addRouteInfo($handler);
    }

    /**
     * Check if the request is from CLI;
     * 
     * @return bool
     */
    protected function fromCli()
    {
        $hash = $this->app->request->header('X-From-CLI');

        $slugHash = md5($this->app->config->get('app.slug'));

        return $hash === $slugHash;
    }

    /**
     * Add route information for CLI command.
     * 
     * @param mixed $handler
     * @return self
     */
    protected function addRouteInfo($handler)
    {
        if (!$this->fromCli()) {
            return $this;
        }

        if ($handler instanceof Closure) {
            $policyHandler = 'Closure';
        } else {
            $policyHandler = $this->resolvePolicyHandler();
            if (is_array($policyHandler)) {
                $policyHandler = implode('@', $policyHandler);
            }
        }

        $this->injectProp('policy', $policyHandler);

        return $this;
    }

    /**
     * Inject property into route infio.
     * 
     * @param  string $key
     * @param  mixed $value
     * @return void
     */
    public function injectProp($key, $value)
    {
        [$controller, $cbKey] = $this->endpointSignature;

        $controllerKey = str_replace('\\', '.', $controller);

        // @phpstan-ignore-next-line
        $endpoints = $this->app->endpoints;

        if (isset($endpoints[$controllerKey][$cbKey])) {
            $endpoints[$controllerKey][$cbKey][$key] = $value;
            // @phpstan-ignore-next-line
            $this->app->endpoints = $endpoints;
        }
    }

    /**
     * Resolve and set policy with namespace for add-ons
     *
     * @param array $backTrace
     * @return void
     */
    protected function setPolicyHandlerWithNamespace($backTrace)
    {
        $last = end($backTrace);

        if (!isset($last['class'])) return;

        $class = $last['class'];

        $namespace = substr(__NAMESPACE__, 0, strpos(__NAMESPACE__, '\\'));

        $calledClassNamespace = substr($class, 0, strpos($class, '\\'));

        if ($namespace != $calledClassNamespace) {
            $ns = $calledClassNamespace . '\\App\\Http\\Policies\\';
            $this->policyHandler = $ns . $this->policyHandler;
        }
    }

    /**
     * Set the name for the route.
     * 
     * @param string $name
     * @return self
     */
    public function name($name)
    {
        if (!$this->name) {
            $this->name = $name;
        } else {
            $this->name .= $name;
        }

        // @phpstan-ignore-next-line
        return $this->app->router->setNamedRoute($this->name, $this);
    }

    /**
     * Set the name for the route.
     * 
     * @param string $name
     * @return null
     */
    public function withName($name)
    {
        $this->name = implode('', $name);
    }

    /**
     * Set the namespace for controller/action
     * @param string $ns
     * @return null
     */
    public function withNamespace($ns)
    {
        $this->namespace = implode('\\', $ns);
    }

    /**
     *  Sign the route.
     *  
     * @return $this
     */
    public function signed()
    {
        $this->signed = true;

        return $this;
    }

    /**
     *  Apply rate limit to the route.
     *  
     *  @param int $limit Number of allowed requests.
     *  @param int $interval Time interval in seconds.
     *  
     * @return $this
     */
    public function rateLimit($limit, $interval)
    {
        // Since the rate limiter is applied twice because
        // WordPress sends an extra request for every
        // request, so we need to double the limit.

        $rateLimiter = new RateLimiter($limit * 2, $interval);

        $this->middleware('before', $rateLimiter);

        return $this;
    }

    /**
     * Apply a rate limit to the route for per minute.
     *
     * @param int $limit The maximum number of requests allowed per minute.
     * @return $this
     */
    public function rateLimitPerMinute($limit)
    {
        return $this->rateLimit($limit, MINUTE_IN_SECONDS);
    }

    /**
     * Apply an hourly rate limit to the route.
     *
     * @param int $limit The maximum number of requests allowed per hour.
     * @return $this
     */
    public function rateLimitHourly($limit)
    {
        return $this->rateLimit($limit, HOUR_IN_SECONDS);
    }

    /**
     * Apply a daily basis (24 hours) rate limit to the route.
     *
     * @param int $limit The maximum number of requests allowed per day.
     * @return $this
     */
    public function rateLimitDaily($limit)
    {
        return $this->rateLimit($limit, DAY_IN_SECONDS);
    }

    /**
     * Register the rest endpoint
     *
     * @return null
     */
    public function register()
    {
        $this->updateRouteOptions();

        return register_rest_route(
            $this->restNamespace,
            $this->getRouteUri(),
            $this->getOptions(),
            $this->override()
        );
    }

    /**
     * Update route options before registering.
     * 
     * @return void
     */
    protected function updateRouteOptions()
    {
        $this->setOptions();
    }

    /**
     * Get normalized uri for the current route.
     * 
     * @return string
     */
    protected function getRouteUri()
    {
        return '/' . trim($this->compileRoute($this->uri), '/');
    }

    /**
     * Allow route override if we are testing.
     * 
     * @return bool
     */
    protected function override()
    {
        return str_starts_with($this->app->env(), 'testing');
    }

    /**
     * Set route options
     *
     * @return null
     */
    protected function setOptions()
    {
        $this->options = array_merge(
            $this->options, $this->getDefaultOptions()
        );
    }

    /**
     * Get default options.
     * 
     * @return array
     */
    protected function getDefaultOptions()
    {
        return [
            [
                'methods' => $this->method,
                'callback' => [$this, 'callback'],
                'permission_callback' => [$this, 'permissionCallback'],
                'args' => [],
            ],
        ];
    }

    /**
     * Generate and return the schema for the route.
     * 
     * @return self
     * @see https://developer.wordpress.org/rest-api/extending-the-rest-api/schema
     */
    public function schema($schema)
    {
        $this->options['schema'] = fn() => $schema;

        return $this;
    }

    /**
     * Get item from predefined regex
     * @param string $value
     * @return string
     */
    protected function getValue($value)
    {
        if (array_key_exists($value, $this->predefinedNamedRegx)) {
            return $this->predefinedNamedRegx[$value];
        }

        return $value;
    }

    /**
     * Compikle the rest route to regex
     *
     * @param string $uri
     * @return string compiled rest endpoint
     */
    protected function compileRoute($uri)
    {
        $params = [];

        $compiledUri = preg_replace_callback('#/{(.*?)}#', function ($match) use (&$params, $uri) {
            // Default regx
            $regx = '[^\s(?!/)]+';

            $param = trim($match[1]);

            if ($isOptional = strpos($param, '?')) {
                $param = trim($param, '?');
            }

            if (in_array($param, $params)) {
                throw new InvalidArgumentException(
                    "Duplicate parameter name '{$param}' found in {$uri}.", 500
                );
            }

            $params[] = $param;

            if (isset($this->wheres[$param])) {
                $regx = $this->wheres[$param];
            }

            $pattern = "/(?P<" . $param . ">" . $regx . ")";

            if ($isOptional) {
                $pattern = "(?:" . $pattern . ")?";
            }

            $this->options['args'][$param]['required'] = !$isOptional;

            return $pattern;

        }, $uri);

        return $this->compiled = $compiledUri;
    }

    /**
     * Route handler
     *
     * @return mixed
     */
    public function callback()
    {
        try {
            return $this->handleAfterMiddleware(
                $this->dispatchRouteAction()
            );
        } catch (ValidationException $e) {
            return $this->app->response->sendError(
                $e->errors(), $e->getCode()
            );
        } catch (ModelNotFoundException $e) {
            return $this->app->response->sendError([
                'message' => $e->getMessage()
            ], 404);
        } catch (Throwable $e) {
            $this->fireExceptionEvent($e);
            return $this->app->response->sendError([
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Dispatch the route action.
     *
     * @return mixed
     */
    protected function dispatchRouteAction()
    {
        $response = $this->app->call(
            $this->action, $this->getControllerParameters()
        );

        if ($response instanceof WPFluentResponse) {
            $response = $response->toArray();
        } elseif (!($response instanceof WP_REST_Response)) {
            $response = !is_wp_error($response) ?
                $this->app->response->sendSuccess($response) :
                $this->app->response->wpErrorToResponse($response);
        }

        return $response;
    }

    /**
     * Handle after middleware if any.
     *
     * @param mixed $response
     * @return mixed
     */
    protected function handleAfterMiddleware($response)
    {
        if (!$this->skipMiddleware) {
            $response = $this->app->make(Pipeline::class)
                ->send(new WPFluentResponse($response))
                ->through($this->collectMiddleWare('after'))
                ->then(function($response) {
                    return $this->normalize($response);
                });

            if (!$response) {
                $response = $this->app->request->abort();
            }
        }

        return $response;
    }

    /**
     * Normalize the response.
     * 
     * @param  mixed $response
     * @return mixed
     */
    protected function normalize($response)
    {
        if ($response instanceof WPFluentResponse) {
            $response = $response->toArray();
        }

        if (!$response instanceof WP_REST_Response) {
            return new WP_REST_Response($response);
        }

        return $response;
    }

    /**
     * Fire exception action hook.
     * 
     * @param  Exception $exception
     * @return void
     */
    protected function fireExceptionEvent($exception)
    {
        if ($this->app->isDebugOn()) {
            $this->app->doCustomAction('exception', $exception);
        }
    }

    /**
     * Permission callback for route
     * @param \WP_REST_Request $wpRestRequest
     * @return mixed
     */
    public function permissionCallback($wpRestRequest)
    {
        try {
            $this->app->instance('route', $this);
            $this->app->instance('wprestrequest', $wpRestRequest);
            $this->app->request->mergeInputsFromRestRequest($wpRestRequest);
            $this->prepareCallbacks($this->app->request);

            if (!$this->isThisValidSignedRoute()) {
                throw new Exception('Invalid Signature', 403);
            }

            $response = $this->app->make(Pipeline::class)
                ->send($this->app->request)
                ->through($this->collectMiddleWare('before'))
                ->then(function ($request) {
                    if ($request && $request instanceof Request) {
                        return $this->dispatchPermissionHandler();
                    }
                });

            if (is_wp_error($response)) {
                throw new Exception(
                    $response->get_error_message(),
                    is_int($code = $response->get_error_code()) ? $code : 403
                );
            }

            if ($response instanceof WP_REST_Response) {
                $data = $response->get_data();

                throw new Exception(
                    $data['message'] ?? $response->get_status(),
                    $response->get_status()
                );
            }

            return $response;

        } catch (Exception $e) {
            return new WP_Error(
                'Permission Callback Error',
                $e->getMessage(), [
                    'status' => $e->getCode() ?: 403
                ]
            );
        }
    }

    /**
     * Checks if the route is signed and needs validation.
     * 
     * @return boolean [description]
     */
    protected function isThisValidSignedRoute()
    {
        if (!$this->signed) return true;

        $request = $this->app->make('request');

        if ($this->app->make('url')->validate($request->getFullUrl())) {
            parse_str($this->app->make('encrypter')->decrypt(
                $this->app->request->get('_data')
            ), $query);

            $this->app->request->merge(
                Arr::except($query, ['expires_at'])
            );

            $this->app->request->forget('_data');

            return true;
        }
    }

    /**
     * Dispatches the permission handler.
     *
     * @return bool|null
     */
    protected function dispatchPermissionHandler()
    {
        if (!$this->permissionHandler) {
            return true;
        }

        $isValid = $this->app->call(
            $this->permissionHandler,
            $this->getControllerParameters()
        );

        if (is_object($isValid)) {
            if ($this->isUser($isValid)) {
                $isValid = $isValid->id();
            } else {
                $this->throwInvalidPolicy();
            }
        }

        if (!is_bool($isValid) && !is_int($isValid) && !is_null($isValid)) {
            $this->throwInvalidPolicy();
        }

        return (bool) $isValid;
    }

    /**
     * Checks if the user is an instance of WPUserProxy.
     * 
     * @param  WPUserProxy  $user
     * @return bool
     */
    protected function isUser($user)
    {
        return $user instanceof WPUserProxy;
    }

    /**
     * Throw invalid policy handling exception.
     * 
     * @return InvalidArgumentException
     */
    protected function throwInvalidPolicy()
    {
        throw new InvalidArgumentException(
            'The policy must return a boolean, integer, null, or a WPUserProxy instance.', 500
        );
    }

    /**
     * Gether route params after substituted the params
     *
     * @return array
     */
    protected function getControllerParameters()
    {
        $routeParameters = [];

        if (!$this->substitutedParameters) {
            if ($routeParameters = $this->getParameter()) {
                $routeParameters = $this->substituteParameters($routeParameters);
            }
        } else {
            $routeParameters = $this->substitutedParameters;
        }

        return $routeParameters;
    }

    /**
     * Added the ability to add middleware so we can intercept
     * the request without modifying the source code again
     * and again. The middleware class will implement
     * the handle method as given below:
     *
     * public function handle($request, $next)
     *
     * And must return $next($request) to handle the request.
     * Otherwise return nothing to abort the request.
     * Optionally, you may call the abort method:
     * return $request->abort(code, message);
     *
     * @param string $type
     * @return array
     */
    protected function collectMiddleWare($type = 'before')
    {
        $middleware = $this->app['config']->get('middleware', []);

        $callableMiddleware = Arr::get($middleware, "global.{$type}", []);

        $routeArray = [];

        if (isset($middleware['route'])) {
            $routeArray = $middleware['route'];
            if (isset($routeArray[$type])) {
                $routeArray = $routeArray[$type];
            }
        }

        foreach ($this->middleware[$type] as $routeMiddleware) {

            if (is_object($routeMiddleware)) {
                $handler = $routeMiddleware;
            } elseif (class_exists($routeMiddleware)) {
                $handler = $this->resolveMiddlewareFrom($routeMiddleware);
            } else {
                $pieces = explode(':', $routeMiddleware);
                $handler = Arr::get($routeArray, $key = reset($pieces));
                if (isset($pieces[1])) {
                    $handler = $this->resolveMiddleware($handler, $pieces);
                }
            }

            if (isset($handler)) {
                $this->addMiddlewareInTheStack($callableMiddleware, $handler);
            } else {
                if (isset($key)) {
                    $mpath = 'config.middleware.route.' . $type;
                    $msg = "No middleware is assigned for the key: {$key} in {$mpath} array.";
                } else {
                    $msg = "Could't resolve middleware.";
                }

                throw new InvalidArgumentException($msg);
            }
        }

        return $callableMiddleware;
    }

    /**
     * Resolve a middleware from a class.
     * 
     * @param  string $class
     * @return \Closure
     */
    protected function resolveMiddlewareFrom($class)
    {
        return static function ($r, $next, ...$params) use ($class) {
            return (new $class)->handle($r, $next, ...$params);
        };
    }

    /**
     * Resolve the middleware
     *
     * @param mixed $handler
     * @param array $pieces
     * @return object
     */
    protected function resolveMiddleware($handler, $pieces)
    {
        if (is_object($handler)) {
            $handler = $this->wrapMiddleware($handler, $pieces);
        } elseif (is_string($handler)) {
            $handler = $handler . ':' . str_replace(' ', '', end($pieces));
        }

        return $handler;
    }

    /**
     * Create a class to wrap the middleware
     *
     * @param mixed $handler
     * @param array $pieces
     * @return object
     */
    protected function wrapMiddleware($handler, $pieces)
    {
        $params = str_replace(' ', '', end($pieces));

        $params = explode(',', $params);

        return new class ($handler, $params) {
            protected $handler, $params = null;

            public function __construct($handler, $params) {
                $this->handler = $handler;
                $this->params = $params;
            }

            public function handle($r, $next) {
                if (is_callable($this->handler)) {
                    return ($this->handler)($r, $next, ...$this->params);
                } else {
                    if (!method_exists($this->handler, 'handle')) {
                        $class = get_class($this->handler);
                        throw new InvalidArgumentException(
                            "The {$class} must implement the handle method."
                        );
                    }
                    return $this->handler->handle($r, $next, ...$this->params);
                }
            }
        };
    }

    /**
     * Add the middleware in the stack
     *
     * @param array &$stack All callable middleware for the route
     * @param string $middleware
     * @return void
     */
    protected function addMiddlewareInTheStack(&$stack, $middleware)
    {
        if (!in_array($middleware, $stack)) {
            $stack[] = $middleware;
        }
    }

    /**
     * Resolve the policy handler
     *
     * @param string $policyHandler
     * @return mixed
     */
    protected function getPolicyHandler($policyHandler)
    {
        if (!$policyHandler) {
            return [$this, 'defaultPolicyHandler'];
        }

        if (is_callable($policyHandler)) {
            return $policyHandler;
        }

        if (is_string($policyHandler)) {

            if (function_exists($policyHandler)) {
                return $policyHandler;
            }

            $policyHandlerFunction = substr(
                $policyHandler, strrpos($policyHandler, '\\') + 1
            );

            if (function_exists($policyHandlerFunction)) {
                return $policyHandlerFunction;
            }
        }

        if ($this->isPolicyHandlerParseable($policyHandler)) {
            return $policyHandler;
        }

        if (is_string($policyHandler) && $this->handler instanceof Closure) {

            if (class_exists($policyHandler)) {

                $reflection = new ReflectionClass($policyHandler);

                if ($reflection->hasMethod('verifyRequest')) {
                    return $policyHandler . '@' . 'verifyRequest';
                }
            } elseif (function_exists($policyHandler)) {
                return $policyHandler;
            }

            throw new InvalidArgumentException(
                'Explicit policy handler is required while using a closure as route callback.'
            );
        }

        if ($policyHandler && !function_exists($policyHandler)) {
            [$_, $method] = is_array($this->handler)
                ? [$this->handler[0], $this->handler[1] ?? '__invoke']
                : Str::parseCallback($this->handler, '__invoke');

            $policyHandler .= '@' . $method;
        }

        return $policyHandler ?: [$this, 'defaultPolicyHandler'];
    }

    /**
     * Check if the policy handler is parseable.
     * 
     * @param  string  $policyHandler
     * @return boolean
     */
    protected function isPolicyHandlerParseable($policyHandler)
    {
        return (strpos($policyHandler, '@') === true
            || strpos($policyHandler, '::') === true);
    }

    /**
     * Default/Fallback policy handler for the route
     *
     * @return bool
     */
    public function defaultPolicyHandler()
    {
        return true;
    }

    /**
     * Parse the rest and permission/policy handlers
     *
     * @param \WP_REST_Request $request
     * @return null
     * @throws \BadMethodCallException
     */
    public function prepareCallbacks($request)
    {
        $handler = $this->app->parseRestHandler($this->handler, $this->namespace);

        [$action, $controller] = $this->resolveHandlerDetails($handler);

        $policyHandler = $this->resolvePolicyHandler();

        $this->actionInfo = [
            'handler'             => is_object($handler) ? $action : $handler,
            'controller'          => $controller,
            'method'              => $this->getMethodName($action, $handler),
            'path'                => $this->uri,
            'http_method'         => $request->get_method(),
            'full_uri'            => $request->get_route(),
            'permission_callback' => $policyHandler,
            'compiled_url'        => $this->compiled
        ];

        $this->action = $handler;

        if ($routeParameters = $this->getParameter()) {
            $this->substitutedParameters = $this->substituteParameters($routeParameters);
        }

        return $this->action;
    }

    /**
     * Get the method name to build action info.
     * 
     * @param  mixed $action
     * @param  mixed $handler
     * @return string|null
     */
    protected function getMethodName($action, $handler)
    {
        $method = is_array($action) ? $action[1] ?? '__invoke' : null;

        if (is_null($method) && is_object($handler)) {
            $method = '__invoke';
        }

        return $method;
    }

    /**
     * Resolve the handler details.
     * 
     * @param  mixed $handler
     * @return array
     */
    protected function resolveHandlerDetails($handler)
    {
        if ($handler instanceof Closure) {
            return ['Closure', null];
        }

        if (is_object($handler)) {
            $class = get_class($handler);
            return [$class, $class];
        }

        $handler = trim($handler, '\\');
        [$controller, $method] = Str::parseCallback($handler, '__invoke');
        $controllerName = $this->extractControllerName($controller);

        return [[$controller, $method], $controllerName];
    }

    /**
     * Extract the controller name from the FQCN.
     * 
     * @param  string $fqcn
     * @return string      
     */
    protected function extractControllerName($fqcn)
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }

    /**
     * Parse and validate the policy handler.
     * 
     * @return array
     */
    protected function resolvePolicyHandler()
    {
        try {
            $policyHandler = $this->app->parsePolicyHandler(
                $this->getPolicyHandler($this->policyHandler)
            );

            if ($policyHandler) {
                $this->permissionHandler = $policyHandler;

                // Adjust method if explicitly given in string policy handler
                if (is_string($this->policyHandler) && is_array($policyHandler) && isset($policyHandler[1])) {
                    $pieces = explode('@', $this->policyHandler);
                    if (isset($pieces[1])) {
                        $this->permissionHandler[1] = $pieces[1];
                    }
                }

                if (!is_callable($this->permissionHandler)) {
                    throw new Exception;
                }
            }

        } catch (Exception $e) {
            throw $this->invalidPolicyHandlerException();
        }

        // Convert object controller to class string for endpoint metadata
        if (is_array($policyHandler) && is_object($policyHandler[0])) {
            $policyHandler[0] = get_class($policyHandler[0]);
        }

        return $policyHandler;
    }

    /**
     * Build and throw an exception for invalid policy handlers.
     * 
     * @throws \BadMethodCallException
     */
    protected function invalidPolicyHandlerException()
    {
        $pHandler = $this->policyHandler;

        if (is_array($this->permissionHandler) && $this->permissionHandler) {
            $pHandler = is_object($this->permissionHandler[0])
                ? get_class($this->permissionHandler[0]) . ':' . $this->permissionHandler[1]
                : $this->permissionHandler[0] . ':' . $this->permissionHandler[1];
        }

        return new BadMethodCallException(
            "The permission callback {$pHandler} is invalid or not callable."
        );
    }

    /**
     * Get one or more route parameters
     * @param string $key
     *
     * @return mixed
     */
    public function getParameter($key = null)
    {
        if (is_null($this->parameters)) {
            $this->parameters = $this->app->request->get_url_params();
        }

        return $key ? $this->parameters[$key] : $this->parameters;
    }

    /**
     * Get the name of the route.
     * 
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the url of the route.
     * 
     * @return string
     */
    public function getUrl()
    {
        return $this->uri;
    }

    /**
     * Get the url of the route.
     * 
     * @return string
     */
    public function uri()
    {
        return $this->getUrl();
    }

    /**
     * Dynamically access a route parameter.
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getParameter($key);
    }
}
