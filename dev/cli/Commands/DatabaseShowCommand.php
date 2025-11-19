<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

class DatabaseShowCommand extends BaseCommand
{
    protected static $defaultName = 'db:show';

    protected function configure()
    {
        $this
            ->setDescription('Show detailed information about the current WordPress database.')
            ->setHelp('Displays WordPress DB config, MySQL info, and table stats in a dotted-line format.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->maybeLoadWordPress();

        global $wpdb;

        if (!isset($wpdb)) {
            $output->writeln(
                '<error>Could not load WordPress $wpdb object.</error>'
            );

            return static::FAILURE;
        }

        $dbName     = DB_NAME ?? '';
        $dbUser     = DB_USER ?? '';
        $dbHost     = DB_HOST ?? '';
        $dbCharset  = DB_CHARSET ?? '';
        $dbCollate  = DB_COLLATE ?: 'Default';
        $mysqlVersion = $wpdb->get_var("SELECT VERSION()");
        $connectionType = $wpdb->dbdriver ?? 'mysql';

        $tables = $wpdb->get_results("SHOW TABLE STATUS", ARRAY_A);

        foreach ($tables as $index => $table) {
            $tableName = $table['Name'];
            $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$tableName}`");
            $tables[$index]['Rows'] = $count;
        }

        $tableCount = count($tables);
        $totalSize  = 0;
        $row = $wpdb->get_row("SHOW STATUS LIKE 'Threads_connected'", ARRAY_A);
        $openConnections = $row ? (int) $row['Value'] : 1;


        foreach ($tables as $table) {
            $totalSize += ($table['Data_length'] + $table['Index_length']);
        }

        $terminal = new Terminal();
        $terminalWidth = $terminal->getWidth();

        $printLine = function ($label, $value) use ($output, $terminalWidth) {
            $label = ucfirst($label);
            $dotCount = max(0, $terminalWidth - strlen($label) - strlen($value) - 2);
            $dots = str_repeat('.', $dotCount);
            $output->writeln(sprintf(
                '<info>%s</info> <fg=gray>%s</> <fg=white>%s</>',
                $label,
                $dots,
                $value
            ));
        };

        $dbHostParts = explode(':', DB_HOST ?? '');
        $host = $dbHostParts[0] ?? 'localhost';
        $port = $dbHostParts[1] ?? '3306';

        $printLine('MySQL', $mysqlVersion);
        $printLine('Connection', $connectionType);
        $printLine('Database', $dbName);
        $printLine('Host', $host);
        $printLine('Port', $port);
        $printLine('Username', $dbUser);
        $printLine('Open Connections', (string)$openConnections);
        $printLine('Tables', (string)$tableCount);
        $printLine('Total Size', size_format($totalSize));

        $output->writeln('');

        // Header
        $headerLeft = 'Table (Records)';
        $headerRight = 'Size';
        $dotCount = max(
            0, $terminalWidth - strlen($headerLeft) - strlen($headerRight) - 2
        );
        $dots = str_repeat('.', $dotCount);

        $output->writeln(sprintf(
            '<info>%s</info> <fg=gray>%s</> <info>%s</info>',
            $headerLeft,
            $dots,
            $headerRight
        ));

        // Compute max lengths for proper alignment
        $maxNameLength = 0;
        foreach ($tables as $table) {
            
            $tableLabel = sprintf(
                '%s (%s)',
                $table['Name'],
                number_format($table['Rows'])
            );

            $maxNameLength = max($maxNameLength, strlen($tableLabel));
        }

        // Print table info
        foreach ($tables as $table) {
            $tableLabel = sprintf(
                '%s (%s)', $table['Name'], number_format($table['Rows'])
            );
            $size = size_format(
                $table['Data_length'] + $table['Index_length']
            );

            // Pad label
            $tableLabelPadded = str_pad(
                $tableLabel, $maxNameLength, ' ', STR_PAD_RIGHT
            );

            // Compute dots
            $dotCount = max(
                2, $terminalWidth - $maxNameLength - strlen($size) - 2
            );

            $dots = str_repeat('.', $dotCount);

            $output->writeln(sprintf(
                '<fg=white>%s</> <fg=gray>%s</> <fg=white>%s</>',
                $tableLabelPadded,
                $dots,
                $size
            ));
        }

        return static::SUCCESS;
    }
}
