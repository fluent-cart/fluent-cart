<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Services\Report\RetentionSnapshotService;

class RetentionSnapshotHandler
{
    /**
     * Register Action Scheduler hooks
     */
    public function register()
    {
        add_action('fluent_cart_generate_retention_snapshots', [$this, 'generateSnapshots'], 10, 2);
    }

    /**
     * Action Scheduler callback to generate retention snapshots
     * 
     * @param array $args ['product_id' => int|null]
     */
    public function generateSnapshots($productId = null, $jobId = null)
    {
        // Update job status to running
        if ($jobId) {
            update_option('fluent_cart_snapshot_job_' . $jobId, [
                'status' => 'running',
                'started_at' => current_time('mysql'),
                'product_id' => $productId,
            ]);
        }

        try {
            // Run the snapshot generation
            $service = new RetentionSnapshotService();
            $result = $service->generate($productId, null);

            // Update job status with results
            if ($jobId) {
                update_option('fluent_cart_snapshot_job_' . $jobId, [
                    'status' => $result['success'] ? 'completed' : 'failed',
                    'started_at' => current_time('mysql'),
                    'completed_at' => current_time('mysql'),
                    'product_id' => $productId,
                    'message' => $result['message'],
                    'stats' => $result['stats'] ?? [],
                ]);
            }

        } catch (\Exception $e) {
            // Update job status with error
            if ($jobId) {
                update_option('fluent_cart_snapshot_job_' . $jobId, [
                    'status' => 'failed',
                    'started_at' => current_time('mysql'),
                    'completed_at' => current_time('mysql'),
                    'product_id' => $productId,
                    'message' => $e->getMessage(),
                    'stats' => [],
                ]);
            }

            throw $e; // Re-throw so Action Scheduler marks it as failed
        }
    }
}
