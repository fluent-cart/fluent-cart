<?php

namespace Dev\Test\Inc;

class Response
{
	protected $response = null;

	public function __construct($response)
	{
		$this->response = $response;
	}

	public function dd()
	{
		dd([
			'status' => $this->response->get_status(),
			'data' => $this->response->get_data()
		]);
	}

	public function ddd()
	{
		ddd([
			'status' => $this->response->get_status(),
			'data' => $this->response->get_data()
		]);
	}

	public function isOkay()
	{
		return $this->getStatus() === 200;
	}

	public function isForbidden()
	{
		return $this->getStatus() === 403;
	}

	public function __call($method, $params = [])
	{
		if (preg_match('/[A-Z]/', $method, $matches)) {
			foreach ($matches as $match) {
				$method = str_replace($match, '_'.strtolower($match), $method);
			}
		}
		
		return $this->response->{$method}(...$params);
	}
}
