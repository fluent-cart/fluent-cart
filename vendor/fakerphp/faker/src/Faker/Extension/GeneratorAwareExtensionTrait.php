<?php

declare (strict_types=1);
namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension;

use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Generator;
/**
 * A helper trait to be used with GeneratorAwareExtension.
 */
trait GeneratorAwareExtensionTrait
{
    /**
     * @var Generator|null
     */
    private $generator;
    /**
     * @return static
     */
    public function withGenerator(\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Generator $generator): \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Extension
    {
        $instance = clone $this;
        $instance->generator = $generator;
        return $instance;
    }
}