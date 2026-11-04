<?php

namespace FluentCart\App\Hooks\CLI;

use FluentCart\App\Services\Report\RetentionSnapshotService;

class RetentionSnapshotCommand
{
    /**
     * Generate retention snapshots
     */
    public function generate($args, $assoc_args)
    {
        \WP_CLI::line('');
        \WP_CLI::line('===========================================');
        \WP_CLI::line('  Retention Snapshot Generator');
        \WP_CLI::line('===========================================');
        \WP_CLI::line('');

        // Progress callback for CLI output
        $progressCallback = function (string $message, string $level = 'info') {
            if ($level === 'success') {
                \WP_CLI::success($message);
            } elseif ($level === 'error') {
                \WP_CLI::error($message);
            } elseif ($level === 'warning') {
                \WP_CLI::warning($message);
            } else {
                \WP_CLI::line($message);
            }
        };

        // Run the service
        $service = new RetentionSnapshotService();
        $result = $service->generate($progressCallback);

        // Summary
        \WP_CLI::line('');
        \WP_CLI::line('===========================================');
        
        if ($result['success']) {
            \WP_CLI::success('Retention snapshot generation complete!');
        } else {
            \WP_CLI::error($result['message']);
        }
        
        \WP_CLI::line('===========================================');
        \WP_CLI::line('');

        // Show summary stats
        if (!empty($result['stats'])) {
            $stats = $result['stats'];
            \WP_CLI::line('Summary:');
            \WP_CLI::line("  Total Records: {$stats['total_records']}");
            \WP_CLI::line("  Unique Cohorts: {$stats['unique_cohorts']}");
            \WP_CLI::line("  Unique Periods: {$stats['unique_periods']}");
            \WP_CLI::line("  Unique Products: {$stats['unique_products']}");
            \WP_CLI::line('');
        }
    }
}
