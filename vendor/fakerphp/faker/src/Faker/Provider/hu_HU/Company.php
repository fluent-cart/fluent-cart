<?php

namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\hu_HU;

class Company extends \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\Company
{
    protected static $formats = ['{{lastName}} {{companySuffix}}', '{{lastName}}'];
    protected static $companySuffix = ['Kft.', 'és Tsa', 'Kht', 'Zrt.', 'Nyrt.', 'Bt.'];
}