<?php

namespace FluentCart\Framework\Foundation\Exceptions;

use Throwable;

/**
 * 401 Unauthorized — the request lacks valid authentication credentials.
 *
 * Pair with a WWW-Authenticate header when appropriate by passing it
 * via the $headers argument.
 */
class UnauthorizedHttpException extends HttpException
{
    /**
     * @param string          $message
     * @param string          $errorCode
     * @param array           $data
     * @param array           $headers
     * @param \Throwable|null $previous
     */
    public function __construct(
        $message = 'Unauthorized',
        $errorCode = 'unauthorized',
        array $data = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(401, $message, $errorCode, $data, $headers, $previous);
    }
}
