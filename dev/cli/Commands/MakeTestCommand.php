<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MakeTestCommand extends Command
{
    protected static $defaultName = 'make:test';
    protected static $defaultDescription = 'Creates a test class inside dev/test/tests folder for writing unit tests.';

    protected function configure(): void
    {
        $this
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The path/name of the test (e.g. Feature/ExampleTest)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $nameArg  = $input->getArgument('name');

        if (!str_ends_with($nameArg, 'Test')) {
            $nameArg .= 'Test';
        }

        $filePath = $this->root . '/dev/test/tests/' . $nameArg . '.php';
        $pieces   = explode('/', $nameArg);
        $className = array_pop($pieces);
        $namespaceSuffix = implode('\\', $pieces);
        $fqn = 'Dev\Test\Tests' . ($namespaceSuffix ? '\\' . $namespaceSuffix : '');

        $content = <<<PHP
<?php

namespace {$fqn};

use Dev\Test\Inc\TestCase;

class {$className} extends TestCase
{
    public function test()
    {
        \$this->assertTrue(true);
    }
}

PHP;

        $dirPath = dirname($filePath);

        if (!is_dir($dirPath) && !@mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
            $io->error("Failed to create directory: {$dirPath}");
            return Command::FAILURE;
        }

        if (file_put_contents($filePath, $content) !== false) {
            $relativePath = substr(
                $filePath,
                strpos($filePath, ltrim(substr($this->root, strrpos($this->root, '/')), '/'))
            );
            $io->writeln("<info>Test {$relativePath} created successfully.</info>");
            return Command::SUCCESS;
        }

        $io->error("Failed to create test file at {$filePath}");
        return Command::FAILURE;
    }
}
