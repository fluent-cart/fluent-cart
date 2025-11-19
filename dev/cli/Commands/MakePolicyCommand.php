<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MakePolicyCommand extends BaseCommand
{
    protected static $defaultName = 'make:policy';

    protected function configure(): void
    {
        $this
            ->setDescription('Creates a policy class inside the app/Http/Policies folder.')
            ->addArgument('name', InputArgument::REQUIRED, 'Path/name of the policy (e.g., Admin/UserPolicy)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $policyName = $input->getArgument('name');

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

        $file = $this->root . '/app/Http/Policies/' . $policyName . '.php';

        $pieces = explode('/', $policyName);
        $className = array_pop($pieces);
        $subNamespace = implode('\\', $pieces);
        $fullNamespace = rtrim($namespace . '\\App\\Http\\Policies\\' . ltrim($subNamespace, '\\'), '\\');

        $content = <<<PHP
<?php

namespace {$fullNamespace};

use {$namespace}\App\Utils\Auth\Auth;
use {$namespace}\Framework\Request\Request;

class {$className} extends Policy
{
    /**
     * Check user permission for the current method.
     *
     * @param Request \$request
     * @param mixed ...\$args
     * @return bool
     */
    public function create(Request \$request, ...\$args)
    {
        return Auth::check(\$request, 'manage_options', ...\$args);
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
            $io->error("Failed to write policy file: {$file}");
            return static::FAILURE;
        }

        $relativePath = substr($file, strpos($file, ltrim(substr($this->root, strrpos($this->root, '/')), '/')));
        $io->writeln("<info>Policy {$relativePath} created successfully.</info>");

        return static::SUCCESS;
    }
}
