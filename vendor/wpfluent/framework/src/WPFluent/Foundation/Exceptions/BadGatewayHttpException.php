<?php

namespace FluentCart\Framework\Foundation\Exceptions;

use Throwable;

/**
 * 502 Bad Gateway — an upstream service the plugin depends on returned
 * an unexpected response or could not be reached.
 */
class BadGatewayHttpException extends HttpException
{
    /**
     * @param string          $message
     * @param string          $errorCode
     * @param array           $data
     * @param array           $headers
     * @param \Throwable|null $previous
     */
    public function __construct(
        $message = 'Bad Gateway',
        $errorCode = 'bad_gateway',
        array $data = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(502, $message, $errorCode, $data, $headers, $previous);
    }
}
