<?php

namespace FluentCart\Framework\Support;

use ReflectionClass;
use FluentCart\Framework\Support\ExceptionInterface;
use InvalidArgumentException as BaseInvalidArgumentException;

use function sprintf;
use function trait_exists;
use function interface_exists;

/**
 * Exception for invalid arguments provided to the instantiator
 */
class InvalidArgumentException extends BaseInvalidArgumentException implements ExceptionInterface
{
    public static function fromNonExistingClass(string $className): self
    {
        if (interface_exists($className)) {
            return new self(
                sprintf('The provided type "%s" is an interface, and cannot be instantiated', $className)
            );
        }

        if (trait_exists($className)) {
            return new self(
                sprintf('The provided type "%s" is a trait, and cannot be instantiated', $className)
            );
        }

        return new self(sprintf('The provided class "%s" does not exist', $className));
    }

    /**
     * @phpstan-param ReflectionClass<T> $reflectionClass
     *
     * @template T of object
     */
    public static function fromAbstractClass(ReflectionClass $reflectionClass): self
    {
        return new self(sprintf(
            'The provided class "%s" is abstract, and cannot be instantiated',
            $reflectionClass->getName()
        ));
    }

    public static function fromEnum(string $className): self
    {
        return new self(sprintf(
            'The provided class "%s" is an enum, and cannot be instantiated',
            $className
        ));
    }
}
