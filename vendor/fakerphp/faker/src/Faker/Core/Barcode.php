<?php

declare (strict_types=1);
namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Core;

use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Calculator;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Calculator\Ean;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Calculator\Isbn;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\BarcodeExtension;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Helper;
use FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\NumberExtension;
/**
 * @experimental This class is experimental and does not fall under our BC promise
 */
final class Barcode implements \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\BarcodeExtension
{
    private \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\NumberExtension $numberExtension;
    public function __construct(\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\NumberExtension $numberExtension = null)
    {
        $this->numberExtension = $numberExtension ?: new \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Core\Number();
    }
    private function ean(int $length = 13): string
    {
        $code = \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Helper::numerify(str_repeat('#', $length - 1));
        return sprintf('%s%s', $code, \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Calculator\Ean::checksum($code));
    }
    public function ean13(): string
    {
        return $this->ean();
    }
    public function ean8(): string
    {
        return $this->ean(8);
    }
    public function isbn10(): string
    {
        $code = \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Helper::numerify(str_repeat('#', 9));
        return sprintf('%s%s', $code, \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Calculator\Isbn::checksum($code));
    }
    public function isbn13(): string
    {
        $code = '97' . $this->numberExtension->numberBetween(8, 9) . \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Extension\Helper::numerify(str_repeat('#', 9));
        return sprintf('%s%s', $code, \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Calculator\Ean::checksum($code));
    }
}