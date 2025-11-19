<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway\API;

trait RequestProcessor
{
	private static function processResponse($response)
	{
		if (!empty($response->error)) {
			$errorType = 'general';

			if (!empty($response->error->type)) {
				$errorType = $response->error->type;
			}

			$errorCode = '';

			if (!empty($response->error->code)) {
				$errorCode = $response->error->code . ' : ';
			}

			return static::errorHandler($errorType, $errorCode . $response->error->message);
		}

		if (false !== $response) {
			return $response;
		}
		
		return false;
	}

	private static function errorHandler($code, $message, $data = [])
	{
		return new \WP_Error($code, $message, $data);
	}
}
