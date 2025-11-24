<?php
/**
 * This is an example of using middleware in routes. The global middleware group will be
 * applied to all routes and the before middleware will be executed before the policy
 * handler ran and the after middleware group will be executed right after the main
 * callback (the main route handler) ran without any exceptions/interruptions.
 * The route middleware will be ran if any middleware is explicitly used.
 */

/**
 * Note: Please use class based middleware when you need more than a few to keep
 * this confile clean and store the middleware in the Http/Middleware folder.
 */

/**
 * How to use route middleware:
 * 
 * $router->namespace(...)->before('auth')->after('loger')->group(...);
 * $router->before('auth')->after('loger')->get(...);
 */

return [
	'global' => [
		'before' => [
			function ($request, $next) {
				return $next($request->forget('query_timestamp'));
			}
		],
		'after' => [
			function ($response, $next) {
				// $response->header(...);
				return $next($response);
			}
		]
	],
	'route' => [
		'before' => [
			'auth' => function($request, $next) {
				if (is_user_logged_in()) {
					return $next($request);
				}
			},
			'can' => function($request, $next, ...$roles) {
				if (array_intersect($roles, ['create', 'update'])) {
					return $next($request);
				}	
			},
		],
		'after' => [
			'logger' => function($response, $next) {
				// do_action('perfix-log', $response);
				return $next($response);
			}
		],
	]
];