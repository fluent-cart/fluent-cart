<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\Services\Report\RetentionSnapshotService;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;

/**
 * The Cohorts page rebuilds snapshots in short requests: start, then step until done. Nothing runs in the background,
 * so it works on hosts where WP-Cron or loopback requests do not.
 */
class RetentionSnapshotController extends Controller
{
    public function generate(Request $request)
    {
        return (new RetentionSnapshotService())->start();
    }

    public function step(Request $request)
    {
        return (new RetentionSnapshotService())->step(sanitize_text_field((string) $request->get('token', '')));
    }
}
