<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeactivatePluginCommand extends BaseCommand
{
    protected static $defaultName = 'app:deactivate';

    protected static $defaultDescription = 'Deactivates the plugin.';

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

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_plugin_active($file)) {
            $output->writeln(
                "<info>Plugin <fg=yellow>{$this->config['name']}</> is already inactive.</info>"
            );

            return static::SUCCESS;
        }

        deactivate_plugins($file);

        if (is_plugin_active($this->config['name'])) {
            $output->writeln('<fg=red>Failed to deactivate plugin.</>');
            return static::FAILURE;
        }

        $output->writeln(
            "<info>Plugin <fg=yellow>{$this->config['name']}</> deactivated successfully.</info>"
        );

        return static::SUCCESS;
    }
}
