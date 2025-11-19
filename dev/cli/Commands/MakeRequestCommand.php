<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MakeRequestCommand extends BaseCommand
{
    protected static $defaultName = 'make:request';

    protected function configure(): void
    {
        $this
            ->setDescription('Creates a request class inside the app/Http/Requests folder.')
            ->addArgument('name', InputArgument::REQUIRED, 'The path/name of the request class (e.g. Auth/LoginRequest)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $requestName = $input->getArgument('name');

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

        $file = $this->root . '/app/Http/Requests/' . $requestName . '.php';

        $pieces = explode('/', $requestName);
        $className = array_pop($pieces);
        $subNamespace = implode('\\', $pieces);
        $fullNamespace = rtrim($namespace . '\\App\\Http\\Requests\\' . ltrim($subNamespace, '\\'), '\\');

        $content = <<<PHP
<?php

namespace {$fullNamespace};

use {$namespace}\\Framework\\Validator\\Rule;
use {$namespace}\\Framework\\Foundation\\RequestGuard;

class {$className} extends RequestGuard
{
    /**
     * Register your custom rules
     */
    public function __construct()
    {
        // Rule::add(CustomRule::class);
    }

    /**
     * Authorize the request
     * 
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [];
    }

    /**
     * @return array
     */
    public function beforeValidation()
    {
        \$data = \$this->all();
        
        // Modify the \$data

        return \$data;
    }

    /**
     * @return array
     */
    public function afterValidation(\$validator)
    {
        \$data = \$this->all();
        
        // Modify the \$data

        return \$data;
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
            $io->error("Failed to write request file: {$file}");
            return static::FAILURE;
        }

        $relativePath = substr($file, strpos($file, ltrim(substr($this->root, strrpos($this->root, '/')), '/')));
        $io->writeln("<info>Request {$relativePath} created successfully.</info>");

        return static::SUCCESS;
    }
}
