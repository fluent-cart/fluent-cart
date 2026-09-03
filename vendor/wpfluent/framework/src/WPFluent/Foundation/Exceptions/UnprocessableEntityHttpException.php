<?php

namespace FluentCart\Framework\Foundation\Exceptions;

use Throwable;

/**
 * 422 Unprocessable Entity — the request is syntactically valid but
 * semantically rejected (business-rule violation, etc.).
 *
 * For framework validation failures, the existing
 * FluentCart\Framework\Validator\ValidationException retains its dedicated catch in
 * Route::callback(). Use this class for application-level semantic errors.
 */
class UnprocessableEntityHttpException extends HttpException
{
    /**
     * @param string          $message
     * @param string          $errorCode
     * @param array           $data
     * @param array           $headers
     * @param \Throwable|null $previous
     */
    public function __construct(
        $message = 'Unprocessable Entity',
        $errorCode = 'unprocessable_entity',
        array $data = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(422, $message, $errorCode, $data, $headers, $previous);
    }
}
