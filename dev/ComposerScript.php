<?php

namespace FluentCart\Dev;

use Exception;
use Composer\Script\Event;
use InvalidArgumentException;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * Composer script to automatically update namespaces and autoload
 * entries for any packages after Composer install/update.
 */
class ComposerScript
{
    /**
     * List of packages to exclude from namespace updates.
     *
     * @var array
     */
    private static $excludables = [];

    /**
     * Path to the JSON file used for tracking updated files.
     *
     * @var string
     */
    private static $composerFiles = __DIR__ . '/wpf.json';

    /**
     * Main entry point triggered by Composer.
     *
     * @param Event $event Composer event instance.
     * @return void
     */
    public static function run(Event $event)
    {
        static::execute($event);

        shell_exec('composer dump-autoload');
    }

    /**
     * Executes the full namespace update process.
     *
     * @param Event $event Composer event instance.
     * @return void
     */
    private static function execute($event)
    {
        echo "Please wait, this may take a while." . PHP_EOL;

        static::resetReportFile();

        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');

        $namespace = static::getNamespaceFromRootComposer($vendorDir);

        static::updateNamespaceInWPFluent($vendorDir, $namespace);

        static::processVendorPackages($vendorDir, $namespace, $event);

        static::updateAutoloadFileEntries($vendorDir, $namespace);
    }

    /**
     * Clears the report file to ensure a fresh run.
     *
     * @return void
     */
    private static function resetReportFile()
    {
        file_put_contents(static::$composerFiles, '');
    }

    /**
     * Reads the root composer.json file to determine the current namespace.
     *
     * @param string $vendorDir Path to the vendor directory.
     * @return string The configured namespace.
     * @throws InvalidArgumentException If composer.json or namespace is missing.
     */
    private static function getNamespaceFromRootComposer($vendorDir)
    {
        $composerJson = $vendorDir . '/../composer.json';

        if (!file_exists($composerJson)) {
            throw new InvalidArgumentException("composer.json not found.");
        }

        $data = json_decode(file_get_contents($composerJson));

        $namespace = $data->extra->wpfluent->namespace->current ?? null;

        if (!$namespace) {
            throw new InvalidArgumentException(
                "Namespace not set in composer.json."
            );
        }

        // Load exclusion list
        static::$excludables = array_merge(
            static::$excludables,
            (array) $data->extra->wpfluent->excludes ?? []
        );

        return $namespace;
    }

    /**
     * Updates hardcoded namespaces inside the WPFluent framework source.
     *
     * @param string $vendorDir Path to the vendor directory.
     * @param string $namespace Target namespace to apply.
     * @return void
     */
    private static function updateNamespaceInWPFluent($vendorDir, $namespace)
    {
        $itr = static::getFilesIterator(
            $vendorDir . '/wpfluent/framework/src/'
        );

        foreach ($itr as $file) {
            if ($file->isDir()) continue;

            $updated = str_replace(
                ['WPFluent\\', 'WPFluentPackage\\'],
                [$namespace . '\\Framework\\', $namespace . '\\'],
                file_get_contents($file->getPathname())
            );

            file_put_contents($file->getPathname(), $updated);
        }
    }

    /**
     * Processes all installed vendor packages to apply namespace changes.
     *
     * @param string $vendorDir Path to the vendor directory.
     * @param string $namespace Target namespace.
     * @param Event  $event Composer event instance.
     * @return void
     */
    private static function processVendorPackages($vendorDir, $namespace, $event)
    {
        $installedFile = $vendorDir . '/composer/installed.json';
        $installedData = static::readJson($installedFile);

        foreach ($installedData['packages'] as &$package) {
            try {
                if ($package['name'] === 'wpfluent/framework') {
                    $package['autoload']['psr-4'] = [
                        "{$namespace}\\Framework\\" => "src/WPFluent"
                    ];
                    continue;
                }

                if (str_contains($package['name'], 'wpfluent')) {
                    static::processWPFluentPackage(
                        $package, $vendorDir, $namespace
                    );
                } else {
                    // Apply exclusion logic
                    $excludes = static::$excludables;

                    if (in_array('*', $excludes, true)) {
                        continue; // skip all generic packages
                    }

                    if (!in_array($package['name'], $excludes, true)) {
                        static::processGenericPackage(
                            $package, $vendorDir, $namespace
                        );
                    }
                }
            } catch (Exception $e) {
                echo $e->getMessage() . PHP_EOL;
            }
        }

        static::writeJson($installedFile, $installedData);
    }

    /**
     * Updates namespace for generic (non-WPFluent) packages.
     *
     * @param array  $package Composer package data.
     * @param string $vendorDir Path to vendor directory.
     * @param string $namespace Target namespace.
     * @return void
     */
    private static function processGenericPackage(
        &$package,
        $vendorDir,
        $namespace
    )
    {
        $packageDir = $vendorDir . "/{$package['name']}/src/";

        static::updateComposerPackageNamespace(
            str_replace('src/', '', $packageDir), $namespace
        );

        if (!empty($package['autoload']['psr-4'])) {
            static::updatePsr4NamespaceArray(
                $package['autoload']['psr-4'], $namespace
            );
        }

        $packageJsonFile = $vendorDir . '/' . $package['name'] . '/composer.json';

        $packageComposerJson = static::readJson($packageJsonFile);

        static::updatePsr4NamespaceArray(
            $packageComposerJson['autoload']['psr-4'], $namespace
        );

        static::writeJson($packageJsonFile, $packageComposerJson);
    }

    /**
     * Updates namespace for WPFluent-specific packages.
     *
     * @param array  $package Composer package data.
     * @param string $vendorDir Path to vendor directory.
     * @param string $namespace Target namespace.
     * @return void
     */
    private static function processWPFluentPackage(
        &$package,
        $vendorDir,
        $namespace
    )
    {
        foreach (static::getFilesIterator($vendorDir . "/{$package['name']}/src/") as $item) {
            if ($item->isDir()) continue;

            $updated = str_replace(
                ['Test\\', 'WPFluent\\', 'WPFluentPackage\\', 'WpfluentPackage\\'],
                [$namespace . '\\Framework\\', $namespace . '\\Framework\\', $namespace . '\\', $namespace . '\\'],
                file_get_contents($item->getPathname())
            );

            file_put_contents($item->getPathname(), $updated);
        }

        $psr4Key = str_replace(
            ['WPFluentPackage', 'WpfluentPackage'],
            $namespace,
            array_key_first($package['autoload']['psr-4'])
        );

        $package['autoload']['psr-4'] = [$psr4Key => "src/"];

        $packageJsonFile     = $vendorDir . '/' . $package['name'] . '/composer.json';
        $packageComposerJson = static::readJson($packageJsonFile);

        $packageComposerJson['autoload']['psr-4'] = [$psr4Key => "src/"];
        static::writeJson($packageJsonFile, $packageComposerJson);
    }

    /**
     * Updates file autoload entries for changed file paths.
     *
     * @param string $vendorDir Path to vendor directory.
     * @param string $namespace Target namespace.
     * @return void
     */
    private static function updateAutoloadFileEntries($vendorDir, $namespace)
    {
        $reportData    = static::readJson(static::$composerFiles);
        $installedFile = $vendorDir . '/composer/installed.json';
        $installedJson = static::readJson($installedFile);

        if (!$reportData) return;

        foreach ($reportData as $composerFile => $file) {
            $newName  = static::renameFile($file['path'], $namespace);
            $composer = static::readJson($composerFile);

            if (isset($composer['autoload']['files'])) {
                foreach ($composer['autoload']['files'] as $key => $fileName) {
                    if ($file['name'] !== $fileName) continue;

                    $composer['autoload']['files'][$key] = static::replaceFileName($file['name'], $newName);

                    foreach ($installedJson['packages'] as &$packageData) {
                        if ($packageData['name'] === $file['package']) {
                            $packageData['autoload']['files'] = $composer['autoload']['files'];
                        }
                    }
                }
            }

            static::writeJson($composerFile, $composer);

            static::writeJson($installedFile, $installedJson);
        }
    }

    /* ---------------------- Helpers ---------------------- */

    /**
     * Returns an iterator for all files in a directory (recursive).
     *
     * @param string $directory Directory path.
     * @return RecursiveIteratorIterator File iterator.
     * @throws InvalidArgumentException If directory does not exist.
     */
    private static function getFilesIterator($directory)
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException(
                sprintf(
                /* translators: %s is the directory path */
                    esc_html__('The directory "%s" does not exist.', 'fluent-cart'),
                    esc_html($directory)
                )
            );
        }

        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory, RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
    }

    /**
     * Reads and decodes a JSON file.
     *
     * @param string $path Path to JSON file.
     * @return array Decoded JSON as associative array.
     */
    private static function readJson($path)
    {
        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false) return [];

        $data = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : [];
    }

    /**
     * Encodes and writes an array to a JSON file.
     *
     * @param string $path Path to JSON file.
     * @param array  $data Data to encode.
     * @return void
     */
    private static function writeJson($path, $data)
    {
        file_put_contents($path, json_encode(
            $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * Runs external namespace fixer script for a given directory.
     *
     * @param string $dir Path to directory.
     * @param string $namespace Target namespace.
     * @return void
     */
    private static function updateComposerPackageNamespace($dir, $namespace)
    {
        if (file_exists($dir)) {
            (require __DIR__ . '/cli/Namespace/fixer.php')(
                $dir, $namespace, static::$composerFiles
            );
        }
    }

    /**
     * Renames a file to include the namespace as a prefix.
     *
     * @param string $path Original file path.
     * @param string $namespace Target namespace.
     * @return string New file path.
     */
    private static function renameFile($path, $namespace)
    {
        $nameParts = explode('/', $path);
        $name = array_pop($nameParts);

        if (str_starts_with($name, $namespace)) {
            return $path;
        }

        $dirPath = implode('/', $nameParts);
        $newName = $dirPath . '/' . $namespace . '-' . md5($path) . '-' . $name;

        return rename($path, $newName) ? $newName : $path;
    }

    /**
     * Updates a file name inside an autoload 'files' entry.
     *
     * @param string $oldName Original file name.
     * @param string $newName New file name.
     * @return string Updated file name/path.
     */
    private static function replaceFileName($oldName, $newName)
    {
        $parts = explode(DIRECTORY_SEPARATOR, $oldName);

        if (count($parts) > 1) {
            array_pop($parts);
            return rtrim(
                implode(DIRECTORY_SEPARATOR, $parts),
                DIRECTORY_SEPARATOR
            )
            . DIRECTORY_SEPARATOR
            . pathinfo($newName, PATHINFO_BASENAME);
        }

        return pathinfo($newName, PATHINFO_BASENAME);
    }

    /**
     * Updates PSR-4 namespace mapping array by prefixing with target namespace.
     *
     * @param array<string,string> $psr4 PSR-4 mapping array.
     * @param string               $namespace Target namespace.
     * @return void
     */
    private static function updatePsr4NamespaceArray(&$psr4, $namespace)
    {
        if (!$psr4) return;
        
        foreach ($psr4 as $key => $value) {
            if (!str_contains($key, $namespace)) {
                $psr4[$namespace . '\\' . $key] = $value;
                unset($psr4[$key]);
            }
        }
    }
}
