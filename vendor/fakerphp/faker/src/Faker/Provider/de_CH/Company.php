<?php

namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\de_CH;

class Company extends \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\Company
{
    protected static $formats = ['{{lastName}} {{companySuffix}}', '{{lastName}} {{lastName}} {{companySuffix}}', '{{lastName}}', '{{lastName}}'];
    protected static $companySuffix = ['AG', 'GmbH'];
}