<?php

namespace Dev\Cli\Commands;

use Exception;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RoutesCommand extends BaseCommand
{
    protected static $defaultName = 'routes:list';
    
    protected static $defaultDescription = 'Displays WordPress REST API routes registered by the plugin.';

    protected function configure(): void
    {
        $this
        ->setAliases(['routes'])
        ->setHelp(
            'This command lists REST API routes exposed by the plugin.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->maybeLoadWordPress();

        $slug = $this->config['slug'];

        if (!$slug) {
            $output->writeln('<fg=red>The plugin slug is missing.</>');
            return static::FAILURE;
        }

        $response = wp_remote_get(
            home_url("/wp-json/{$slug}/v2/{$slug}/__endpoints"),
            [
                'headers' => [
                    'X-From-CLI' => md5($slug),
                ]
            ]
        );

        $code = wp_remote_retrieve_response_code($response);

        if ($code == 404) {
            throw new Exception('Make sure that plugin is active.'.PHP_EOL);
        }

        $routes = json_decode(wp_remote_retrieve_body($response), true);

        $this->outputRouteTable($routes, $output);

        return static::SUCCESS;
    }

    private function outputRouteTable(array $routes, OutputInterface $output): void
    {
        $rows = [];
        
        foreach ($routes as $controller => $actions) {
            $controller = str_replace('.', '\\', $controller);
            foreach ($actions as $action => $details) {
                $uri = $details['uri'];
                $methods = implode(', ', $details['methods']);
                $actionFormatted = $controller . '@' . ltrim($action, '_');
                $policy = $details['policy'] ?? 'none';
                $rows[] = [$methods, $uri, $actionFormatted, $policy];
            }
        }

        $table = new Table($output);
        $table->setStyle('box');

        $table
            ->setHeaders(['Method', 'URI', 'Action', 'Policy'])
            ->setRows($rows)
            ->render();
    }
}
