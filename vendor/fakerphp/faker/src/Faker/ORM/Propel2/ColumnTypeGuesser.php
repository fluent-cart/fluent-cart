<?php

namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\ORM\Propel2;

use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Runtime\Map\ColumnMap;
class ColumnTypeGuesser
{
    protected $generator;
    public function __construct(\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Generator $generator)
    {
        $this->generator = $generator;
    }
    /**
     * @return \Closure|null
     */
    public function guessFormat(\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Runtime\Map\ColumnMap $column)
    {
        $generator = $this->generator;
        if ($column->isTemporal()) {
            if ($column->getType() == \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::BU_DATE || $column->getType() == \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::BU_TIMESTAMP) {
                return static function () use ($generator) {
                    return $generator->dateTime;
                };
            }
            return static function () use ($generator) {
                return $generator->dateTimeAD;
            };
        }
        $type = $column->getType();
        switch ($type) {
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::BOOLEAN:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::BOOLEAN_EMU:
                return static function () use ($generator) {
                    return $generator->boolean;
                };
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::NUMERIC:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::DECIMAL:
                $size = $column->getSize();
                return static function () use ($generator, $size) {
                    return $generator->randomNumber($size + 2) / 100;
                };
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::TINYINT:
                return static function () use ($generator) {
                    return $generator->numberBetween(0, 127);
                };
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::SMALLINT:
                return static function () use ($generator) {
                    return $generator->numberBetween(0, 32767);
                };
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::INTEGER:
                return static function () use ($generator) {
                    return $generator->numberBetween(0, 2147483647);
                };
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::BIGINT:
                return static function () use ($generator) {
                    return $generator->numberBetween(0, PHP_INT_MAX);
                };
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::FLOAT:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::DOUBLE:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::REAL:
                return static function () use ($generator) {
                    return $generator->randomFloat();
                };
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::CHAR:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::VARCHAR:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::BINARY:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::VARBINARY:
                $size = $column->getSize();
                return static function () use ($generator, $size) {
                    return $generator->text($size);
                };
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::LONGVARCHAR:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::LONGVARBINARY:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::CLOB:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::CLOB_EMU:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::BLOB:
                return static function () use ($generator) {
                    return $generator->text;
                };
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::ENUM:
                $valueSet = $column->getValueSet();
                return static function () use ($generator, $valueSet) {
                    return $generator->randomElement($valueSet);
                };
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::OBJECT:
            case \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Propel\Generator\Model\PropelTypes::PHP_ARRAY:
            default:
                // no smart way to guess what the user expects here
                return null;
        }
    }
}