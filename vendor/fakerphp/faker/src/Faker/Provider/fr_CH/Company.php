<?php

namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\fr_CH;

class Company extends \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\fr_FR\Company
{
    protected static $formats = ['{{lastName}} {{companySuffix}}', '{{lastName}} {{lastName}} {{companySuffix}}', '{{lastName}}', '{{lastName}}'];
    protected static $companySuffix = ['AG', 'Sàrl', 'SA', 'GmbH'];
}