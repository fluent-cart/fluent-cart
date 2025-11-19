<?php

namespace Dev\Factories\Core;

use Faker\Factory as Faker;

abstract class Factory
{
	use Methods;

	protected $count = 0;
	
	protected $factory = null;

	protected static $model = null;

	abstract public function defination($data = []);

	public function __construct($factory = null)
	{
		$this->fake = $factory ?: Faker::create('en_GB');
	}

	public function __call($method, $args = [])
	{
		$method = $method.'Method';

		return $this->{$method}(...$args);
	}

	public static function __callStatic($method, $args = [])
	{
		return (new static)->{$method}(...$args);
	}
}
