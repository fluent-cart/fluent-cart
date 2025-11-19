<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MakeSeederCommand extends BaseCommand
{
    protected static $defaultName = 'make:seeder';
    protected static $defaultDescription = 'Generates a simple PHP seeder file';

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the seeder file (without .php)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seederName = $input->getArgument('name');

        // Seeder folder
        $seedersDir = $this->root . '/dev/seeders';
        if (!is_dir($seedersDir)) {
            mkdir($seedersDir, 0777, true);
        }

        $filePath = $seedersDir . '/' . $seederName . '.php';

        $content = $this->getSeederTemplate($seederName);

        file_put_contents($filePath, $content);

        $relativePath = basename($this->root) . DIRECTORY_SEPARATOR . str_replace(
            $this->root . '/', '', realpath($filePath)
        );

        $io->writeln("<info>Seeder file created: {$relativePath}</info>");

        return static::SUCCESS;
    }

    /**
     * Returns a simple seeder template
     */
    protected function getSeederTemplate(string $seederName): string
    {
        return <<<PHP
<?php

/**
 * This is an example for seeding. In the given code below, the UserFactory is
 * reseting the primary key starting from 1, creating 5 users and looping
 * each user from the collection and creating 5 posts for each user
 * using the hasMany relation defined in the User model.
 */


// use Dev\Factories\{
//  UserFactory,
//  PostFactory
// };

// UserFactory::resetPrimaryKey()->count(5)->create()->each(function($user) {
//  \$user->posts()->createMany(
//      PostFactory::resetPrimaryKey()->count(5)->make()
//  );
// });

PHP;
    }
}
