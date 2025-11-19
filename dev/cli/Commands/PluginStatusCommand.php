<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PluginStatusCommand extends BaseCommand
{
    protected static $defaultName = 'app:status';

    protected static $defaultDescription = 'Check the plugin status, whether it is active or not.';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->maybeLoadWordPress();

        $plugin = $this->root . '/plugin.php';

        $parts = explode('/', $plugin);
        $pluginPhp = array_pop($parts);
        $pluginDirPath = array_pop($parts);
        $file = implode('/', [$pluginDirPath, $pluginPhp]);

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active($file)) {
            $output->writeln(
                "<info>Plugin <fg=yellow>{$this->config['name']}</> is currently active.</info>"
            );
        } else {
            $output->writeln("<info>Plugin <fg=yellow>{$this->config['name']}</> is not active.</info>");
        }

        return static::SUCCESS;
    }
}
