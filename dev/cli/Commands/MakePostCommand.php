<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MakePostCommand extends BaseCommand
{
    protected static $defaultName = 'make:post';

    protected function configure(): void
    {
        $this
            ->setDescription('Creates a custom post class to register CPT inside the app/CPT folder.')
            ->addArgument('name', InputArgument::REQUIRED, 'The path/name of the custom post class (e.g. Blog/Post)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $postName = $input->getArgument('name');

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

        $file = $this->root . '/app/CPT/' . $postName . '.php';

        $pieces = explode('/', $postName);
        $className = array_pop($pieces);
        $subNamespace = implode('\\', $pieces);
        $fullNamespace = rtrim($namespace . '\\App\\CPT\\' . ltrim($subNamespace, '\\'), '\\');

        $postTypeSlug = strtolower($className);

        $appConfigFile = $this->root . '/config/app.php';
        if (!file_exists($appConfigFile)) {
            $io->error('Config file app.php not found in config directory.');
            return static::FAILURE;
        }
        $appConfig = require $appConfigFile;
        $textDomain = $appConfig['text_domain'] ?? 'default-text-domain';

        $content = <<<PHP
<?php

namespace {$fullNamespace};

class {$className}
{
    public function registerPostType()
    {
        \$slug = '{$postTypeSlug}';

        \$labels = [
            'name'          => __('{$className}', '{$textDomain}'),
            'singular_name' => __('{$className}', '{$textDomain}'),
        ];

        register_post_type(\$slug, [
            'labels'                => \$labels,
            'public'                => true,
            'show_in_rest'          => true,
            'show_ui'               => false,
            'show_in_nav_menus'     => false,
            'description'           => 'Custom post type used in Foo plugin.',
        ]);

        \$this->registerTaxonomies(\$slug);
    }

    protected function registerTaxonomies(\$slug)
    {
        // ...
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
            $io->error("Failed to write post file: {$file}");
            return static::FAILURE;
        }

        $relativePath = substr($file, strpos($file, ltrim(substr($this->root, strrpos($this->root, '/')), '/')));
        $io->writeln("<info>Post {$relativePath} created successfully.</info>");

        return static::SUCCESS;
    }
}
