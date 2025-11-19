<?php

namespace FluentCart\Framework\Http;

use Exception;
use BadMethodCallException;
use InvalidArgumentException;
use FluentCart\Framework\Foundation\App;

/**
 * Register AJAX handlers using HTTP-like method names.
 *
 * $action should start with config.app.hook_prefix,
 * e.g. `wpfluent_my_action`.
 * 
 * The $handler can be a callable or "Class@method" string.
 * These dynamic methods delegate to the register() method.
 * 
 * @method $this get(string $action, callable|string $handler, int|string|array $priority = 10, string $scope = "both")
 * @method $this post(string $action, callable|string $handler, int|string|array $priority = 10, string $scope = "both")
 * @method $this put(string $action, callable|string $handler, int|string|array $priority = 10, string $scope = "both")
 * @method $this patch(string $action, callable|string $handler, int|string|array $priority = 10, string $scope = "both")
 * @method $this delete(string $action, callable|string $handler, int|string|array $priority = 10, string $scope = "both")
 *
 * @method static get(string $action, callable|string $handler, int|string|array $priority = 10, string $scope = "both")
 * @method static post(string $action, callable|string $handler, int|string|array $priority = 10, string $scope = "both")
 * @method static put(string $action, callable|string $handler, int|string|array $priority = 10, string $scope = "both")
 * @method static patch(string $action, callable|string $handler, int|string|array $priority = 10, string $scope = "both")
 * @method static delete(string $action, callable|string $handler, int|string|array $priority = 10, string $scope = "both")
 *
 * Usage Examples:
 *
 * Ajax::post('action', 'MyController@handle'); // priority 10, admin & public
 * Ajax::post('action', 'MyController@handle', 5); // priority 5, admin & public
 * Ajax::post('action', 'MyController@handle', 'admin'); // priority 10, admin
 * Ajax::post('action', 'MyController@handle', 5, 'public'); // priority 5, public
 *
 * Ajax::post('action', 'MyController@handle', [
 *  'priority' => 5,
 *  'scope' => 'public'
 * ]);
 */

class Ajax
{
	/**
	 * @var \FluentCart\Framework\Foundation\Application
	 */
	protected $app = null;
	
	/**
	 * $passthru allowed methods
	 * @var array
	 */
	protected $passthru = [
		'get',
		'post',
		'put',
		'patch',
		'delete',
	];

	/**
	 * Consruct the Instance.
	 * 
	 * @param \FluentCart\Framework\Foundation\Application $app
	 */
	public function __construct($app = null)
	{
		$this->app = $app ?? App::getInstance();
	}

	/**
	 * Alternative constructor.
	 * 
	 * @return $this
	 */
	public static function getInstance()
	{
		return new static;
	}

	/**
	 * Register the ajax hook using appropriate method.
	 * 
	 * @param  string  			$method
	 * @param  string  			$action
	 * @param  callable|string  $handler
	 * @param  integer 			$priority
	 * @param  string  			$scope
	 * @return void
	 */
	public function register(
	    $method,
	    $action,
	    $handler,
	    $priority = 10,
	    $scope = 'both'
	) {
	    if (!in_array($method, $this->passthru)) {
	        throw new BadMethodCallException(
	            "Ajax::{$method}() is not supported."
	        );
	    }

	    // If 3rd argument is an array
	    if (is_array($priority)) {
	        $scope = $priority['scope'] ?? 'both';
	        $priority = $priority['priority'] ?? 10;
	    }
	    // If scope is passed as the 3rd argument
	    elseif (is_string($priority) && !is_numeric($priority)) {
	        [$scope, $priority] = [$priority, $scope];
	    }

	    if (!in_array($scope, ['admin', 'public', 'both'], true)) {
	        throw new InvalidArgumentException(
	            "Invalid scope '{$scope}' provided."
	        );
	    }

	    $callback = function () use ($method, $handler) {
	        try {
	            wp_send_json_success(
	                $this->handle($method, $handler)
	            );
	        } catch (Exception $e) {
	            wp_send_json_error($e->getMessage());
	        }
	    };

	    if ($scope === 'both') {
	    	$registrationMethod = 'addAjaxActions';
	    } elseif ($scope === 'admin') {
	    	$registrationMethod = 'addAdminAjaxAction';
	    } elseif ($scope === 'public') {
	    	$registrationMethod = 'addPublicAjaxAction';
	    }

	    $this->app->$registrationMethod(
        	$action, $callback, $priority
        );
	}

	/**
	 * Handle an AJAX request.
	 * 
	 * @param  string $method
	 * @param  mixed $handler
	 * @return mixed
	 */
	public function handle($method, $handler)
	{
		if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
			throw new BadMethodCallException(
				"Invalid request method."
			);
		}

		check_ajax_referer($this->app->config->get('hook_prefix'));

		return $this->app->call(
			$this->app->parseHookHandler($handler)
		);
	}

	/**
	 * Overload magic method.
	 * 
	 * @param  string $method
	 * @param  array $args
	 * @return void
	 */
	public function __call($method, $args)
	{
		$this->register($method, ...$args);
	}

	/**
	 * Overload static magic method.
	 * 
	 * @param  string $method
	 * @param  array $args
	 * @return void
	 */
	public static function __callStatic($method, $args)
	{
		return (new static)->$method(...$args);
	}
}
