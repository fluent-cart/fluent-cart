<?php

namespace FluentCart\Framework\Foundation\Exceptions;

use Throwable;

/**
 * 400 Bad Request — the request itself is malformed or missing required
 * inputs that the caller can fix and retry.
 */
class BadRequestHttpException extends HttpException
{
    /**
     * @param string          $message
     * @param string          $errorCode
     * @param array           $data
     * @param array           $headers
     * @param \Throwable|null $previous
     */
    public function __construct(
        $message = 'Bad Request',
        $errorCode = 'bad_request',
        array $data = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(400, $message, $errorCode, $data, $headers, $previous);
    }
}
