<?php

namespace FluentCart\Api\Exceptions;

class WPErrorException extends \Exception
{
	public function __construct(WP_Error $wpError, $message = "", $code = 0 , Exception $previous = NULL)
    {
        $this->wpError = $wpError;

        $this->code = $code ?: $wpError->get_error_code();
        
        $this->message = $message ?: $wpError->get_error_message();

        parent::__construct($message, $code, $previous);
    }

    public function errors()
    {
        return $this->wpError->get_error_messages();
    }
}
