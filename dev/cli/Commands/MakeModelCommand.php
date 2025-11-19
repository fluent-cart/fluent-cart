<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeModelCommand extends BaseCommand
{
    protected static $defaultName = 'make:model';

    protected function configure(): void
    {
        $this
            ->setDescription('Creates an ORM model class inside the app/Models folder.')
            ->addArgument('name', InputArgument::REQUIRED, 'Path/name of the model (e.g., User/Profile)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modelName = $input->getArgument('name');

        $composerFile = $this->root . '/composer.json';
        if (!file_exists($composerFile)) {
            $output->writeln('<error>composer.json not found in plugin directory.</error>');
            return static::FAILURE;
        }

        $composer = json_decode(file_get_contents($composerFile), true);
        if (!isset($composer['extra']['wpfluent']['namespace']['current'])) {
            $output->writeln('<error>Namespace not found in composer.json.</error>');
            return static::FAILURE;
        }
        $namespace = $composer['extra']['wpfluent']['namespace']['current'];

        $file = $this->root . '/app/Models/' . $modelName . '.php';

        $pieces = explode('/', $modelName);
        $className = array_pop($pieces);
        $subNamespace = implode('\\', $pieces);
        $fullNamespace = rtrim($namespace . '\\App\\Models\\' . ltrim($subNamespace, '\\'), '\\');

        $content = <<<PHP
<?php

namespace {$fullNamespace};

use {$namespace}\App\Models\Model;

class {$className} extends Model
{
    // If the table name is not given explicitly then the plural form of
    // your model name in lower case will be used for the table name.

    // protected \$table = 'database_table_name_without_prefix';
}

PHP;

        $dirPath = dirname($file);
        if (!is_dir($dirPath)) {
            if (!mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
                $output->writeln('<error>Failed to create directories: ' . $dirPath . '</error>');
                return static::FAILURE;
            }
        }

        if (file_put_contents($file, $content) === false) {
            $output->writeln('<error>Failed to write model file: ' . $file . '</error>');
            return static::FAILURE;
        }

        // Compute relative path for success message
        $relativePath = substr($file, strpos($file, ltrim(substr($this->root, strrpos($this->root, '/')), '/')));
        $output->writeln('<info>Model ' . $relativePath . ' created successfully.</info>');

        return static::SUCCESS;
    }
}
