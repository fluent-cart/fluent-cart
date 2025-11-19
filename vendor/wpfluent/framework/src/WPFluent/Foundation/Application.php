<?php

namespace FluentCart\Framework\Foundation;

use InvalidArgumentException;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Env;
use FluentCart\Framework\Http\Client;
use FluentCart\Framework\Foundation\Config;
use FluentCart\Framework\Container\Container;
use FluentCart\Framework\Foundation\ComponentBinder;
use FluentCart\Framework\Foundation\Concerns\FoundationTrait;

/**
 * @property \FluentCart\Framework\Foundation\Config $config
 * @property \FluentCart\Framework\Http\Router\Router $router
 * @property \FluentCart\Framework\Http\Request\Request $request
 * @property \FluentCart\Framework\Http\Response\Response $response
 */
class Application extends Container
{
    use FoundationTrait;

    /**
     * Main plugin file's absolute path
     * 
     * @var string
     */
    protected $file = null;

    /**
     * Plugin's base url
     * 
     * @var string
     */
    protected $baseUrl = null;

    /**
     * Plugin's base path
     * 
     * @var string
     */
    protected $basePath = null;

    /**
     * Default namespace for hook's handlers
     * 
     * @var string
     */
    protected $handlerNamespace = null;

    /**
     * Default namespace for controllers
     * 
     * @var string
     */
    protected $controllerNamespace = null;

    /**
     * Default namespace for policy handlers
     * 
     * @var string
     */
    protected $policyNamespace = null;

    /**
     * Composer JSON
     * 
     * @var null|array
     */
    protected static $composer = null;

    /**
     * Ready event handlers
     * 
     * @var array
     */
    protected $onReady = [];

    /**
     * Flag to check if components are bound.
     * 
     * @var boolean
     */
    protected $componentsBound = false;

    /**
     * Construct the application instance
     * 
     * @param string $file The main plugin file's absolute path
     * @return null
     */
    public function __construct($file = null)
    {
        $this->init($file);
        $this->loadEnvironmentVars();
        $this->setAppLevelNamespace();
        $this->bootstrapApplication();
        $this->callPluginReadyCallbacks();
    }

    /**
     * Init the application instance
     * 
     * @param string $file The main plugin file's absolute path
     * 
     * @return null
     */
    protected function init($file)
    {
        $this['__pluginfile__'] = $this->file = $file;
        $this->basePath = plugin_dir_path($this->file);
        $this->baseUrl = plugin_dir_url($this->file);
    }

    /**
     * Load the environment bariables from .env file.
     * 
     * @param  string $path
     * @return void
     */
    protected function loadEnvironmentVars($path = null)
    {
        $path = $path ?: $this->basePath . '.env';

        is_readable($path) && Env::load($path);
    }

    /**
     * Set the default application level namespaces to resolve
     * the controllers, policies and various hook handlers.
     *
     * @return null
     */
    protected function setAppLevelNamespace()
    {
        $composer = $this->getComposer();

        $psr4 = array_flip($composer['autoload']['psr-4']);

        $this->policyNamespace = $psr4['app/'] . 'Http\Policies';

        $this->handlerNamespace = $psr4['app/'] . 'Hooks\Handlers';
        
        $this->controllerNamespace = $psr4['app/'] . 'Http\Controllers';

        $this['__namespace__'] = $composer['extra']['wpfluent']['namespace']['current'];
    }

    /**
     * Get the composer data as an array
     * 
     * @param  string $section Specific key
     * 
     * @return array partial or full composer data array
     */
    public function getComposer($section = null)
    {
        if (is_null(static::$composer)) {
            static::$composer = json_decode(
                file_get_contents($this->basePath . 'composer.json'), true
            );
        }

        return $section ? Arr::get(
            static::$composer, $section
        ) : static::$composer;
    }

    /**
     * Bootstrap the application.
     * 
     * @return null
     */
    protected function bootstrapApplication()
    {
        $this->bindAppInstance();
        $this->bindPathsAndUrls();
        $this->loadConfigIfExists();
        $this->registerTextdomain();
        $this->bindCoreComponents();
        $this->requireCommonFiles($this);
        $this->addRestApiInitAction($this);
    }

    /**
     * Bind application instance in the container.
     * 
     * @return null
     */
    protected function bindAppInstance()
    {
        App::setInstance($this);
        $this->instance('app', $this);
        $this->instance(__CLASS__, $this);
        $this->instance('endpoints', []);
    }

    /**
     * Bind the paths and urls
     * 
     * @return null
     */
    protected function bindPathsAndUrls()
    {
        $this->bindUrls();
        $this->basePaths();
    }

    /**
     * Bind urls
     * 
     * @return null
     */
    protected function bindUrls()
    {
        $this['url.assets'] = $this->baseUrl . 'assets/';
    }

    /**
     * Bind paths
     * 
     * @return null
     */
    protected function basePaths()
    {
        $this['path'] = $this->basePath;
        $this['path.app'] = $this->basePath . 'app/';
        $this['path.hooks'] = $this['path.app'] . 'Hooks/';
        $this['path.http'] = $this['path.app'] . 'Http/';
        $this['path.controllers'] = $this['path.http'] . 'Controllers/';
        $this['path.config'] = $this->basePath . 'config/';
        $this['path.assets'] = $this->basePath . 'assets/';
        $this['path.resources'] = $this->basePath . 'resources/';
        $this['path.views'] = $this['path.app'] . 'Views/';
    }

    /**
     * Load application's config and set
     * the data in the Config instance.
     * 
     * @return null
     */
    protected function loadConfigIfExists()
    {
        $data = [];

        if (is_dir($this['path.config'])) {
            foreach (glob($this['path.config'] . '*.php') as $file) {
                $data[basename($file, '.php')] = require($file);
            }
        }

        $this->instance('config', new Config($data));
    }

    /**
     * Resolve the given type from the container.
     * This method is required for unit testing.
     *
     * @param  string  $abstract
     * @param  array   $parameters
     * @return mixed
     */
    public function make($abstract, $parameters = [])
    {
        if (str_starts_with($abstract, '_NS')) {
            
            $namespace = $this->getComposer(
                'extra.wpfluent.namespace.current'
            );

            $abstract = str_replace('_NS', $namespace, $abstract);
        }
        
        return parent::make($abstract, $parameters);
    }

    /**
     * Register plugin's text domain
     * 
     * @return null
     */
    protected function registerTextdomain()
    {
        $this->addAction('init', function() {
            load_plugin_textdomain(
                $this->config->get(
                    'app.text_domain'
                ), false, $this->textDomainPath()
            );
        });
    }

    /**
     * Resolve the text domain path.
     * 
     * @return null
     */
    protected function textDomainPath()
    {
        return basename($this['path']) . $this->config->get('app.domain_path');
    }

    /**
     * Bind the components of the framework into the container so
     * they'll be available throughout the application life cycle.
     * 
     * @return null
     */
    protected function bindCoreComponents()
    {
        if (!$this->componentsBound) {
            $this->componentsBound = true;
            (new ComponentBinder($this))->bindComponents();
        }
    }

    /**
     * Load (include) the files where hooks are registered.
     * 
     * @param self $app
     * 
     * @return null
     */
    protected function requireCommonFiles($app)
    {
        $this->addFilter(
            'rest_pre_serve_request', [$this, 'preServeRequest'], 10, 4
        );

        require_once $this->basePath . 'app/Hooks/actions.php';
        require_once $this->basePath . 'app/Hooks/filters.php';

        if (file_exists($includes = $this->basePath . 'app/Hooks/includes.php')) {
            require_once $includes;
        }
    }

    /**
     * Handler for rest_pre_serve_request filter.
     * 
     * @param  bool $served  (default: false)
     * @param  \WP_REST_Response $result
     * @param  \WP_REST_Request  $request
     * @param  \WP_REST_Server   $server
     * @return bool (false to intercept, otherwise true)
     */
    public function preServeRequest($served, $result, $request, $server)
    {
        if ($result->get_status() === 404) {
            $route = $request->get_route();
            $slug = $this->config->get('app.slug');
            
            if ($this->isRequestOfPlugin($route, $slug)) {
                if ($this->isRequestForEndpoints($route)) {
                    status_header(200);
                    $result->set_status(200);
                    // @phpstan-ignore-next-line
                    $result->set_data($this->endpoints);
                } else {
                    $result->set_data(
                        $this->customizeNotFoundResponse(
                            $result, $request
                        )
                    );
                }
            }
        }

        return $served;
    }

    /**
     * Determines whether the request is madse by plugin.
     * 
     * @param  string  $route (Rest route|Full URL)
     * @param  string  $slug  (Plugin's slug)
     * @return bool
     */
    public function isRequestOfPlugin($route = '', $slug = '')
    {
        $slug = $slug ?: $this->config->get('app.slug');

        if (!$route) {
            if (get_option('permalink_structure')) {
                $route = $this->request->url();
            } else {
                $route = $this->request->query('rest_route');
            }
        }

        // For web routing (If web-routing is installed)
        if (!$route && !$this->request->isRest()) {
            $route = $this->request->url();
        }

        $parsedUrl = parse_url($route ?? '');
        
        $path = str_replace('/wp-json', '', $parsedUrl['path'] ?? '');

        if (is_admin()) {
            $page = $this->request->query('page');
            if ($slug === $page) {
                $path = $page;
            }
        } 

        return str_starts_with(ltrim($path, '/'), $slug);
    }

    /**
     * Determines if the request is made for endpoints.
     * 
     * @param  string  $route (Rest route|Full URL)

     * @return bool
     */
    protected function isRequestForEndpoints($route)
    {
        return str_ends_with($route, '__endpoints');
    }

    /**
     * Prepare a custom not found response.
     * 
     * @param  \WP_REST_Response $result
     * @param  \WP_REST_Request  $request
     * 
     * @return array
     */
    public function customizeNotFoundResponse($result, $request)
    {
        $response = $result->get_data();
        
        if ($this->env() === 'dev') {
            $response['data']['wpfluent'] = [
                'env' => $this->env(),
                'method' => $request->get_method(),
                'request_url' => $this->request->url(),
                'route_params' => $request->get_url_params(),
                'query_params' => $request->get_query_params(),
                'body_params' => $request->get_body_params(),
            ];
        } else {
            $response['data']['wpfluent'] = [
                'env' => $this->env()
            ];
        }

        return $response;
    }

    /**
     * Check if running unit test.
     * 
     * @return boolean
     */
    public function isUnitTesting()
    {
        return getenv('ENV') === 'testing';
    }

    /**
     * Register the rest api init actions and routes
     * 
     * @param self $app
     */
    protected function addRestApiInitAction($app)
    {
        $this->addAction('rest_api_init', function($wpRestServer) use ($app) {
            try {
                $this->registerRestRoutes($app->router);
            } catch (InvalidArgumentException $e) {
                return $app->response->json([
                    'message' => $e->getMessage()
                ], $e->getCode() ?: 500);
            }
        });
    }

    /**
     * Register rest routes.
     * 
     * @param \FluentCart\Framework\Http\Router $router
     * 
     * @return void
     */
    protected function registerRestRoutes($router)
    {
        // @phpstan-ignore-next-line
        $router->registerRoutes(
            $this->requireRouteFile($router)
        );
    }

    /**
     * Load (include) routes
     * 
     * @param \FluentCart\Framework\Http\Router $router
     * @return null
     */
    protected function requireRouteFile($router)
    {
        require_once $this['path.http'] . 'Routes/routes.php';
    }

    /**
     * Register plugin ready (booted) callbacks.
     * 
     * @param  callable $callback
     * @return void
     */
    public function ready(callable $callback)
    {
        $this->onReady[] = $callback;
    }

    /**
     * Execute plugin booted callbacks.
     * 
     * @return void
     */
    protected function callPluginReadyCallbacks()
    {
        while ($callback = array_pop($this->onReady)) {
            $callback($this);
        }
    }
}
