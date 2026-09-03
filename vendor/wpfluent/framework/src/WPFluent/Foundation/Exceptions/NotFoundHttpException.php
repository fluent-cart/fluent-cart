<?php

namespace FluentCart\Framework\Foundation\Exceptions;

use Throwable;

/**
 * 404 Not Found — the requested resource does not exist.
 *
 * Note: ORM lookups continue to use FluentCart\Framework\Database\Orm\ModelNotFoundException
 * which has its own dedicated catch in Route::callback(). Use this class for
 * non-ORM 404s (custom lookups, file-not-found surfaced to the client, etc.).
 */
class NotFoundHttpException extends HttpException
{
    /**
     * @param string          $message
     * @param string          $errorCode
     * @param array           $data
     * @param array           $headers
     * @param \Throwable|null $previous
     */
    public function __construct(
        $message = 'Not Found',
        $errorCode = 'not_found',
        array $data = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(404, $message, $errorCode, $data, $headers, $previous);
    }
}
