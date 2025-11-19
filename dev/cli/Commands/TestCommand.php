<?php

namespace Dev\Cli\Commands;

use Exception;
use PHPUnit\TextUI\Command as PHPUnitCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommand extends BaseCommand
{
    protected static $defaultName = 'test:run';

    protected function configure()
    {
        $this->setDescription('Runs the tests using PHPUnit with WordPress test suite integration.')
            ->setAliases(['test'])
            ->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tmpDir = sys_get_temp_dir();

        $this->registerShutdownHandler($output);

        $this->maybeReplaceNamespace($this->root);

        try {
            $this->initializeTests($tmpDir, $this->root, $input, $output);
            return static::SUCCESS;
        } catch (Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return static::FAILURE;
        }
    }

    private function initializeTests($tmpDir, $cwd, $input, $output)
    {
        $funcPath = $tmpDir . '/wordpress-tests-lib/includes/functions.php';
        
        if (!file_exists($funcPath)) {
            $output->writeln(
                "<info>\nNeed to install the test suite, please wait...</info>"
            );
            require_once realpath($cwd . "/../../../wp-load.php");
            $this->deleteTestSuites($tmpDir);
            $this->runInitCommand($input, $output);
            die('Run ' . "\033[1;33m" . './wpf test' . "\033[0m" . " again." . PHP_EOL);

        }

        $this->activatePluginDuringTest($cwd);

        return $this->runPHPUnitTests($input, $output, $cwd);
    }

    private function deleteTestSuites($tmpDir)
    {
        foreach (['/wordpress', '/wordpress-tests-lib'] as $path) {
            $this->deleteRecursively($tmpDir . $path);
        }
    }

    private function deleteRecursively($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->deleteRecursively($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function runInitCommand($input, $output)
    {
        $app = $this->getApplication();

        if (!$app) {
            throw new Exception('Console application instance not found.');
        }

        $initCommand = $app->find('init');

        $initInput = new ArrayInput([
            'command' => 'init',
        ]);

        $exitCode = $initCommand->run($initInput, $output);

        if ($exitCode !== static::SUCCESS) {
            throw new Exception(
                'Init command failed with exit code ' . $exitCode
            );
        }
    }

    private function registerShutdownHandler($output)
    {
        register_shutdown_function(function () use ($output) {
            $error = error_get_last();
            if ($error !== null) {
                $output->writeln('');
                $output->writeln('<error>Error:</error> ' . print_r($error, true));
                $output->writeln("\nIf this error is related to the test suite, run ./wpf init\n");
            }
        });
    }

    private function maybeReplaceNamespace($cwd)
    {
        // Update __NAMESPACE to real namespace in
        // the tests folder if needed (first time)
        $composer = json_decode(
            file_get_contents($cwd.'/composer.json'), true
        );

        $ns = $composer['extra']['wpfluent']['namespace']['current'];

        $files = glob($cwd . '/dev/factories/*.php');

        $files[] = $cwd . '/dev/test/tests/SampleTest.php';
        
        foreach ($files as $file) {
            if (!file_exists($file)) continue;
            $content = file_get_contents($file);
            $content = str_replace('__NAMESPACE', $ns, $content);
            file_put_contents($file, $content);
        }
    }

    private function activatePluginDuringTest(string $cwd): void
    {
        $plugin = basename($cwd);
        $GLOBALS['wp_tests_options'] = [
            'active_plugins' => [$plugin . '/plugin.php'],
        ];
    }

    private function runPHPUnitTests($input, $output, $cwd)
    {
        chdir($cwd . '/dev');
        $argv = $_SERVER['argv'] ?? [];
        array_shift($argv);
        $argv[0] = str_replace(':run', '', $argv[0]);
        $argv[] = '--exclude';
        $argv[] = 'skip';

        $phpunitCommand = new PHPUnitCommand();

        $result = $phpunitCommand->run(array_merge(['phpunit'], $argv), false);

        if ($result > 2) {
            $this->suggestTestCleanup($output);
            return static::FAILURE;
        }

        return static::SUCCESS;
    }

    private function suggestTestCleanup($output)
    {
        $msg = <<<EOT
If nothing works, try running:

rm -rf "$(echo \$TMPDIR)wordpress" "$(echo \$TMPDIR)wordpress-tests-lib"

./wpf test or ./dev/test/setup.sh

EOT;
        $output->writeln('<comment>' . $msg . '</comment>');
    }
}
