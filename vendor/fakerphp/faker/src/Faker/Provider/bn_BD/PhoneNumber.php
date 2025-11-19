<?php

namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\bn_BD;

class PhoneNumber extends \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\PhoneNumber
{
    public function phoneNumber()
    {
        $number = '+880';
        $number .= static::randomNumber(7);
        return \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\bn_BD\Utils::getBanglaNumber($number);
    }
}