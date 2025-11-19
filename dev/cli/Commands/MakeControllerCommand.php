<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MakeControllerCommand extends Command
{
    protected static $defaultName = 'make:controller';
    protected static $defaultDescription = 'Creates a controller class inside the app/Http/Controllers folder.';

    protected function configure(): void
    {
        $this
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The path/name of the controller (e.g. Admin/UserController)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $nameArg = $input->getArgument('name');

        // Ensure suffix
        if (!str_ends_with($nameArg, 'Controller')) {
            $nameArg .= 'Controller';
        }

        $composerFile = $this->root . '/composer.json';
        if (!file_exists($composerFile)) {
            $io->error("composer.json not found at {$composerFile}");
            return static::FAILURE;
        }

        $composer = json_decode(file_get_contents($composerFile), true);
        if (!isset($composer['extra']['wpfluent']['namespace']['current'])) {
            $io->error('Namespace configuration not found in composer.json.');
            return static::FAILURE;
        }

        $namespace = $composer['extra']['wpfluent']['namespace']['current'];
        $filePath = $this->root . '/app/Http/Controllers/' . $nameArg . '.php';

        $pieces = explode('/', $nameArg);
        $className = array_pop($pieces);
        $namespaceSuffix = implode('\\', $pieces);
        $fqn = rtrim($namespace . '\\App\\Http\\Controllers\\' . trim($namespaceSuffix, '\\'), '\\');

        $content = <<<PHP
<?php

namespace {$fqn};

use {$namespace}\Framework\Request\Request;

class {$className} extends Controller
{
    public function get(Request \$request)
    {
        // Your code goes here...
    }

    public function store(Request \$request)
    {
        // Your code goes here...
    }

    public function update(Request \$request, \$id)
    {
        // Your code goes here...
    }

    public function delete(Request \$request, \$id)
    {
        // Your code goes here...
    }
}

PHP;

        $dirPath = dirname($filePath);
        if (!is_dir($dirPath) && !@mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
            $io->error("Failed to create directory: {$dirPath}");
            return static::FAILURE;
        }

        if (file_put_contents($filePath, $content) !== false) {
            $relativePath = substr(
                $filePath,
                strpos($filePath, ltrim(substr($this->root, strrpos($this->root, '/')), '/'))
            );
            $io->writeln("<info>Controller {$relativePath} created successfully.</info>");
            return static::SUCCESS;
        }

        $io->error("Failed to create controller file at {$filePath}");
        return static::FAILURE;
    }
}
