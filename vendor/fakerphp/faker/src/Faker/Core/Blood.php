<?php

declare (strict_types=1);
namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Core;

use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\BloodExtension;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Helper;
/**
 * @experimental This class is experimental and does not fall under our BC promise
 */
final class Blood implements \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\BloodExtension
{
    /**
     * @var string[]
     */
    private array $bloodTypes = ['A', 'AB', 'B', 'O'];
    /**
     * @var string[]
     */
    private array $bloodRhFactors = ['+', '-'];
    public function bloodType(): string
    {
        return \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Helper::randomElement($this->bloodTypes);
    }
    public function bloodRh(): string
    {
        return \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Helper::randomElement($this->bloodRhFactors);
    }
    public function bloodGroup(): string
    {
        return sprintf('%s%s', $this->bloodType(), $this->bloodRh());
    }
}