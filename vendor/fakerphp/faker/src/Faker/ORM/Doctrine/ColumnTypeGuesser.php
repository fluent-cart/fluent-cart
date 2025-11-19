<?php

namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\ORM\Doctrine;

use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Doctrine\Common\Persistence\Mapping\ClassMetadata;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Generator;
require_once 'backward-compatibility.php';
class ColumnTypeGuesser
{
    protected $generator;
    public function __construct(\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Generator $generator)
    {
        $this->generator = $generator;
    }
    /**
     * @return \Closure|null
     */
    public function guessFormat($fieldName, \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Doctrine\Common\Persistence\Mapping\ClassMetadata $class)
    {
        $generator = $this->generator;
        $type = $class->getTypeOfField($fieldName);
        switch ($type) {
            case 'boolean':
                return static function () use ($generator) {
                    return $generator->boolean;
                };
            case 'decimal':
                $size = $class->fieldMappings[$fieldName]['precision'] ?? 2;
                return static function () use ($generator, $size) {
                    return $generator->randomNumber($size + 2) / 100;
                };
            case 'smallint':
                return static function () use ($generator) {
                    return $generator->numberBetween(0, 65535);
                };
            case 'integer':
                return static function () use ($generator) {
                    return $generator->numberBetween(0, 2147483647);
                };
            case 'bigint':
                return static function () use ($generator) {
                    return $generator->numberBetween(0, PHP_INT_MAX);
                };
            case 'float':
                return static function () use ($generator) {
                    return $generator->randomFloat();
                };
            case 'string':
                $size = $class->fieldMappings[$fieldName]['length'] ?? 255;
                return static function () use ($generator, $size) {
                    return $generator->text($size);
                };
            case 'text':
                return static function () use ($generator) {
                    return $generator->text;
                };
            case 'datetime':
            case 'date':
            case 'time':
                return static function () use ($generator) {
                    return $generator->datetime;
                };
            case 'datetime_immutable':
            case 'date_immutable':
            case 'time_immutable':
                return static function () use ($generator) {
                    return \DateTimeImmutable::createFromMutable($generator->datetime);
                };
            default:
                // no smart way to guess what the user expects here
                return null;
        }
    }
}