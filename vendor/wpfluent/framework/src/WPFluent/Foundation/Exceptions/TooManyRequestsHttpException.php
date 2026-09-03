<?php

namespace FluentCart\Framework\Foundation\Exceptions;

use Throwable;

/**
 * 429 Too Many Requests — the caller has exceeded a rate limit.
 *
 * Typically paired with a Retry-After header. Example:
 *
 *     throw new TooManyRequestsHttpException(
 *         'Slow down.', 'rate_limited', [], ['Retry-After' => 60]
 *     );
 */
class TooManyRequestsHttpException extends HttpException
{
    /**
     * @param string          $message
     * @param string          $errorCode
     * @param array           $data
     * @param array           $headers
     * @param \Throwable|null $previous
     */
    public function __construct(
        $message = 'Too Many Requests',
        $errorCode = 'too_many_requests',
        array $data = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(429, $message, $errorCode, $data, $headers, $previous);
    }
}
