<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MakeRuleCommand extends BaseCommand
{
    protected static $defaultName = 'make:rule';

    protected function configure(): void
    {
        $this
            ->setDescription('Creates a custom validation rule class inside the app/Http/Rules folder.')
            ->addArgument('name', InputArgument::REQUIRED, 'The path/name of the rule class (e.g. Password/IsValidPassword)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ruleName = $input->getArgument('name');

        $composerFile = $this->root . '/composer.json';
        if (!file_exists($composerFile)) {
            $io->error('composer.json not found in plugin directory.');
            return static::FAILURE;
        }

        $composer = json_decode(file_get_contents($composerFile), true);
        if (!isset($composer['extra']['wpfluent']['namespace']['current'])) {
            $io->error('Namespace not found in composer.json.');
            return static::FAILURE;
        }

        $namespace = $composer['extra']['wpfluent']['namespace']['current'];

        $file = $this->root . '/app/Http/Rules/' . $ruleName . '.php';

        $pieces = explode('/', $ruleName);
        $className = array_pop($pieces);
        $subNamespace = implode('\\', $pieces);
        $fullNamespace = rtrim($namespace . '\\App\\Http\\Rules\\' . ltrim($subNamespace, '\\'), '\\');

        $content = <<<PHP
<?php

namespace {$fullNamespace};

class {$className}
{
    public function __invoke(\$attr, \$value, \$rules, \$data, ...\$params)
    {
        // \$params = ['param1', 'param2'] (Passed from method call)
        // i.e: Rule::isValidPassword('param1', 'param2')

        if (!true) {
            return "The {\$attr} field must contain special characters.";
        }
    }
}

PHP;

        $dirPath = dirname($file);
        if (!is_dir($dirPath)) {
            if (!mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
                $io->error("Failed to create directory: {$dirPath}");
                return static::FAILURE;
            }
        }

        if (file_put_contents($file, $content) === false) {
            $io->error("Failed to write rule file: {$file}");
            return static::FAILURE;
        }

        $relativePath = substr($file, strpos($file, ltrim(substr($this->root, strrpos($this->root, '/')), '/')));
        $io->writeln("<info>Rule {$relativePath} created successfully.</info>");

        return static::SUCCESS;
    }
}
