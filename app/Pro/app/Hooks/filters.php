<?php

/**
 * @var $app FluentCartPro\App\Core\Application
 */


// Used to encrypt/decrypt any value. The same
// key is required to decrypt the encrypted value.
$app->addFilter($app->config->get('app.slug') . '_encryption_key', function ($default) {
    // must return a 16 characters long string, for example:
    return implode('', range('a', 'p')); // abcdefghijklmnop
});
