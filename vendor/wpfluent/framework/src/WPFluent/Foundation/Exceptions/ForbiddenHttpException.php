<?php

namespace FluentCart\Framework\Foundation\Exceptions;

use Throwable;

/**
 * 403 Forbidden — the request is authenticated but the caller is not
 * permitted to perform the action.
 */
class ForbiddenHttpException extends HttpException
{
    /**
     * @param string          $message
     * @param string          $errorCode
     * @param array           $data
     * @param array           $headers
     * @param \Throwable|null $previous
     */
    public function __construct(
        $message = 'Forbidden',
        $errorCode = 'forbidden',
        array $data = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(403, $message, $errorCode, $data, $headers, $previous);
    }
}
