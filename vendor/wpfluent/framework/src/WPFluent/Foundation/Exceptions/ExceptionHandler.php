<?php

namespace FluentCart\Framework\Foundation\Exceptions;

use Throwable;
use WP_REST_Response;
use InvalidArgumentException;

/**
 * Pluggable exception renderer registry.
 *
 * Plugins extend this class, override `register()`, and map third-party /
 * domain exceptions to `HttpException` instances (or directly to a
 * `WP_REST_Response`). `Route::callback()` consults the bound handler
 * AFTER its own `HttpException` catch and BEFORE the production sanitizer.
 * Unmapped exceptions still fall through to `handleUnknownException` and
 * get their messages scrubbed — the registry is opt-in for "I've
 * authored a user-safe response for this exception class".
 *
 * Plugin example:
 *
 *     namespace Acme\App\Hooks\Handlers;
 *
 *     use FluentCart\Framework\Foundation\Exceptions\ExceptionHandler as BaseHandler;
 *     use FluentCart\Framework\Foundation\Exceptions\ServiceUnavailableHttpException;
 *     use FluentCart\Framework\Foundation\Exceptions\TooManyRequestsHttpException;
 *
 *     class ExceptionHandler extends BaseHandler
 *     {
 *         public function register()
 *         {
 *             $this->renderable(\PDOException::class, function ($e, $app) {
 *                 return new ServiceUnavailableHttpException(
 *                     'Database temporarily unavailable.',
 *                     'db_unavailable'
 *                 );
 *             });
 *
 *             $this->renderable(\Acme\Domain\RateLimitException::class,
 *                 function ($e, $app) {
 *                     return new TooManyRequestsHttpException(
 *                         $e->getMessage(),
 *                         'rate_limited',
 *                         [],
 *                         ['Retry-After' => (string) $e->retryAfter()]
 *                     );
 *                 });
 *         }
 *     }
 *
 * Plugins bind their subclass over the default during boot in
 * `boot/bindings.php`:
 *
 *     $app->singleton(
 *         \FluentCart\Framework\Foundation\Exceptions\ExceptionHandler::class,
 *         \Acme\App\Hooks\Handlers\ExceptionHandler::class
 *     );
 *
 * Renderer return contract — closures may return any of:
 *   - `HttpException`         (rendered via `Route::renderHttpException()`)
 *   - `WP_REST_Response`      (returned to the client as-is)
 *   - `null`                  (skip this entry; try the next match)
 *   - any other value         (treated as `null` — invalid return ignored)
 *
 * A renderer that throws is caught internally and treated as `null` —
 * a buggy renderer never crashes the request.
 *
 * Matching — `render()` walks the registry in **registration order** and
 * returns the first `instanceof` match. The conventional registration
 * order is therefore "specific classes first, broader classes last". The
 * framework default registers nothing; plugins fully control the
 * registry. Re-registering an existing class name overwrites the closure
 * but keeps its registration position.
 */
class ExceptionHandler
{
    /**
     * Registered renderers, keyed by exception class name. Walked in
     * insertion order; PHP associative arrays preserve insertion order
     * and `$arr[$key] = $value` to an existing key updates in place.
     *
     * @var array<string, callable>
     */
    protected $renderables = [];

    /**
     * Construct the handler. Calls `register()` so subclasses can declare
     * their renderables in one place without remembering to call it.
     */
    public function __construct()
    {
        $this->register();
    }

    /**
     * Override in a subclass to declare renderables. The base
     * implementation is a no-op so the framework default acts as a
     * bare registry plugins can add to.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Map an exception class to a renderer closure.
     *
     * @param  string   $class
     * @param  callable $renderer  signature: function ($exception, $app)
     * @return $this
     *
     * @throws \InvalidArgumentException when `$class` is not a non-empty string
     */
    public function renderable($class, callable $renderer)
    {
        if (!is_string($class) || $class === '') {
            throw new InvalidArgumentException(
                'ExceptionHandler::renderable() expects a non-empty class name string '
                . 'as the first argument.'
            );
        }

        $this->renderables[$class] = $renderer;

        return $this;
    }

    /**
     * Remove a previously-registered renderer. No-op if the class
     * wasn't registered. Useful for plugins that want to opt out of a
     * default registered by a parent handler.
     *
     * @param  string $class
     * @return $this
     */
    public function forget($class)
    {
        unset($this->renderables[$class]);

        return $this;
    }

    /**
     * Consult the registry for a thrown exception.
     *
     * Walks `$renderables` in registration order. The first entry whose
     * key the exception is `instanceof` invokes its renderer. The
     * renderer may return an `HttpException`, a `WP_REST_Response`, or
     * `null` (to fall through to the next match). Any thrown exception
     * from the renderer is caught and treated as `null` so a buggy
     * renderer never crashes the request.
     *
     * @param  \Throwable $e
     * @param  mixed      $app  the framework App instance (or null in tests)
     * @return \FluentCart\Framework\Foundation\Exceptions\HttpException|\WP_REST_Response|null
     */
    public function render(Throwable $e, $app = null)
    {
        foreach ($this->renderables as $class => $renderer) {
            if (!($e instanceof $class)) {
                continue;
            }

            try {
                $result = $renderer($e, $app);
            } catch (Throwable $inner) {
                // Renderer crashed. Surface to error_log in debug so the
                // developer sees it, but never let it kill the request.
                if (function_exists('error_log')) {
                    error_log(sprintf(
                        '[WPFluent] ExceptionHandler renderer for %s threw: %s in %s:%d',
                        $class,
                        $inner->getMessage(),
                        $inner->getFile(),
                        $inner->getLine()
                    ));
                }
                return null;
            }

            if ($result instanceof HttpException) {
                return $result;
            }

            if ($result instanceof WP_REST_Response) {
                return $result;
            }

            if ($result === null) {
                continue;
            }

            // Invalid return type (string, int, array, etc.) — log in
            // debug and fall through to the sanitizer. Strict by design:
            // the contract is HttpException | WP_REST_Response | null.
            if (function_exists('error_log')) {
                error_log(sprintf(
                    '[WPFluent] ExceptionHandler renderer for %s returned %s; '
                    . 'expected HttpException | WP_REST_Response | null. Ignoring.',
                    $class,
                    is_object($result) ? get_class($result) : gettype($result)
                ));
            }
            return null;
        }

        return null;
    }

    /**
     * List the class names this handler has renderables registered for.
     * Useful for introspection, debugging, and AI tooling that wants to
     * know which exception types are mapped to safe responses.
     *
     * @return string[]
     */
    public function registeredFor()
    {
        return array_keys($this->renderables);
    }

    /**
     * Whether the handler has a renderer registered for `$class`. Does
     * NOT walk the inheritance chain — exact key match only. Use this
     * for "is THIS specific class registered" checks; use `render()`
     * for "does any registration handle this exception".
     *
     * @param  string $class
     * @return bool
     */
    public function hasRenderable($class)
    {
        return is_string($class) && array_key_exists($class, $this->renderables);
    }
}
