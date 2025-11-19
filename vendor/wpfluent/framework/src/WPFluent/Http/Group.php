<?php

namespace FluentCart\Framework\Http;

class Group
{
	protected $router = null;
	protected $callback = null;

	public function __construct($router, $callback)
	{
		$this->router = $router;
		$this->callback = $callback;
	}

	public function __call($method, $params)
	{
		$this->router->{$method}(...$params);

		return $this;
	}

	public function __destruct()
	{
		$this->router->executeGroupCallback($this->callback);
	}
}
