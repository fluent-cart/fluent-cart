<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ActivatePluginCommand extends BaseCommand
{
    protected static $defaultName = 'app:activate';

    protected static $defaultDescription = 'Activates the plugin.';

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->maybeLoadWordPress();

        $plugin = $this->root .'/plugin.php';

        if (!file_exists($plugin)) {
            $ds = DIRECTORY_SEPARATOR;
            $plugin = $this->root . $ds . basename($this->root) . '.php';
        }

        if (!file_exists($plugin)) {
            $basename = basename($plugin);
            $output->writeln(
                "<fg=red>The Plugin must contain either a plugin.php or {$basename} file in the root directory.</>"
            );
            return static::FAILURE;
        }

        $parts = explode('/', $plugin);

        $pluginPhp = array_pop($parts);

        $pluginDirPath = array_pop($parts);

        $file = implode('/', [$pluginDirPath, $pluginPhp]);

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active($file)) {
            $output->writeln(
                "<info>Plugin <fg=yellow>{$this->config['name']}</> is already active.</info>"
            );

            return static::SUCCESS;
        }

        $result = activate_plugin($file);

        if (is_wp_error($result)) {
            $output->writeln('<fg=red>' . $result->get_error_message() . '</>');
            return static::FAILURE;
        }

        $output->writeln(
            "<info>Plugin <fg=yellow>{$this->config['name']}</> activated successfully.</info>"
        );

        return static::SUCCESS;
    }
}
