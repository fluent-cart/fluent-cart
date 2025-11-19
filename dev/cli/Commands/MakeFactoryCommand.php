<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeFactoryCommand extends BaseCommand
{
    protected static $defaultName = 'make:factory';

    protected static $defaultDescription = 'Creates a new model factory class inside dev/factories folder.';

    protected function configure()
    {
        $this
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The factory class name (e.g. UserFactory or Blog/PostFactory)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nameArg   = $input->getArgument('name');

        $filePath  = $this->root . '/dev/factories/' . str_replace('\\', '/', $nameArg) . '.php';

        // Namespace & class name
        $pieces = explode('/', str_replace('\\', '/', $nameArg));
        $className = array_pop($pieces);
        $subNamespace = implode('\\', $pieces);
        $namespace = rtrim('Dev\Factories\\' . ltrim($subNamespace, '\\'), '\\');

        $template = <<<PHP
<?php

namespace {$namespace};

use Dev\Factories\Core\Factory;

class {$className} extends Factory
{
    // Required to use Factory::create method
    // protected static \$model = ModelName::class;

    /**
     * @see https://fakerphp.github.io/formatters/
     */
    public function defination()
    {
        return [
            'name' => \$this->fake->name(),
            'email' => \$this->fake->email(),
            'phone' => \$this->fake->phoneNumber(),
        ];
    }
}

PHP;

        // Ensure directory exists
        $dirPath = dirname($filePath);
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0777, true);
        }

        // Create file
        if (file_exists($filePath)) {
            $output->writeln("<error>Factory already exists: {$filePath}</error>");
            return static::FAILURE;
        }

        if (file_put_contents($filePath, $template) !== false) {
            $mainPath = substr(
                $filePath,
                strpos($filePath, ltrim(substr($this->root, strrpos($this->root, '/')), '/'))
            );
            $output->writeln("<info>Factory {$mainPath} created successfully.</info>");
            return static::SUCCESS;
        }

        $output->writeln("<error>Failed to create factory.</error>");
        return static::FAILURE;
    }
}
