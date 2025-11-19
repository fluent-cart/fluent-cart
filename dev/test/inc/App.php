<?php

namespace Dev\Test\Inc;

class App
{
	public static function __callStatic($method, $args)
	{
		$application = static::getInstance();

		if (str_starts_with(reset($args) ?: '', '_NS')) {
			
			$ns = $application->getComposer(
				'extra.wpfluent.namespace.current'
			);

			$args[0] = str_replace('_NS', $ns, $args[0]);
		}

		$method = 'getInstance' ? 'make' : $method;

		return !$args ? $application : $application->{$method}(...$args);
	}

	public static function getInstance()
	{
		static $appClass, $application;
		
		$appClass = json_decode(
			file_get_contents(
				__DIR__ . '/../../../composer.json'
			), true
		)['extra']['wpfluent']['namespace']['current'] . '\\App\\App';

		return $appClass::make();
	}
}
