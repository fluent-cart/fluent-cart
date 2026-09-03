<?php

namespace FluentCart\Framework\Foundation\Exceptions;

use Throwable;

/**
 * 503 Service Unavailable — the plugin is intentionally unavailable
 * (maintenance, missing configuration, dependency down).
 *
 * Often paired with a Retry-After header.
 */
class ServiceUnavailableHttpException extends HttpException
{
    /**
     * @param string          $message
     * @param string          $errorCode
     * @param array           $data
     * @param array           $headers
     * @param \Throwable|null $previous
     */
    public function __construct(
        $message = 'Service Unavailable',
        $errorCode = 'service_unavailable',
        array $data = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(503, $message, $errorCode, $data, $headers, $previous);
    }
}
