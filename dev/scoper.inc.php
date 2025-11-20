<?php

use Isolated\Symfony\Component\Finder\Finder;

/**
 * Build Finder entries from an array of src folder paths.
 *
 * @param array $srcPaths
 * @return array
 */
function buildFinders(array $srcPaths)
{
    $finders = [];

    foreach ($srcPaths as $src) {
        if (is_dir($src)) {
            $finders[] = Finder::create()
                ->files()
                ->in($src)
                ->name('*.php');
        }
    }

    return $finders;
}

$srcPaths = ['/Volumes/Projects/wp/wp-content/plugins/fluent-cart/vendor/openspout/openspout/src/'];

return [
    'prefix' => 'FluentCart',

    'finders' => buildFinders($srcPaths),

    'expose-namespaces' => [
        'Psr\\',
        'Composer\\Autoload\\',
    ],

    'exclude-namespaces' => [
        'Composer\\',
    ],

    'patchers' => [],
];
