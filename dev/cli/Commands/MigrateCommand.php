<?php

namespace Dev\Cli\Commands;

use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateCommand extends BaseCommand
{
    protected static $defaultName = 'migrate:up';

    protected static $defaultDescription = 'Run database migrations for your plugin\'s custom tables.';

    protected function configure(): void
    {
        $this
            ->setAliases(['migrate'])
            ->setHelp('Run database migrations for your plugin\'s custom tables.')
            ->addOption(
                'seed',
                null,
                InputOption::VALUE_NONE,
                'Seed the database after migration'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->maybeLoadWordPress();

        $io = new SymfonyStyle($input, $output);

        try {
            $composerJson = file_get_contents($this->root . '/composer.json');
            $composer = json_decode($composerJson, true);

            if (!isset($composer['extra']['wpfluent']['namespace']['current'])) {
                $io->error('Namespace configuration not found in composer.json.');
                return static::FAILURE;
            }

            $ns = $composer['extra']['wpfluent']['namespace']['current'];
            $dbMigratorClass = $ns . '\\Database\\DBMigrator';

            if (!class_exists($dbMigratorClass)) {
                $io->error("DBMigrator class $dbMigratorClass not found.");
                return static::FAILURE;
            }

            $result = $dbMigratorClass::run();

            if ($result) {
                foreach ($result as $message) {
                    $io->writeln("<comment>$message.</comment>");
                }

                $io->writeln('<info>The migration ran successfully.</info>');
            } else {
                $io->writeln('Migration completed with no changes.');
            }

            // Run seed if --seed option passed
            if ($input->getOption('seed')) {
                require $this->root . '/dev/seeds/index.php';
                $io->writeln(
                    '<info>The database has been seeded successfully.</info>'
                );
            }

            return static::SUCCESS;
        } catch (Exception $e) {
            $io->writeln(
                '<fg=red>Migration failed: ' . $e->getMessage() . '</>'
            );
            return static::FAILURE;
        }
    }
}
