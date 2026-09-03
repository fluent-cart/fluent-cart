<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\Services\Report\RetentionSnapshotService;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Http\Controllers\Controller;

class RetentionSnapshotController extends Controller
{
    /**
     * Generate retention snapshots via Action Scheduler (background job)
     */
    public function generate(Request $request)
    {
        $productId = $request->get('product_id');
        if ($productId) {
            $productId = (int) $productId;
        }

        // Check if Action Scheduler is available
        if (!function_exists('as_enqueue_async_action')) {
            // Fallback: run synchronously if Action Scheduler not available
            $service = new RetentionSnapshotService();
            $result = $service->generate($productId, null);

            return [
                'success' => Arr::get($result, 'success'),
                'message' => Arr::get($result, 'message'),
                'stats'   => Arr::get($result, 'stats', []),
                'mode'    => 'synchronous',
            ];
        }

        // Use timestamp as tracking ID - create it ONCE
        $trackingId = time();

        // Queue the job via Action Scheduler
        // Note: Action Scheduler passes array values as separate arguments to the callback
        as_schedule_single_action(
            time(),
            'fluent_cart_generate_retention_snapshots',
            [$productId, $trackingId], // Will be passed as generateSnapshots($productId, $trackingId)
            'fluent-cart-snapshots'
        );
        
        // Store job start time
        update_option('fluent_cart_snapshot_job_' . $trackingId, [
            'status' => 'pending',
            'started_at' => current_time('mysql'),
            'product_id' => $productId,
        ]);

        return [
            'success' => true,
            'message' => __('Snapshot generation queued', 'fluent-cart'),
            'job_id' => $trackingId,
            'mode' => 'background',
        ];
    }

    /**
     * Check status of a snapshot generation job
     */
    public function checkStatus(Request $request)
    {
        $jobId = $request->get('params.job_id');
        
        if (!$jobId) {
            return [
                'success' => false,
                'message' => __('Job ID required', 'fluent-cart'),
            ];
        }

        $jobData = get_option('fluent_cart_snapshot_job_' . $jobId);

        if (!$jobData) {
            return [
                'success' => false,
                'message' => __('Job not found', 'fluent-cart'),
                'job_id' => $jobId,
            ];
        }

        // If job data shows completed or failed, return that status
        $jobStatus = Arr::get($jobData, 'status');
        if ($jobStatus && \in_array($jobStatus, ['completed', 'failed'])) {
            return [
                'success' => true,
                'status'  => $jobStatus,
                'message' => Arr::get($jobData, 'message', \sprintf(
                /* translators: %1$s: job status (completed or failed) */
                    __('Job %1$s', 'fluent-cart'),
                    $jobStatus
                )),
                'stats'   => Arr::get($jobData, 'stats', []),
                'data'    => $jobData,
            ];
        }

        // Otherwise, it's still running
        return [
            'success' => true,
            'status' => 'running',
            'message' => __('Job is still running', 'fluent-cart'),
            'data' => $jobData,
        ];
    }
}
