<?php

namespace FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\de_AT;

class Company extends \FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\FluentCart\Faker\Provider\Company
{
    protected static $formats = ['{{lastName}} {{companySuffix}}', '{{lastName}}'];
    protected static $companySuffix = ['AG', 'EWIV', 'Ges.m.b.H.', 'GmbH', 'KEG', 'KG', 'OEG', 'OG', 'OHG', 'SE'];
}