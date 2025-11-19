<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

class DatabaseTableCommand extends BaseCommand
{
    protected static $defaultName = 'db:table';

    protected function configure()
    {
        $this
            ->setDescription('Show detailed information about a WordPress database table.')
            ->addArgument('table', InputArgument::REQUIRED, 'The table name to inspect.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        global $wpdb;

        $this->maybeLoadWordPress();

        $tableName = $input->getArgument('table');

        $fullTableName = $wpdb->prefix . $tableName;

        // Check table exists
        if (!$wpdb->get_results("SHOW TABLES LIKE '{$fullTableName}'")) {
            $output->writeln(
                "<error>Table '{$fullTableName}' not found.</error>"
            );
            return static::FAILURE;
        }

        // Table status
        $status = $wpdb->get_row(
            "SHOW TABLE STATUS LIKE '{$fullTableName}'", ARRAY_A
        );

        if (!$status) {
            $output->writeln(
                "<error>Unable to fetch status for '{$fullTableName}'.</error>"
            );

            return static::FAILURE;
        }

        $terminal = new Terminal();
        $terminalWidth = $terminal->getWidth();

        // Strip tags for visible length
        $visibleLength = fn($str) => mb_strlen(preg_replace('/<[^>]+>/', '', $str));

        // Helper to print full-width dotted lines
        $printLine = function($label, $value) use (
            $output, $terminalWidth, $visibleLength
        ) {
            $dotCount = max(2, $terminalWidth - $visibleLength($label) - $visibleLength($value) - 0);
            
            $dots = str_repeat('.', $dotCount);
            
            $output->writeln(
                sprintf(
                    '<info>%s</info><fg=gray>%s</><fg=white>%s</>',
                    $label, $dots, $value
                )
            );
        };

        $columns = $wpdb->get_results(
            "SHOW FULL COLUMNS FROM `{$fullTableName}`", ARRAY_A
        );

        $columnCount = count($columns);

        $rows = $wpdb->get_var("SELECT COUNT(*) FROM `{$fullTableName}`");

        // Table summary
        $printLine($fullTableName, '');
        $printLine('Columns', $columnCount ?? 'N/A');
        $printLine('Records', $rows ?? 'N/A');
        $printLine(
            'Size', size_format(
                $status['Data_length'] + $status['Index_length']
            )
        );
        $printLine('Engine', $status['Engine'] ?? 'N/A');
        $printLine('Collation', "{$status['Collation']}");
        
        $printLine(
            'Table Created At',
            date('d-m-Y h:m', strtotime($status['Create_time'])) ?? 'N/A'
        );

        if (isset($status['Auto_increment'])) {
            $printLine('Next Auto Increment', $status['Auto_increment']);
        }

        $output->writeln('');

        // Columns
        $columns = $wpdb->get_results(
            "SHOW FULL COLUMNS FROM `{$fullTableName}`", ARRAY_A
        );

        if ($columns) {
            $dots = str_repeat(
                '.', $terminalWidth - strlen('Column') - strlen('Type') - 0
            );

            $output->writeln(
                '<info>Column</info>'
                . '<fg=gray>' . $dots . '</>'
                . '<info>Type</info>'
            );

            foreach ($columns as $col) {
                $label = $col['Field'];

                $nullable = (strtoupper($col['Null'] ?? '') === 'YES') ? ', nullable' : '';
                $collation = !empty($col['Collation']) ? " <fg=gray>{$col['Collation']}</>" : '';

                $type = $col['Type'];

                $dotCount = max(2, $terminalWidth - $visibleLength($label) - $visibleLength($nullable) - $visibleLength($collation) - $visibleLength($type) - 0);
                $dots = str_repeat('.', $dotCount);

                $output->writeln(sprintf(
                    '<fg=white>%s%s</>%s<fg=gray>%s</><fg=white>%s</>',
                    $label,
                    $nullable,
                    $collation,
                    $dots,
                    $type
                ));
            }
        }

        $output->writeln('');

        // Indexes
        $indexes = $wpdb->get_results(
            "SHOW INDEXES FROM `{$fullTableName}`", ARRAY_A
        );

        if ($indexes) {
            // Print header
            $dotCount = max(2, $terminalWidth - strlen('Index') - strlen('Column') - strlen('Type') - 1);
            $dots = str_repeat('.', $dotCount);
            $output->writeln(
                '<info>Index</info> <info>Column</info><fg=gray>' . $dots . '</><info>Type</info>'
            );

            foreach ($indexes as $index) {
                $label = $index['Key_name'];
                $column = $index['Column_name'];
                $label = strtolower($index['Key_name']);

                $type = $index['Index_type'] ?? 'N/A';

                $dotCount = max(
                    2,
                    $terminalWidth
                    - $visibleLength($label)
                    - $visibleLength($column)
                    - $visibleLength($type)
                    - 1
                );

                $dots = str_repeat('.', $dotCount);

                $output->writeln(
                    sprintf(
                        '<fg=white>%s</> <fg=gray>%s</><fg=gray>%s</><fg=white>%s</>',
                        $label,
                        $column,
                        $dots,
                        $type
                    )
                );
            }
        }

        return static::SUCCESS;
    }
}
