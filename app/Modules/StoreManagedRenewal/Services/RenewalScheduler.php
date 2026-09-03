<?php

namespace FluentCart\App\Modules\StoreManagedRenewal\Services;

class RenewalScheduler
{
    /**
     * Register the scheduler hook for hourly processing
     */
    public function register()
    {
        add_action('fluent_cart/scheduler/hourly_tasks', [$this, 'handle'], 10);
    }

    /**
     * Handle the hourly task to process due subscriptions, overdue invoices, and reminders
     */
    public function handle()
    {
        // 1. Create renewal invoices for due subscriptions
        $results = RenewalService::processDueSubscriptions(20);

        if ($results['processed'] > 0 || $results['failed'] > 0) {
            fluent_cart_error_log(
                'Renewal Scheduler Results',
                sprintf(
                    'Processed: %d, Failed: %d',
                    $results['processed'],
                    $results['failed']
                )
            );

            if (!empty($results['errors'])) {
                foreach ($results['errors'] as $error) {
                    fluent_cart_error_log(
                        'Renewal Scheduler Error',
                        sprintf(
                            'Subscription ID: %d, Error: %s',
                            $error['subscription_id'],
                            $error['error']
                        )
                    );
                }
            }
        }

        // 2. Process overdue invoices — transition subscriptions past_due → expired
        $overdueResults = RenewalService::processOverdueRenewals(20);

        if ($overdueResults['past_due'] > 0 || $overdueResults['expired'] > 0) {
            fluent_cart_error_log(
                'Overdue Renewal Processing Results',
                sprintf(
                    'Marked %d subscription(s) as past due, %d as expired',
                    $overdueResults['past_due'],
                    $overdueResults['expired']
                )
            );
        }

    }
}
