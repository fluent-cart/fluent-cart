<?php

namespace FluentCart\Framework\Foundation;

use Exception;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Helper;
use InvalidArgumentException;

/**
 * @property \FluentCart\Framework\Foundation\Config $config
 */
class Async
{
	/**
	 * The dispatched handlers to stop recursion.
	 * 
	 * @var array
	 */
	private $dispatched = [];

	/**
	 * The application instance
	 * 
	 * @var \FluentCart\Framework\Foundation\Application
	 */
	private static $app = null;

	/**
	 * Self instance
	 * 
	 * @var self
	 */
	private static $instance = null;

	/**
	 * The array of async action handlers
	 * 
	 * @var array
	 */
	private static $handlers = [];

	/**
	 * The array of async action handlers in queue
	 * 
	 * @var array
	 */
	private static $queue = [
		'default' => []
	];

	/**
	 * Creates the instance
	 * 
	 * @return self
	 */
	public static function init($app = null)
	{
		$app = $app ?: App::make();

		if (is_null(self::$instance)) {
			self::$app = $app;
			self::$instance = new static;
		}

		$action = self::$instance->makeAsyncHookAction();

		self::$app->addAction(
			"admin_post_{$action}", [self::$instance, 'handle']
		);

		self::$app->addAction(
			"admin_post_nopriv_{$action}", [self::$instance, 'handle']
		);

		return self::$instance;
	}

	/**
	 * Makes the async hook action name
	 * 
	 * @return string
	 */
	public function makeAsyncHookAction()
	{
		$slug = self::$app->config->get('app.slug');

		return  "wpfluent_async_hook_{$slug}";
	}

	/**
	 * Handles the incoming async request
	 * 
	 * @return void
	 */
	public function handle()
	{
		$post = self::$app->request->post();

		$this->verifyRequest(self::$app, $post);
		
		$handlers = Arr::get($post, 'handlers', []);

		foreach ($handlers as $handler) {
			try {
				[$class, $action] = $this->resolveHandler($handler);

				$this->execute(self::$app, $class, $action['params'] ?? []);

			} catch (Exception $e) {

			}
		}
	}

	/**
	 * Verify the request by checking the nonce.
	 * 
	 * @param  \FluentCart\Framework\Foundation\Application $app
	 * @param  array $data
	 * @return void
	 */
	protected function verifyRequest($app, $data)
	{
		if (!isset($data['wpfluent_async_nonce'])) {
			exit;
		}

		!wp_verify_nonce(
			$data['wpfluent_async_nonce'],
			$app->config->get('app.slug')
		) && exit;
	}

	/**
	 * Resolve the action handler.
	 * 
	 * @param  array $action
	 * @return array
	 */
	protected function resolveHandler($action)
	{
		if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Invalid action.");
        }

        $handler = base64_decode($action['handler']);

        [$class, $method] = explode('@', $handler);

        if (!class_exists($class)) {
            throw new InvalidArgumentException(
            	"Handler {$class} does not exist."
            );
        }

        return [$class.'@'.$method, $action];
	}

	/**
	 * Execute the action handler.
	 * 
	 * @param  \FluentCart\Framework\Foundation\Application $app
	 * @param  string $class
	 * @param  array  $params
	 * @return void
	 */
	protected function execute($app, $class, $params = [])
	{
		set_time_limit(0);
        ignore_user_abort(true);
        [$class, $method] = explode('@', $class);
		$app->make($class)->{$method}($app, $params);
	}

	/**
	 * Add the async handler and register the shutdown handler
	 * All the handlers will be dispatched in a separate request
	 * 
	 * @param  string $handler (Class@handler or with __invoke method) $handler
	 * @return self
	 * @throws \InvalidArgumentException
	 */
	public static function call($handler, array $params = [])
	{
		if (!self::$instance) {
			static::init();
		}

		self::$handlers[] = self::$instance->validate(
			$handler, $params, static::sign(debug_backtrace(false, 1)[0])
		);
		
		return self::$instance->maybeRegisterShutDownHandler();
	}

	/**
	 * Queue an async handler to be executed during shutdown.
	 *
	 * Queued handlers are grouped by queue name and dispatched
	 * together in a single async HTTP request.
	 *
	 * @param string        $handler The handler 'Class@method'|invokable class.
	 * @param array|string  $params  Array of args or the queue name if a string.
	 * @param string        $name    The name of the queue (default is 'default').
	 * @return self
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function queue(
		$handler, $params = [], $name = 'default'
	) {
		if (!self::$instance) {
			static::init();
		}
		
		if (is_string($params)) {
			$name = $params;
			$params = [];
		}

		self::$queue[$name][] = self::$instance->validate(
			$handler, $params, static::sign(debug_backtrace(false, 1)[0])
		);
		
		return self::$instance->maybeRegisterShutDownHandler();
	}

	/**
	 * Sign the handler to mark as dispatched.
	 * 
	 * @param  array $handler
	 * @return string
	 */
	protected static function sign($handler)
	{
		return md5($handler['file'] . $handler['line']);
	}

	/**
	 * Validate the handler and add a sign to mark as dispatched.
	 * 
	 * @param  string $handler (Class@handler or with __invoke method) $handler
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	public function validate($handler, $params, $sign)
	{
		$method = '__invoke';

		if (is_array($handler)) {
			if (is_object($handler[0])) {
				$handler[0] = get_class($handler[0]);
			}
			$handler = $handler[0] . '@' . $handler[1];
		}

		if (str_contains($handler, '@')) {
			[$handler, $method] = explode('@', $handler);
		}

		if (!class_exists($handler)) {
			throw new InvalidArgumentException(
				"Class {$handler} not found."
			);
		}

		if (!method_exists($handler, $method)) {
			throw new InvalidArgumentException(
				"Class {$handler} must implement __invoke or specify method."
			);	
		}

		$handler = $handler.'@'.$method;

		return [
			'sign' => $sign,
			'params'  => $params,
			'handler' => base64_encode($handler),
		];
	}

	/**
	 * Register the shutdown handler
	 * 
	 * @return self
	 */
	protected function maybeRegisterShutDownHandler()
	{
		$handler = [self::$instance, 'dispatch'];

		if (!self::$app->hasAction('shutdown', $handler)) {
			self::$app->addAction('shutdown', $handler);
		}

		return self::$instance;
	}

	/**
	 * Dispatches the async request
	 * 
	 * @return void
	 */
	public function dispatch()
	{
		$stacks = array_filter([
			array_filter(self::$queue),
			array_filter(self::$handlers),
		]);

		// At first we need to mark all the handlers from all
		// the stacks as dispatched before sending any request.
		foreach ($stacks as $key => $stack) {
			$stacks[$key] = $this->getDispatchables($stack);
		}

		// Now we can dispatch them all
		foreach ($stacks as $stack) {
			foreach ($stack as $handler) {
				$this->sendAsyncRequest($this->wrap($handler));
			}
		}
	}

	/**
	 * Filter the handlers to be dispatched.
	 * 
	 * @param  array $stack
	 * @return array|null
	 */
	protected function getDispatchables($stack)
	{
		// If the stack is an array of associtive arrays we
		// need to get the first one because queued handlers
		// will containn one associtive array in the stack.
		$stack = !isset($stack[0]) ? reset($stack) : $stack;

		return array_filter($stack, function ($handler) {
			if (isset($handler['sign'])) {
				$isDispatched = in_array(
					$handler['sign'],
					self::$app->request->post('dispatched', [])
				);

				if (!$isDispatched) {
					$this->dispatched[] = $handler['sign'];
					return !$isDispatched;
				}
			}
		});
	}

	/**
	 * Wrap with an array if necessary. Only used for separate
	 * handlers because queued handlers will be an array of
	 * associative arrays and we treat all the stacks same.
	 * 
	 * @param  array $handlers
	 * @return array of array(s)
	 */
	protected function wrap($handlers)
	{
		return isset($handlers[0]) ? $handlers : [$handlers];
	}

	/**
	 * Send the real async request
	 * 
	 * @param  array $handler
	 * @return mixed
	 */
	public function sendAsyncRequest(array $handler)
	{
		Helper::retry(3, function () use ($handler) {
			return $this->sendRequest(
				$this->url(),
				$this->data($handler),
				['cookie' => $this->getCookie()]
			);
		}, 2000, function ($e) {
	        return str_contains($e->getMessage(), 'cURL');
	    });
	}

	/**
	 * Prepare the request body/POST data.
	 * 
	 * @param  string|array $handler
	 * @return array
	 */
	protected function data($handler)
	{
		$post = self::$app->request->post();

		$data = [
	        'handlers' => $handler,
	        'wpfluent_async_nonce' => wp_create_nonce(
	        	self::$app->config->get('app.slug')
	        ),
	        'dispatched' => array_unique(array_merge(
	        	Arr::get($post, 'dispatched', []),
	        	$this->dispatched,
	        )),
	    ];

		return array_merge($data, Arr::except($post, [
		    'handlers', 'wpfluent_async_nonce', 'dispatched'
		]));
	}

	/**
	 * Build the request url.
	 * 
	 * @return string
	 */
	protected function url()
	{
		return admin_url('admin-post.php') . '?' . http_build_query(
			array_merge(
				self::$app->request->query(),
				['action' => $this->makeAsyncHookAction()]
			)
		);
	}

	/**
	 * Send the non-blocking request.
	 * 
	 * @param  string $url
	 * @param  array $body
	 * @param  array  $headers
	 * @return mixed
	 */
	protected function sendRequest($url, $body = [], $headers = [])
	{
		return wp_remote_post($url, [
	        'timeout'   => 0.01,
	        'blocking'  => false,
	        'sslverify' => false,
	        'body'      => $body,
	        'headers'   => $headers,
	    ]);
	}

	/**
	 * Get the cookie to send with the request
	 * @return string Cookie string
	 */
	protected function getCookie()
	{
		$cookies = [];

		foreach ($_COOKIE as $name => $value) {
			$cookies[] = "$name=" . urlencode(
				is_array($value) ? serialize($value) : $value
			);
		}

		return implode('; ', $cookies);
	}
}
