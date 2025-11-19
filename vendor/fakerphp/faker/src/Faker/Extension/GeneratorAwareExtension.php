<?php

declare (strict_types=1);
namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension;

use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Generator;
/**
 * @experimental This interface is experimental and does not fall under our BC promise
 */
interface GeneratorAwareExtension extends \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Extension
{
    /**
     * This method MUST be implemented in such a way as to retain the
     * immutability of the extension, and MUST return an instance that has the
     * new Generator.
     */
    public function withGenerator(\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Generator $generator): \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Extension;
}