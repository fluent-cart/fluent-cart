<?php

namespace Dev\Cli\Commands;

use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateDownCommand extends BaseCommand
{
    protected static $defaultName = 'migrate:down';
    protected static $defaultDescription = 'Drops the database tables migrated by the plugin.';

    protected function configure(): void
    {
        $this->setHelp('Rollback the last batch of database migrations.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $io = new SymfonyStyle($input, $output);

        // Ask for confirmation before proceeding
        if (!$io->confirm('Are you sure you want to drop all tables migrated by the plugin? This action cannot be undone.', false)) {
            $io->writeln('<comment>Operation cancelled.</comment>');
            return static::SUCCESS;
        }

        $this->maybeLoadWordPress();

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

            if (!method_exists($dbMigratorClass, 'migrateDown')) {
                $io->error("Method down() not implemented in $dbMigratorClass.");
                return static::FAILURE;
            }

            if ($migrations = $dbMigratorClass::getMigrations()) {
                $db = $GLOBALS['wpdb'];
                $result = [];
                foreach (array_keys($migrations) as $table) {
                    if ($db->query("DROP TABLE IF EXISTS {$db->prefix}{$table}")) {
                        $result[] = $io->writeln("<comment>Dropped table {$db->prefix}{$table}.</comment>");
                    }
                }
            }

            if ($result) {
                $io->writeln('<info>The tables migrated by plugin have been dropped successfully.</info>');
            } else {
                $io->writeln('<info>No migrations to drop.</info>');
            }

            return static::SUCCESS;
        } catch (Exception $e) {
            $io->error('Down migration failed: ' . $e->getMessage());
            return static::FAILURE;
        }
    }
}
