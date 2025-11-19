<?php

namespace FluentCart\Framework\Foundation;

use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Validator\ValidationException;

/**
 * @property \FluentCart\Framework\Http\Request\Request $request
 */
abstract class RequestGuard
{
    /**
     * The request instance.
     * @var \FluentCart\Framework\Http\Request\Request
     */
    protected $request;

    /**
     * Retrive the validation rules
     * @return array
     */
    public function rules()
    {
        return [];
    }

    /**
     * Retrive the validation messages set by the developer
     * @return array
     */
    public function messages()
    {
        return [];
    }

    /**
     * Allow the developer tinker with data before the validation.
     * @return array
     */
    public function beforeValidation()
    {
        return [];
    }

    /**
     * Allow the developer tinker with data after the validation.
     * @return array
     */
    public function afterValidation($validator)
    {
        return [];
    }

    /**
     * Validate the request.
     * 
     * @param  array $rules Optional
     * @param  array $messages Optional
     * @return array Request Data
     * @throws \FluentCart\Framework\Validator\ValidationException
     */
    public function validate($rules = [], $messages = [])
    {
        try {
            return $this->request->validate(
                $rules ?: (array) $this->rules(),
                $messages ?: (array) $this->messages()
            );
        } catch (ValidationException $e) {
            
            $validator = App::make('validator');
            
            $validator->addError($e->errors());

            if (!($errors = $validator->errors())) {
                return $this->afterValidation($validator);
            }

            $e = new ValidationException(
                'Unprocessable Entity!', 422, null, $e->errors()
            );

            if ($this->shouldThrowException()) {
                throw $e;
            } else {
                App::make()->doCustomAction('handle_exception', $e);
            }
        }
    }

    /**
     * Check if exceptions should be thrown.
     * 
     * @return bool
     */
    protected function shouldThrowException()
    {
        $isTrue = $this->isRest();
        $isTrue = $isTrue || str_contains('test', App::make()->env());
        $isTrue = $isTrue || str_contains(strtolower(php_sapi_name()), 'cli');
        return $isTrue;
    }

    /**
     * Handles validation including before and after calls
     *
     * @throws \FluentCart\Framework\Validator\ValidationException
     * @return void
     */
    public static function applyValidation()
    {
        $instance = new static;

        $request = App::make('request');

        $request->merge($instance->beforeValidation());

        $instance->validate();

        $request->merge($instance->afterValidation(App::make('validator')));
    }

    /**
     * Get an input element from the request.
     *
     * @param  string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->get($key);
    }

    /**
     * Set an input element to the request.
     *
     * @param  string $key
     * @return mixed
     */
    public function __set($key, $value)
    {
        return $this->set($key, $value);
    }

    /**
     * Set the request instance.
     * 
     * @param \FluentCart\Framework\Http\Request\Request $request
     */
    public function setRequestInstance($request)
    {
        $this->request = $request;
    }

    /**
     * Handle the dynamic method calls
     * @param  string $method
     * @param  array $params
     * @return mixed
     */
    public function __call($method, $params)
    {
        return call_user_func_array(
            [$this->request, $method], $params
        );
    }
}
