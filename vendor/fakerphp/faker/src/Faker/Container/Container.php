<?php

declare (strict_types=1);
namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Container;

use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Extension;
/**
 * A simple implementation of a container.
 *
 * @experimental This class is experimental and does not fall under our BC promise
 */
final class Container implements \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Container\ContainerInterface
{
    /**
     * @var array<string, callable|object|string>
     */
    private array $definitions;
    private array $services = [];
    /**
     * Create a container object with a set of definitions. The array value MUST
     * produce an object that implements Extension.
     *
     * @param array<string, callable|object|string> $definitions
     */
    public function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }
    /**
     * Retrieve a definition from the container.
     *
     * @param string $id
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws ContainerException
     * @throws NotInContainerException
     */
    public function get($id): \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Extension
    {
        if (!is_string($id)) {
            throw new \InvalidArgumentException(sprintf('First argument of %s::get() must be string', self::class));
        }
        if (array_key_exists($id, $this->services)) {
            return $this->services[$id];
        }
        if (!$this->has($id)) {
            throw new \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Container\NotInContainerException(sprintf('There is not service with id "%s" in the container.', $id));
        }
        $definition = $this->definitions[$id];
        $service = $this->getService($id, $definition);
        if (!$service instanceof \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Extension) {
            throw new \RuntimeException(sprintf('Service resolved for identifier "%s" does not implement the %s" interface.', $id, \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Extension::class));
        }
        $this->services[$id] = $service;
        return $service;
    }
    /**
     * Get the service from a definition.
     *
     * @param callable|object|string $definition
     */
    private function getService(string $id, $definition)
    {
        if (is_callable($definition)) {
            try {
                return $definition();
            } catch (\Throwable $e) {
                throw new \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Container\ContainerException(sprintf('Error while invoking callable for "%s"', $id), 0, $e);
            }
        } elseif (is_object($definition)) {
            return $definition;
        } elseif (is_string($definition)) {
            if (class_exists($definition)) {
                try {
                    return new $definition();
                } catch (\Throwable $e) {
                    throw new \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Container\ContainerException(sprintf('Could not instantiate class "%s"', $id), 0, $e);
                }
            }
            throw new \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Container\ContainerException(sprintf('Could not instantiate class "%s". Class was not found.', $id));
        } else {
            throw new \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Container\ContainerException(sprintf('Invalid type for definition with id "%s"', $id));
        }
    }
    /**
     * Check if the container contains a given identifier.
     *
     * @param string $id
     *
     * @throws \InvalidArgumentException
     */
    public function has($id): bool
    {
        if (!is_string($id)) {
            throw new \InvalidArgumentException(sprintf('First argument of %s::get() must be string', self::class));
        }
        return array_key_exists($id, $this->definitions);
    }
}