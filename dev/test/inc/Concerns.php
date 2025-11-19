<?php

namespace Dev\Test\Inc;

use InvalidArgumentException;

trait Concerns
{
	public function get($uri, $params = [])
	{
		$response = $this->server->dispatch(
			$this->createRequest('GET', $uri, $params)
		);

		return new Response($response);
	}

	public function post($uri, $params = [])
	{
		$response = $this->server->dispatch(
			$this->createRequest('POST', $uri, $params)
		);

		return new Response($response);
	}

	public function createRequest($method, $uri, $params = [])
    {
    	do_action('rest_api_init');

        $request = new \WP_REST_Request(
        	$method, $this->getRestNamespace() . trim($uri, '/')
        );

        if (count($params)) {
    		foreach ($params as $param => $value) {
                $request->set_param($param, $value);
            }
        }

        return $request;
    }

	protected function getRestNamespace()
	{
		$ns = $this->plugin->config->get('app.rest_namespace');

		$ver = $this->plugin->config->get('app.rest_version');

		return '/' . $ns . '/' . $ver . '/';
	}

	public function login($id)
	{
		return $this->setUser($id);
	}

	public function logout()
	{
		return $this->setUser(0);
	}

	public function setUser($id)
	{
		$exception = new InvalidArgumentException(
			'The argument must be a valid user ID or WP_User object'
		);

		if (is_int($id) || $id instanceof \WP_User) {
			$user = wp_set_current_user(
				is_object($id) ? $id->ID : $id
			);

			if ($id && !$user->ID) {
				throw $exception;
			}

			return $this;
		}

		throw $exception;
	}
}
