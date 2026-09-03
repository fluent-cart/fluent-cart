<?php

namespace FluentCart\Framework\Foundation\Exceptions;

use Exception;
use Throwable;

/**
 * Base class for exceptions that represent an intentional, user-safe
 * HTTP response. Carries an HTTP status code, a machine-readable error
 * code, optional structured data, and optional response headers.
 *
 * Subclasses pin the status code (NotFoundHttpException = 404, etc.) so
 * call sites read as `throw new ForbiddenHttpException($why)` — the
 * status is implicit in the type.
 *
 * Route::callback() catches HttpException before its handleUnknownException
 * sanitizer, so the message and status reach the client verbatim. This is
 * the opt-in escape hatch from the production message sanitization that
 * protects against leaking PDO / file-system / transport internals.
 */
class HttpException extends Exception
{
    /**
     * HTTP status code to report.
     *
     * @var int
     */
    protected $statusCode;

    /**
     * Machine-readable error code, stable across versions
     * (e.g. 'widgeteer_token_rejected') for frontend branching.
     *
     * @var string
     */
    protected $errorCode;

    /**
     * Optional structured data emitted in the response body.
     *
     * @var array
     */
    protected $data;

    /**
     * Optional response headers (e.g. Retry-After, WWW-Authenticate).
     *
     * @var array
     */
    protected $headers;

    /**
     * @param int             $statusCode
     * @param string          $message
     * @param string          $errorCode
     * @param array           $data
     * @param array           $headers
     * @param \Throwable|null $previous
     */
    public function __construct(
        $statusCode,
        $message = '',
        $errorCode = 'http_exception',
        array $data = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        $this->statusCode = (int) $statusCode;
        $this->errorCode = (string) $errorCode;
        $this->data = $data;
        $this->headers = $headers;

        // Mirror the status into Exception's $code so getCode() also returns
        // it. Preserves BC for plugins that read the status via the deprecated
        // shims' inherited getCode() before this hierarchy existed
        // (e.g. `new ForbiddenException($msg, 403)` → `$e->getCode() === 403`).
        // getStatusCode() remains the authoritative accessor.
        parent::__construct((string) $message, (int) $statusCode, $previous);
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }
}
