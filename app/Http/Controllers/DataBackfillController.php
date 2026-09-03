<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Database\DataBackfills;

class DataBackfillController extends Controller
{
    /**
     * Run pending one-shot data backfills within this request's budget.
     * The admin app silently re-calls this endpoint while the returned
     * status is 'running'; 'locked' means another request is already on it.
     */
    public function run()
    {
        return $this->sendSuccess(DataBackfills::processPending());
    }
}
