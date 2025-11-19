<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateRefreshCommand extends BaseCommand
{
    protected static $defaultName = 'migrate:refresh';
    protected static $defaultDescription = 'Refresh all tables by dropping and re-running migrations.';

    protected function configure(): void
    {
        $this
            ->setHelp('Deletes all tables and reruns migrations. Optionally seeds the database with --seed.')
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

        $composerPath = $this->root . '/composer.json';
        
        if (!file_exists($composerPath)) {
            $io->error("composer.json not found at $composerPath");
            return static::FAILURE;
        }

        $composer = json_decode(file_get_contents($composerPath), true);

        if (!isset($composer['extra']['wpfluent']['namespace']['current'])) {
            $io->error("Namespace info missing in composer.json 'extra.wpfluent.namespace.current'");
            return static::FAILURE;
        }

        $ns = $composer['extra']['wpfluent']['namespace']['current'];
        
        $dbMigratorClass = $ns . '\\Database\\DBMigrator';

        if ($migrations = $dbMigratorClass::getMigrations()) {
            $db = $GLOBALS['wpdb'];

            foreach (array_keys($migrations) as $table) {
                if ($db->query("DROP TABLE IF EXISTS {$db->prefix}{$table}")) {
                    $io->writeln("<comment>Dropped table {$db->prefix}{$table}.</comment>");
                }
            }

            if ($result = $dbMigratorClass::run()) {
                foreach ($result as $value) {
                    $io->writeln("<comment>$value.</comment>");
                }
                $io->writeln('<info>The migration ran successfully.</info>');
            } else {
                $io->writeln('<info>There was nothing to migrate.</info>');
            }

            if ($input->getOption('seed')) {
                require $this->root . '/dev/seeds/index.php';
                $io->writeln(
                    '<info>The database has been seeded successfully.</info>'
                );
            }
        } else {
            $io->writeln('<info>No migrations found.</info>');
        }

        return static::SUCCESS;
    }
}
