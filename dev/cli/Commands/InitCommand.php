<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

class InitCommand extends BaseCommand
{
    protected static $defaultName = 'test:init';

    protected function configure()
    {
        $this
        ->setAliases(['init'])
        ->setDescription(
            'Initializes the WordPress test suite and environment.'
        );
    }

    protected function execute($input, $output)
    {
        $wpRootDir = $this->wpRoot;

        $loaderPath = $this->root . '/dev/vendor/autoload.php';

        // If autoloader is missing, run composer update
        if (!file_exists($loaderPath)) {
            $output->writeln('<info>Autoloader missing. Running composer update, please wait...</info>');
            chdir($this->root . '/dev');
            exec('composer update 2>&1', $lines);
            foreach ($lines as $line) {
                $output->writeln($line);
            }
            chdir($this->root);
        }

        // Always run initialization when InitCommand is run
        $setupScript = __DIR__ . '/../../../dev/test/setup.sh';

        if (file_exists($setupScript)) {
            if (!defined('ABSPATH')) {
                require_once $wpRootDir . "/wp-load.php";
            }

            @chmod($setupScript, 0700);

            global $wpdb;

            $dbName = str_replace('-', '_', basename(ABSPATH) . '_testdb');

            exec(sprintf(
                '%s %s %s %s %s',
                escapeshellcmd($setupScript),
                escapeshellarg($dbName),
                escapeshellarg($wpdb->dbuser),
                escapeshellarg($wpdb->dbpassword),
                escapeshellarg($wpdb->dbhost)
            ), $outputLines);

            foreach ($outputLines as $line) {
                $output->writeln($line);
            }

            $this->symlinkTestConfigsAndLogs($wpRootDir, $this->root, $output);
        }

        $output->writeln('<info>WordPress test environment initialized successfully.</info>');

        return static::SUCCESS;
    }

    private function extractDbNameFromConfig(string $configPath): ?string
    {
        $dbName = null;
        $file = fopen($configPath, 'r');
        if ($file) {
            while (!feof($file)) {
                $line = fgets($file);
                if (strpos($line, 'DB_NAME') !== false) {
                    if (preg_match("/define\s*\(\s*'DB_NAME'\s*,\s*'([^']+)'\s*\)/", $line, $matches)) {
                        $dbName = $matches[1];
                        break;
                    }
                }
            }
            fclose($file);
        }
        return $dbName;
    }

    private function symlinkTestConfigsAndLogs(string $wpRootDir, string $cwd, OutputInterface $output): void
    {
        $tmpDir = sys_get_temp_dir();

        $testConf = $tmpDir . '/wordpress-tests-lib/wp-tests-config.php';
        $newTestConf = $wpRootDir . '/wp-tests-config.php';

        if (!file_exists($newTestConf)) {
            @chmod($wpRootDir, 0700);
            @touch($newTestConf);
        }

        @unlink($newTestConf);
        @symlink($testConf, $newTestConf);

        $testLog = $tmpDir . '/wordpress/wp-content/debug.log';
        if (!file_exists($testLog)) {
            @touch($testLog);
        }

        $devDir = __DIR__ . '/../..';
        @chmod($devDir, 0700);
        @unlink($devDir . '/test.log');
        @symlink($testLog, $devDir . '/test.log');

        // Append WP_DEBUG_LOG define if not already defined
        $newLine = "defined('WP_DEBUG_LOG') || define('WP_DEBUG_LOG', true);";

        $currentContent = file_get_contents($newTestConf);
        $newContent = $currentContent . "\n" . $newLine;

        // Replace WP_TESTS_DOMAIN if it exists, else add it
        if (preg_match("/define\s*\(\s*'WP_TESTS_DOMAIN'\s*,\s*'([^']+)'\s*\)/", $newContent, $matches)) {
            $currentDomain = $matches[1];
            $config = require(__DIR__ . '/../../test/config.php');
            if ($currentDomain !== $config['site_url']) {
                $newContent = str_replace(
                    "'$currentDomain'",
                    "'" . $config['site_url'] . "'",
                    $newContent
                );
            }
        } else {
            if (strpos($newContent, '?>') !== false) {
                $newContent = str_replace(
                    '?>',
                    "define('WP_TESTS_DOMAIN', 'wpftest.test');\n?>",
                    $newContent
                );
            } else {
                $newContent .= "\ndefine('WP_TESTS_DOMAIN', 'wpftest.test');\n";
            }
        }

        file_put_contents($newTestConf, $newContent);
    }
}
