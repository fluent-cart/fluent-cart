<?php

namespace Dev\Factories\Core;

trait Methods
{
	protected $shouldCreateModel = false;

	protected function createMethod($data = [])
	{
		$this->checkForModelExistance();
		$this->shouldCreateModel = true;
		$data = $this->makeMethod($data);
		$this->shouldCreateModel = false;
		return is_array($data) ? (new static::$model)->newCollection($data) : $data;
	}

	protected function makeMethod($data = [])
	{
		$data = is_array($data) ? $data : $data->toArray();
		
		if ($this->count) {
			$result = [];
			
			do {
				$result[] = $this->toArray($data);
			} while (--$this->count);

			return $result;
		}

		return $this->toArray($data);
		
	}

	protected function countMethod($count)
	{
		$this->count = $count;

		return $this;
	}

	protected function freshMethod()
	{
		$this->checkForModelExistance();

		static::$model::truncate();

		return $this;
	}

	protected function resetPrimaryKeyMethod()
	{
		$this->checkForModelExistance();
		
		$db = $GLOBALS['wpdb'];
		$table = (new static::$model)->getTable();
		$db->query('alter table '.$db->prefix.$table.' auto_increment = 1');
		return $this;
	}

	protected function toArray($data = [])
	{
		$defination = $this->defination($data);

		$data = array_merge(
			$defination, array_map(function($value) {
				return is_callable($value) ? $value($this->fake) : $value;
			}, $data)
		);

		return $this->shouldCreateModel ? static::$model::create($data) : $data;
	}

	protected function checkForModelExistance()
	{
		if (!static::$model) {
			if (str_contains(strtolower(php_sapi_name()), 'cli')) {
				if (class_exists($c = 'Symfony\Component\Console\Output\ConsoleOutput')) {
					(new $c)->writeln('<error>The static property '.static::class.'::$model must contain a fully qualified model name.</error>'
					);die;
				}
			}

			throw new \BadMethodCallException(
				'The static property '.static::class.'::$model must contain a model name with namespace.'
			);
		}
	}
}
