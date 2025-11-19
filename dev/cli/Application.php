<?php

namespace Dev\Cli;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;

class Application
{
    private $root = null;

    private $commands = [
        Commands\AboutCommand::class,
        Commands\BuildCommand::class,
        Commands\PluginStatusCommand::class,
        Commands\ActivatePluginCommand::class,
        Commands\DeactivatePluginCommand::class,
        Commands\InitCommand::class,
        Commands\MigrateCommand::class,
        Commands\MigrateDownCommand::class,
        Commands\MigrateRefreshCommand::class,
        Commands\MigrateSeedCommand::class,
        Commands\RoutesCommand::class,
        Commands\TestCommand::class,
        Commands\MakeTestCommand::class,
        Commands\MakeControllerCommand::class,
        Commands\MakeFactoryCommand::class,
        Commands\MakeHandlerCommand::class,
        Commands\MakeMiddlewareCommand::class,
        Commands\MakeModelCommand::class,
        Commands\MakePolicyCommand::class,
        Commands\MakePostCommand::class,
        Commands\MakeRequestCommand::class,
        Commands\MakeRuleCommand::class,
        Commands\MakeMigrationCommand::class,
        Commands\MakeSeederCommand::class,
        Commands\DatabaseShowCommand::class,
        Commands\DatabaseTableCommand::class,
        Commands\FixStaticCommand::class,
    ];

    public function __construct($root = null)
    {
        $this->root = $root;
    }

    public static function boot($root = null)
    {
        return (new static($root))->run();
    }

    protected function run()
    {
        $app = new SymfonyApplication('WPFluent CLI', '2.0.0');

        $app->setCommandLoader(new FactoryCommandLoader($this->getFactories()));

        $app->run();
    }

    public function getFactories()
    {
        $factories = [];

        foreach ($this->commands as $class) {
            $instance = new $class;

            $factories[$instance->getName()] = fn() => new $class;

            foreach ($instance->getAliases() as $alias) {
                $factories[$alias] = fn() => new $class;
            }
        }

        return $factories;
    }
}
