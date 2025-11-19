<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeHandlerCommand extends BaseCommand
{
    protected static $defaultName = 'make:handler';
    protected static $defaultDescription = 'Creates a handler class inside the app/Hooks/Handlers folder.';

    protected function configure()
    {
        $this
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The path/name of the handler (e.g., "User/RegisterHandler").'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $handlerPath = str_replace('\\', '/', $input->getArgument('name'));

        $composerFile = $this->root . '/composer.json';

        if (!file_exists($composerFile)) {
            $output->writeln(
                '<error>composer.json not found in plugin root.</error>'
            );
            return static::FAILURE;
        }

        $composer = json_decode(file_get_contents($composerFile), true);
        
        if (empty($composer['extra']['wpfluent']['namespace']['current'])) {
            $output->writeln('<error>Plugin namespace not found in composer.json.</error>');
            return static::FAILURE;
        }

        $namespace = $composer['extra']['wpfluent']['namespace']['current'];
        $file = $this->root . '/app/Hooks/Handlers/' . $handlerPath . '.php';
        $pieces = explode('/', $handlerPath);
        $name = array_pop($pieces);
        $subNamespace = implode('\\', $pieces);
        $fqn = rtrim($namespace . '\App\Hooks\Handlers\\' . ltrim($subNamespace, '\\'), '\\');

        $content = <<<PHP
        <?php

        namespace {$fqn};

        use {$namespace}\Framework\Foundation\Application;

        class {$name}
        {
            protected \$app;

            public function __construct(Application \$app)
            {
                \$this->app = \$app;
            }

            public function handle()
            {
                // ...
            }
        }

        PHP;

        $dirPath = dirname($file);

        if (!is_dir($dirPath) && !mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
            $output->writeln('<error>Failed to create directory: ' . $dirPath . '</error>');
            return static::FAILURE;
        }

        if (file_put_contents($file, $content) === false) {
            $output->writeln('<error>Failed to write file: ' . $file . '</error>');
            return static::FAILURE;
        }

        $relativePath = substr($file, strlen($this->root) + 1);
        $output->writeln('<info>Handler ' . $relativePath . ' created successfully.</info>');

        return static::SUCCESS;
    }
}
