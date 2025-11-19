<?php

namespace FluentCart\Framework\Validator;

use Exception;

class ValidationException extends Exception
{
    /**
     * The validation errors
     * 
     * @var array
     */
    protected $errors = [];

    /**
     * Construct the Validation Exception Instance
     * @param string         $message
     * @param integer        $code
     * @param Exception|null $previous
     * @param array          $errors
     */
    public function __construct($message = "", $code = 0 , ?Exception $previous = null, $errors = [])
    {
        $this->errors = $errors;

        parent::__construct($message, $code, $previous);
    }

    /**
     * Retrieve the validation errors
     * @return array
     */
    public function errors()
    {
        return $this->errors;
    }
}
