<?php

namespace FluentCart\Framework\Http;

class Group
{
	protected $router = null;
	protected $callback = null;
	protected $executed = false;

	public function __construct($router, $callback)
	{
		$this->router = $router;
		$this->callback = $callback;
		$this->router->trackGroup($this);
	}

	public function __call($method, $params)
	{
		$this->router->{$method}(...$params);

		return $this;
	}

	public function execute()
	{
		if (!$this->executed) {
			$this->executed = true;
			$this->router->executeGroupCallback($this->callback);
		}
	}

	public function __destruct()
	{
		$this->execute();
	}
}
