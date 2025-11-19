<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeMiddlewareCommand extends BaseCommand
{
    protected static $defaultName = 'make:middleware';

    protected function configure(): void
    {
        $this
            ->setDescription('Creates a middleware class inside the app/Http/Middleware folder.')
            ->addArgument('name', InputArgument::REQUIRED, 'Path/name of the middleware (e.g., Admin/AuthMiddleware)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $middlewareName = $input->getArgument('name');

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

        $file = $this->root . '/app/Http/Middleware/' . $middlewareName . '.php';

        $pieces = explode('/', $middlewareName);
        $className = array_pop($pieces);
        $subNamespace = implode('\\', $pieces);
        $fullNamespace = rtrim($namespace . '\\App\\Http\\Middleware\\' . ltrim($subNamespace, '\\'), '\\');

        $content = <<<PHP
<?php

namespace {$fullNamespace};

class {$className}
{
    /**
     * Handle the request.
     *
     * Note: For a before middleware the \$r will contain the request instance and
     * for the after middleware, the Response will be available in the \$r variable.
     * 
     * @param  {$namespace}\\Framework\\Request\\Request|\${$namespace}\\Framework\\Response\\Response \$r
     * @param  \\Closure \$next
     * @param  array \$params
     * @return mixed
     */
    public function handle(\$r, \\Closure \$next, ...\$params)
    {
        if (isset(\$params[0]) && \$params[0] === 'something_matches') {
            return \$next(\$r);
        }

        // return false or nothing for null, the Rest API will handle the response as
        // rest_forbidden (status:403) or call \$r->abort to send a custom response.
        return \$r->abort(/* int code, string message */);
    }
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
            $output->writeln('<error>Failed to write middleware file: ' . $file . '</error>');
            return static::FAILURE;
        }

        // Compute relative path for success message
        $relativePath = substr($file, strpos($file, ltrim(substr($this->root, strrpos($this->root, '/')), '/')));
        $output->writeln('<info>Middleware ' . $relativePath . ' created successfully.</info>');

        return static::SUCCESS;
    }
}
