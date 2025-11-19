<?php

namespace FluentCart\Framework\Foundation;

use Exception;
use WP_Error;

/**
 * Exception Class.
 */
class WPException extends Exception {

	/**
	 * Http code
	 * @var integer
	 */
	protected $code = 400;

	/**
	 * Exception message.
	 * @var string
	 */
	protected $message = '';

	/**
	 * Error messages
	 * @var array
	 */
	protected $errorData = [];

	/**
	 * WPError object
	 * @var \WP_Error
	 */
	protected $wpError = null;

	/**
	 * Construct the WPException instance
	 * @param WP_Error $wpError
	 */
	public function __construct(WP_Error $wpError)
	{
		$this->wpError = $wpError;
		$this->errorData = $wpError->get_error_data();
		$this->message = $wpError->get_error_message();

		if (is_array($this->errorData) && isset($this->errorData['status'])) {
			$this->code = $this->errorData['status'];
		}

		parent::__construct($this->message, $this->code);
	}

	/**
	 * Get the error messages
	 * @return array
	 */
	public function errors()
	{
		return $this->toArray();
	}

	/**
	 * Retrive the full formatted error messages.
	 * @return array
	 */
	protected function toArray()
	{
		$errors = [];

		foreach ((array) $this->wpError->errors as $code => $messages) {
			foreach ((array) $messages as $message) {
				$errors[] = [
					'code'    => $code,
					'message' => $message,
					'data'    => $this->wpError->get_error_data($code),
				];
			}
		}

		return $errors;
	}
}
