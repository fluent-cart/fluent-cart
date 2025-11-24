<?php

namespace FluentCartPro\App\Modules\Licensing\Hooks\Handlers;

use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;

class LicenseSchedulerHandler
{
    public function register()
    {
        add_action('fluent_cart/scheduler/hourly_tasks', function () {
            $startedAt = time();
            while (true) {
                $processed = $this->expireOldLicenses();
                if (!$processed) {
                    break;
                }
                // Prevent long running process
                if ((time() - $startedAt) > 20) {
                    break;
                }
            }
        }, 20);
    }

    public function expireOldLicenses()
    {
        $dateTime = date('Y-m-d H:i:s', time() - DAY_IN_SECONDS * LicenseHelper::getLicenseGracePeriodDays());
        // Expire old licenses
        $licenses = License::query()
            ->whereIn('status', ['active', 'inactive'])
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<=', $dateTime)
            ->limit(100)
            ->get();

        if ($licenses->isEmpty()) {
            return false;
        }

        foreach ($licenses as $license) {
            $prevStatus = $license->status;
            $license->status = 'expired';
            $license->save();
            do_action('fluent_cart/licensing/license_expired', [
                'license'      => $license,
                'subscription' => $license->subscription,
                'prev_status'  => $prevStatus,
            ]);
        }

        return true;
    }
}
