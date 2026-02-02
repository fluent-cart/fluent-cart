<?php

namespace FluentCartPro\App\Core;

use ArrayAccess;
use InvalidArgumentException;
use FluentCart\Framework\Support\Facade;
use FluentCart\Framework\Foundation\Config;

class Application implements ArrayAccess
{
    protected $app = null;
    protected $file = null;
    protected $baseUrl = null;
    protected $basePath = null;
    protected $bindings = [];
    protected $passthru = [
        'addAction',
        'addFilter',
        'addShortcode'
    ];

    protected static $composer = null;

    public function __construct($app, $file)
    {
        $this->init($app, $file);
        $this->setAppLevelNamespace();
        $this->bootstrapApplication();
        $this->registerFacadeResolver($this);
    }

    protected function init($app, $file)
    {
        $this->app = $app;
        $this->file = $file;
        $this->basePath = plugin_dir_path($file);
        $this->baseUrl = plugin_dir_url($file);
    }

    protected function setAppLevelNamespace()
    {
        $composer = $this->getComposer();

        $this->bindings['__namespace__'] = $composer['extra']['wpfluent']['namespace']['current'];
    }

    public function getComposer($section = null)
    {
        if (is_null(static::$composer)) {
            static::$composer = json_decode(
                file_get_contents($this->basePath . 'composer.json'), true
            );
        }

        return $section ? static::$composer[$section] : static::$composer;
    }

    protected function bootstrapApplication()
    {
        $this->bindAppInstance();
        $this->bindPathsAndUrls();
        $this->loadConfigIfExists();
        $this->registerTextdomain();
        $this->requireCommonFiles($this);
    }

    protected function bindAppInstance()
    {
        App::setInstance($this);
        $this->instance('app', $this);
        $this->instance(__CLASS__, $this);
    }

    protected function bindPathsAndUrls()
    {
        $this->bindUrls();
        $this->basePaths();
    }

    protected function bindUrls()
    {
        $this->bindings['url.assets'] = $this->baseUrl . 'assets/';
    }

    protected function basePaths()
    {
        $this->bindings['path'] = $this->basePath;
        $this->bindings['path.app'] = $this->basePath . 'app/';
        $this->bindings['path.hooks'] = $this->bindings['path.app'] . 'Hooks/';
        $this->bindings['path.http'] = $this->bindings['path.app'] . 'Http/';
        $this->bindings['path.controllers'] = $this->bindings['path.http'] . 'Controllers/';
        $this->bindings['path.config'] = $this->basePath . 'config/';
        $this->bindings['path.assets'] = $this->basePath . 'assets/';
        $this->bindings['path.resources'] = $this->basePath . 'resources/';
        $this->bindings['path.views'] = $this->bindings['path.app'] . 'Views/';
    }

    protected function loadConfigIfExists()
    {
        $data = [];

        if (is_dir($this['path.config'])) {
            foreach (glob($this['path.config'] . '*.php') as $file) {
                $data[basename($file, '.php')] = require_once($file);
            }
        }

        $data['app']['rest_namespace'] = $this->app->config->get('app.rest_namespace');

        $this->bindings['config'] = new Config($data);
    }

    protected function registerTextdomain()
    {
        $this->app->addAction('init', function() {
            load_plugin_textdomain(
                $this->config->get('app.text_domain'), false, $this->textDomainPath()
            );
        });
    }

    protected function textDomainPath()
    {
        return basename($this->bindings['path']) . $this->config->get('app.domain_path');
    }

    protected function requireCommonFiles($app)
    {
        require_once $this->basePath . 'app/Hooks/actions.php';
        require_once $this->basePath . 'app/Hooks/filters.php';

        if (file_exists($bindings = $this->basePath . 'boot/bindings.php')) {
            require_once $bindings;
        }

        if (file_exists($includes = $this->basePath . 'app/Hooks/includes.php')) {
            require_once $includes;
        }

        $this->registerRestRoutes($app->app);
    }

    protected function registerRestRoutes($app)
    {
        $app->addAction('rest_api_init', function($wpRestServer) use ($app) {
            try {
                $app->router->registerRoutes(
                    $this->requireRouteFile($app->router)
                );
            } catch (InvalidArgumentException $e) {
                return $app->response->json([
                    'message' => $e->getMessage()
                ], $e->getCode() ?: 500);
            }
        });
    }

    protected function requireRouteFile($router)
    {
        $router->namespace(
            $this->bindings['__namespace__'] . '\App\Http\Controllers'
        )->group(function($router) {
            require_once $this['path.http'] . 'Routes/api.php';
        });
    }

    protected function registerFacadeResolver($app)
    {
        Facade::setFacadeApplication($app);
 
        spl_autoload_register(function($class) use ($app) {

            $ns = substr(($fqn = __NAMESPACE__), 0, strpos($fqn, '\\'));

            if (str_contains($class, ($facade = $ns.'\Facade'))) {

                $this->createFacadeFor($facade, $class, $app);
            }
        });
    }

    protected function createFacadeFor($facade, $class, $app)
    {
        $facadeAccessor = $this->resolveFacadeAccessor($facade, $class, $app);

        $anonymousClass = new class($facadeAccessor) extends Facade {

            protected static $facadeAccessor;

            public function __construct($facadeAccessor) {
                static::$facadeAccessor = $facadeAccessor;
            }

            protected static function getFacadeAccessor() {
                return static::$facadeAccessor;
            }
        };

        class_alias(get_class($anonymousClass), $class, true);
    }

    protected function resolveFacadeAccessor($facade, $class,$app)
    {
        $name = strtolower(trim(str_replace($facade, '', $class), '\\'));
        
        if ($name == 'route') $name = 'router';

        if ($app->bound($name)) {
            return $name;
        }
    }

    public function addCustomAction($action, $handler, $priority = 10, $numOfArgs = 1)
    {
        $prefix = $this->config->get('app.hook_prefix');

        return $this->addAction(
            $this->hook($prefix, $action), $handler, $priority, $numOfArgs
        );
    }

    public function doCustomAction()
    {
        $args = func_get_args();

        $prefix = $this->config->get('app.hook_prefix');

        $args[0] = $this->hook($prefix, $args[0]);

        return $this->doAction(...$args);
    }

    public function addCustomFilter($action, $handler, $priority = 10, $numOfArgs = 1)
    {
        $prefix = $this->config->get('app.hook_prefix');

        return $this->addFilter(
            $this->hook($prefix, $action), $handler, $priority, $numOfArgs
        );
    }

    public function applyCustomFilters()
    {
        $args = func_get_args();
        
        $prefix = $this->config->get('app.hook_prefix');
        
        $args[0] = $this->hook($prefix, $args[0]);

        return $this->applyFilters(...$args);
    }

    public function env()
    {
        if (defined(WP_DEBUG) && WP_DEBUG) {
            return 'dev';
        }
        
        return $this->config->get('app.env');
    }

    /**
     * Determine if a given offset exists.
     *
     * @param  string  $key
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($key)
    {
        return isset($this->bindings[$key]) ?: $this->app->offsetExists($key);
    }

    /**
     * Get the value at a given offset.
     *
     * @param  string  $key
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($key)
    {
        if ($key === 'view') {
            return $this->view;
        }
        
        if (isset($this->bindings[$key])) {
            return $this->bindings[$key];
        }

        return $this->app->make($key);
    }

    /**
     * Set the value at a given offset.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($key, $value)
    {
        $this->app->offsetSet($key, $value);
    }

    /**
     * Unset the value at a given offset.
     *
     * @param  string  $key
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($key)
    {
        $this->app->offsetUnset($key);
    }

    /**
     * Dynamically access container services.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        if ($key === 'view') {
            $view = $this->app->make($key);
            $view->setViewPath($this->bindings['path.views']);
            return $view;
        }

        if (isset($this->bindings[$key])) {
            return $this->bindings[$key];
        }

        return $this->app[$key];
    }

    /**
     * Dynamically set container services.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->app[$key] = $value;
    }

    public function __call($method, $params)
    {
        if ($method === 'make' && in_array('view', $params)) {
            return $this->view;
        }

        if (in_array($method, $this->passthru)) {
            if (is_string($params[1]) && !$this->app->hasNamespace($params[1])) {
                $ns = substr(__NAMESPACE__, 0, strpos(__NAMESPACE__, '\\'));
                $params[1] = $ns . '\App\Hooks\Handlers\\' . $params[1];
            }
        }

        return call_user_func_array([$this->app, $method], $params);
    }
}
