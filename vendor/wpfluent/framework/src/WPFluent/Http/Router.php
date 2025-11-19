<?php

namespace FluentCart\Framework\Http;

use Closure;

class Router
{
    /**
     * Application Instance
     * @var \FluentCart\Framework\Foundation\Application
     */
    protected $app = null;

    /**
     * Name for the route.
     * @var array
     */
    protected $name = [];

    /**
     * Mapping of named routes.
     * @var array
     */
    protected $namedRoutes = [];
    
    /**
     * Prefix for the route
     * @var array
     */
    protected $prefix = [];
    
    /**
     * Controller/Handler namespace
     * @var array
     */
    protected $namespace = [];

    /**
     * Registered routes collection
     * @var array
     */
    protected $routes = [];
    
    /**
     * Route policy handler to pass to the route
     * @var array
     */
    protected $policyHandler = [];

    /**
     * Route middleware to pass to the route
     * @var array
     */
    protected $middleware = [
        'before' => [],
        'after' => []
    ];

    /**
     * Keep the track of number of group calls
     * @var integer
     */
    protected $groupCount = 0;

    /**
     * Construct the routet instance
     * @param \FluentCart\Framework\Foundation\Application $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Create a route group
     * @param  array $attributes
     * @param  Closure|null $callback
     * @return null
     */
    public function group($attributes = [], ?Closure $callback = null)
    {
        $this->groupCount += 1;

        if ($attributes instanceof Closure) {
            $callback = $attributes;
            $attributes = [];
        }

        if (isset($attributes['name'])) {
            $this->name($attributes['name']);
        }

        if (isset($attributes['prefix'])) {
            $this->prefix($attributes['prefix']);
        }

        if (isset($attributes['namespace'])) {
            $this->namespace($attributes['namespace']);
        }

        if (isset($attributes['policy'])) {
            $this->withPolicy($attributes['policy']);
        }

        if (isset($attributes['middleware'])) {
            $middleware = $attributes['middleware'];
            if (isset($middleware['before'])) {
                $this->middleware('before', $middleware['before']);
            } elseif ($middleware['after']) {
                $this->middleware('after', $middleware['after']);
            }
        }

        // If the current group doesn't have a policy handler
        // but the parent group has then bring it in this group.
        if (!isset($this->policyHandler[$this->groupCount])) {
            if (isset($this->policyHandler[$this->groupCount - 1])) {
                if ($policyHandler = $this->policyHandler[$this->groupCount - 1]) {
                    $this->policyHandler[] = $policyHandler;
                }
            }
        }

        // If the current group doesn't have a before middleware
        // but the parent group has then bring it in this group.
        if (!isset($this->middleware['before'][$this->groupCount])) {
            if (isset($this->middleware['before'][$this->groupCount - 1])) {
                if ($beforeMiddleware = $this->middleware['before'][$this->groupCount - 1]) {
                    $this->middleware['before'][] = $beforeMiddleware;
                }
            }
        }

        // If the current group doesn't have an after middleware
        // but the parent group has then bring it in this group.
        if (!isset($this->middleware['after'][$this->groupCount])) {
            if (isset($this->middleware['after'][$this->groupCount - 1])) {
                if ($afterMiddleware = $this->middleware['after'][$this->groupCount - 1]) {
                    $this->middleware['after'][] = $afterMiddleware;
                }
            }
        }

        return new Group($this, $callback);
    }

    /**
     * Set the route name
     * 
     * @param  string $name
     * @return self
     */
    public function name($name)
    {
        $this->name[] = $name;

        return $this;
    }

    /**
     * Set the route prefix
     * 
     * @param  string $prefix
     * @return self
     */
    public function prefix($prefix)
    {
        $this->prefix[] = $prefix;

        return $this;
    }

    /**
     * Set the namespace for the action/controller
     * 
     * @param  string $ns
     * @return self
     */
    public function namespace($ns)
    {
        $this->namespace[] = $ns;

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
     * @param  mixed $handler
     * @param  string|null $method
     * @return self
     */
    public function withPolicy($handler, $method = null)
    {
        if (is_array($handler = $method ? func_get_args() : $handler)) {
            $handler = implode('@', $handler);
        }

        $this->policyHandler[] = $handler;

        return $this;
    }

    /**
     * Set the route before middleware
     * 
     * @param  array|string $middleware
     * @return self
     */
    public function before(...$middleware)
    {
        return $this->middleware('before', ...$middleware);
    }

    /**
     * Set the route after middleware
     * 
     * @param  array|string $middleware
     * @return self
     */
    public function after(...$middleware)
    {
        return $this->middleware('after', ...$middleware);
    }

    /**
     * Set the route middleware
     * 
     * @param  array|string $middleware
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
     * Execute the route group callback
     * 
     * @param  Closure $callback
     * @return null
     */
    public function executeGroupCallback($callback)
    {
        $callback($this);
        $this->groupCount -= 1;
        array_pop($this->name);
        array_pop($this->prefix);
        array_pop($this->namespace);
        array_pop($this->middleware['before']);
        array_pop($this->middleware['after']);
        array_pop($this->policyHandler);
    }

    /**
     * Declare a GET route endpoint
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentCart\Framework\Http\Route
     */
    public function get($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, 'GET'
        );

        return $route;
    }

    /**
     * Declare a POST route endpoint
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentCart\Framework\Http\Route
     */
    public function post($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, 'POST'
        );

        return $route;
    }

    /**
     * Declare a PUT route endpoint
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentCart\Framework\Http\Route
     */
    public function put($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, 'PUT'
        );

        return $route;
    }

    /**
     * Declare a PATCH route endpoint
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentCart\Framework\Http\Route
     */
    public function patch($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, 'PATCH'
        );

        return $route;
    }

    /**
     * Declare a DELETE route endpoint
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentCart\Framework\Http\Route
     */
    public function delete($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, 'DELETE'
        );

        return $route;
    }

    /**
     * Declare a route endpoint that matches any HTTP Verb/Method
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentCart\Framework\Http\Route
     */
    public function any($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, \WP_REST_Server::ALLMETHODS
        );

        return $route;
    }

    /**
     * Create a new route instance
     * @param  string $uri
     * @param  string|Closure $handler
     * @param  string $method HTTP Method
     * @return \FluentCart\Framework\Http\Route
     */
    protected function newRoute($uri, $handler, $method)
    {
        $route = Route::create(
            $this->app,
            $this->getRestNamespace(),
            $this->buildUriWithPrefix($uri),
            $handler,
            $method
        );

        if ($this->name) {
            $route->withName($this->name);
        }

        if ($this->namespace) {
            $route->withNamespace($this->namespace);
        }

        if ($this->policyHandler) {
            $route->withPolicy(end($this->policyHandler));
        }

        if ($this->middleware['before']) {
            $route->before($this->middleware['before']);
        }

        if ($this->middleware['after']) {
            $route->after($this->middleware['after']);
        }

        return $route;
    }

    /**
     * Resolve the rest namespace for the plugin
     * 
     * @return string
     */
    protected function getRestNamespace()
    {
        $version = $this->app->config->get('app.rest_version');

        $namespace = trim(
            $this->app->config->get('app.rest_namespace', ''), '/'
        );

        return "{$namespace}/{$version}";
    }

    /**
     * Build the URI with the prefix
     * 
     * @param  string $uri
     * @return string The URI
     */
    protected function buildUriWithPrefix($uri)
    {
        $uri = trim($uri, '/');

        $prefix = array_map(function($prefix) {
            return trim($prefix, '/');
        }, $this->prefix);

        $prefix = implode('/', $prefix);

        return trim($prefix, '/') . '/' . trim($uri, '/');
    }

    /**
     * Register all the routse in WordPress Rest Engine
     * 
     * @return null
     */
    public function registerRoutes()
    {
        foreach ($this->routes as $route) $route->register();
    }

    /**
     * Get all ther registered routes
     * @return array
     */
    public function getRoutes()
    {
        return $this->routes;
    }

    /**
     * Set a named route in the router.
     * 
     * @param string $name
     * @param Route  $route
     */
    public function setNamedRoute($name, Route $route)
    {
        $this->namedRoutes[$name] = $route;

        return $route;
    }

    /**
     * Get a route by name.
     * 
     * @param  string $name
     * @return Route|null
     */
    public function getByName($name)
    {
        return $this->namedRoutes[$name] ?? null;
    }
}
