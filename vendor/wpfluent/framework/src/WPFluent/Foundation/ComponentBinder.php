<?php

namespace FluentCart\Framework\Foundation;

use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\View\View;
use FluentCart\Framework\Cache\Cache;
use FluentCart\Framework\Http\URL;
use FluentCart\Framework\Support\Mail;
use FluentCart\Framework\Support\Pipeline;
use FluentCart\Framework\Http\Router;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Http\Response\Response;
use FluentCart\Framework\Events\Dispatcher;
use FluentCart\Framework\Encryption\Encrypter;
use FluentCart\Framework\Database\Orm\Model;
use FluentCart\Framework\Validator\Validator;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Database\DatabaseManager;
use FluentCart\Framework\Database\DatabaseTransactionsManager;
use FluentCart\Framework\Database\ConnectionResolver;
use FluentCart\Framework\Database\Query\WPDBConnection;
use FluentCart\Framework\Pagination\AbstractCursorPaginator;
use FluentCart\Framework\Pagination\AbstractPaginator;
use FluentCart\Framework\Pagination\CursorPaginator;
use FluentCart\Framework\Pagination\Cursor;
use WpOrg\Requests\Exception\Http\Status401;

class ComponentBinder
{
    use Concerns\DynamicFacadeTrait;

    /**
     * The application instance
     * @var \FluentCart\Framework\Foundation\Application
     */
    protected $app = null;

    /**
     * List of bindings
     * @var array
     */
    protected $bindables = [
        'Cache',
        'Request',
        'Response',
        'Validator',
        'View',
        'Events',
        'Encrypter',
        'DB',
        'URL',
        'Router',
        'Mail',
        'Paginator',
        'Pipeline',
    ];

    /**
     * Construct the binder
     * @param \FluentCart\Framework\Foundation\Application $app
     */
    public function __construct($app)
    {
        $this->registerFacadeResolver(
            $this->app = $app
        );
    }

    /**
     * Bind all the components in to the container.
     * @return null
     */
    public function bindComponents()
    {
        foreach ($this->bindables as $value) {
            $method = "bind{$value}";
            $this->{$method}();
        }

        $this->extendBindings($this->app);

        $this->loadGlobalFunctions($this->app);

        $this->registerResolvingEvent($this->app);
    }

    public function resolveDatabaseTransactionsManager()
    {
        if (!$this->app->bound('db.transactions')) {
            $this->app->singleton('db.transactions', function ($app) {
                return new DatabaseTransactionsManager;
            });
        }

        return $this->app->make('db.transactions');
    }

    /**
     * Register resolving event into the container.
     * @param  \FluentCart\Framework\Foundation\Application $app
     * @return null
     */
    protected function registerResolvingEvent($app)
    {
        $app->resolving(RequestGuard::class, function($request) use ($app) {
            
            $request->setRequestInstance($app->request);

            if (method_exists($request, 'authorize')) {
                if(!$request->authorize()) throw new Status401;
            }

            $request->merge((array) $request->beforeValidation());
            $request->validate();
            $request->merge((array) $request->afterValidation(
                $request->getValidator()
            ));
        });
    }

    /**
     * Bind the cache instance into the container.
     * @return null
     */
    protected function bindCache()
    {
        $this->app->singleton(Cache::class, function ($app) {
            return Cache::init();
        });

        $this->app->alias(Cache::class, 'cache');
    }

    /**
     * Bind the request instance into the container.
     * @return null
     */
    protected function bindRequest()
    {
        $method = $this->getBindingMethod('singleton');

        $this->app->$method(Request::class, function ($app) {
            return $this->resolveRequest($app);
        });

        $this->app->alias(Request::class, 'request');

        $this->addBackwardCompatibleAlias(Request::class);
    }

    /**
     * Bind the reesponse instance into the container.
     * @return null
     */
    protected function bindResponse()
    {
        $method = $this->getBindingMethod('singleton');

        $this->app->$method(Response::class, function($app) {
            return new Response($app);
        });

        $this->app->alias(Response::class, 'response');
        
        $this->addBackwardCompatibleAlias(Response::class);
    }

    /**
     * Get the binding method to bind a component.
     * In the testing environment, we use bind
     * method instead of singleton method.
     * 
     * @param  string $original
     * @return string
     */
    protected function getBindingMethod($original)
    {
        return str_starts_with(
            $this->app->env(), 'testing'
        ) ? 'bind' : $original;
    }

    /**
     * Bind the request validator into the container.
     * @return null
     */
    protected function bindValidator()
    {
        $this->app->bind(Validator::class, function($app) {
            return new Validator;
        });

        $this->app->alias(Validator::class, 'validator');
    }

    /**
     * Bind the view instance into the container.
     * @return null
     */
    protected function bindView()
    {
        $this->app->bind(View::class, function($app) {
            return new View($app);
        });

         $this->app->alias(View::class, 'view');
    }

    /**
     * Bind the event dispatcher instance into the container.
     * @return null
     */
    protected function bindEvents()
    {
        $this->app->singleton(Dispatcher::class, function($app) {
            return (new Dispatcher($app))->setTransactionManagerResolver(
                fn () => $this->resolveDatabaseTransactionsManager()
            );
        });

        $this->app->alias(Dispatcher::class, 'events');
    }

    /**
     * Bind the encrypter instance into the container.
     * @return null
     */
    protected function bindEncrypter()
    {
        $this->app->singleton(Encrypter::class, function($app) {
            return new Encrypter($app->config->get('app.key'));
        });

        $this->app->alias(Encrypter::class, 'encrypter');
        $this->app->alias(Encrypter::class, 'crypt');
    }

    /**
     * Bind the db (query builder) instance into the container.
     * @return null
     */
    protected function bindDB()
    {
        $connection = new WPDBConnection($GLOBALS['wpdb']);

        $resolver = new ConnectionResolver([
            'mysql' => $connection,
            'sqlite' => $connection,
        ]);

        $resolver->setDefaultConnection('mysql');

        $resolver->connection()->setTransactionManager(
            $this->resolveDatabaseTransactionsManager()
        );

        Model::setConnectionResolver($resolver);
        
        Model::setEventDispatcher($this->app['events']);

        $this->app->singletonIf('db', function($app) use ($resolver) {
            return new DatabaseManager($resolver);
        });
    }

    /**
     * Bind the Url instance into the container.
     * @return null
     */
    protected function bindUrl()
    {
        $this->app->bind(URL::class, function($app) {
            return new URL($app->make(Encrypter::class));
        });

        $this->app->alias(URL::class, 'url');
    }

    /**
     * Bind the router instance into the container.
     * @return null
     */
    protected function bindRouter()
    {
        $this->app->singleton(Router::class, function($app) {
            return new Router($app);
        });

        $this->app->alias(Router::class, 'router');
    }

    /**
     * Bind the mail instance into the container.
     * @return null
     */
    protected function bindMail()
    {
        $this->app->bind(Mail::class, function($app) {
            return new Mail();
        });

        $this->app->alias(Mail::class, 'mail');
    }

    /**
     * Bind the paginator instance into the container.
     * @return null
     */
    protected function bindPaginator()
    {
        AbstractPaginator::currentPathResolver(function () {
            return $this->app['request']->url();
        });
        
        AbstractPaginator::currentPageResolver(function ($pageName = 'page') {
            $page = $this->app['request']->get($pageName);

            if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
                return $page;
            }

            return 1;
        });

        AbstractPaginator::queryStringResolver(function () {
            return $this->app['request']->query();
        });

        AbstractCursorPaginator::currentCursorResolver(function ($cursorName = 'cursor') {
            return Cursor::fromEncoded($this->app['request']->get($cursorName));
        });
    }

    /**
     * Bind the pipeline instance into the container.
     * @return null
     */
    protected function bindPipeline()
    {
        $this->app->bind(Pipeline::class, function ($app) {
            return new Pipeline($app);
        });

        $this->app->alias(Pipeline::class, 'pipeline');  
    }

    /**
     * Load other bindings the developers might
     * have added in the application level.
     * 
     * @param  \FluentCart\Framework\Foundation\Application $app
     * @return null
     */
    protected function extendBindings($app)
    {
        $bindings = $app['path'] . 'boot/bindings.php';

        if (is_readable($bindings)) {
            require_once $bindings;
        }
    }

    /**
     * Load the plugin's global functions
     * @param  \FluentCart\Framework\Foundation\Application $app
     * @return null
     */
    protected function loadGlobalFunctions($app)
    {
        $globals = $app['path'] . 'boot/globals.php';
        
        if (is_readable($globals)) {
            require_once $globals;
        }
    }

    /**
     * Adds new alias to maintain the backward compatibility.
     *
     * @param string $class
     * @return void
     */
    protected function addBackwardCompatibleAlias($class)
    {
        $this->app->alias(
            $class, $alias = $this->getAlias($class)
        );

        if (!class_exists($alias)) {
            class_alias($class, $alias);
        }
    }

    /**
     * Resolves the backward compatible alias.
     * 
     * @param string $class
     * @return string New alias
     */
    protected function getAlias($class)
    {
        $pieces = explode('\\', $class);
        
        if ($index = Arr::findPath($pieces, 'Http')) {
            unset($pieces[$index]);
        }
        
        return implode('\\', $pieces);
    }

    /**
     * Resolve the appropriate request instance.
     * 
     * @param  $app
     * @return Request|object (Anonymous Class)
     */
    protected function resolveRequest($app)
    {
        return new Request($app, $_GET, $_POST);
    }

    /**
     * Check if the request is of the plugin.
     * 
     * @return boolean
     */
    protected function isRequestOfPlugin()
    {
        if (str_starts_with($this->app->env(), 'testing')) {
            return true;
        }

        $slug = $this->app->config->get('app.slug');

        if (get_option('permalink_structure')) {
            $route = $_SERVER['REQUEST_URI'] ?? '';
        } else {
            $route = $_GET['rest_route'] ?? '';
        }

        $parsedUrl = parse_url($route);

        $path = $parsedUrl['path'] ?? '';

        $path = str_replace('/wp-json', '', $path);

        if (is_admin()) {
            $page = $_GET['page'] ?? '';
            if ($slug === $page) {
                $path = $page;
            }
        }

        return str_starts_with(ltrim($path, '/'), $slug);
    }
}
