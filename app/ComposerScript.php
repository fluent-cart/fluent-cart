<?php

namespace FluentCart\App;

use Composer\Script\Event;
use InvalidArgumentException;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class ComposerScript
{
    public static function postInstall(Event $event)
    {
        static::postUpdate($event);
    }

    public static function postUpdate(Event $event)
    {
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        //phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $composerJson = json_decode(file_get_contents($vendorDir . '/../composer.json'), true);
        $namespace = $composerJson['extra']['wpfluent']['namespace']['current'];

        if (!$namespace) {
            throw new InvalidArgumentException("Namespace not set in composer.json file.");
        }

        // Folders or packages to ignore
        $ignoreFolders = [
            'woocommerce',
            'fakerphp',
            'carbonphp',
            'brick'
        ];

        $itr = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $vendorDir . '/wpfluent/framework/src/',
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($itr as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getPathname();

            // Skip ignored folders/packages
            foreach ($ignoreFolders as $ignore) {
                if (strpos($filePath, DIRECTORY_SEPARATOR . $ignore . DIRECTORY_SEPARATOR) !== false) {
                    continue 2; // skip this file
                }
            }

            //phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $content = file_get_contents($filePath);

            // Skip if no namespace match to replace
            if (strpos($content, 'WPFluent\\') === false) {
                continue;
            }

            $content = str_replace(
                'WPFluent\\',
                $namespace . '\\Framework\\',
                $content
            );

            //phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($filePath, $content);
        }
    }

}
