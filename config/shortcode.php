<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

use FluentCart\App\Services\ShortCodeParser\Parsers;

return [
    'parser_separator'    => '.',
    'template_string'     => '{{:template:}}',
    'parsers'             => [
        'wp'          => Parsers\WPParser::class,
        'user'        => Parsers\UserParser::class,
        //'billing' => OrderParser::class,
        'order'       => Parsers\OrderParser::class,
        'settings'    => Parsers\SettingsParser::class,
        'transaction'  => Parsers\TransactionParser::class,
        'item'         => Parsers\ItemParser::class,
        'license'      => Parsers\LicenseParser::class,
        'download'     => Parsers\DownloadParser::class,
        'subscription' => Parsers\SubscriptionParser::class,
    ],
    'parser_references'   => [

    ],
    'template_references' => [

    ],
];
