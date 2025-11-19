<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateSeedCommand extends BaseCommand
{
    protected static $defaultName = 'db:seed';
    protected static $defaultDescription = 'Seed the database after migrations.';

    protected function configure(): void
    {
        $this->setHelp('Seeds the database with initial or test data.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->maybeLoadWordPress();

        $io = new SymfonyStyle($input, $output);

        $seedFile = $this->root . '/dev/seeders/index.php';

        if (!file_exists($seedFile)) {
            $io->error("Seed file not found at $seedFile");
            return static::FAILURE;
        }

        require $seedFile;

        $io->writeln('<info>The database has been seeded successfully.</info>');

        return static::SUCCESS;
    }
}
