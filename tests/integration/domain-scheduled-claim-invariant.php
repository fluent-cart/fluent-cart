<?php
/**
 * Phase 8 scheduled-action single-claim invariant.
 */

return [
    [
        'id'            => 'domain-scheduled-action-claimed-once',
        'name'          => 'Two scheduler workers cannot select the same pending action',
        'kind'          => 'behavior',
        'known_failure' => true,
        'run'           => function () {
            try {
                $action = FcDomainFixture::scheduledAction();
                $filters = ['group' => FcDomainFixture::marker('scheduled-group')];
                $first = new FcPhase8RecordingJobRunner();
                $second = new FcPhase8RecordingJobRunner();

                $first->start($filters);
                $second->start($filters);

                $stored = FluentCart\App\Models\ScheduledAction::query()
                    ->find((int) $action->id);
                $actual = [
                    'first'  => $first->selectedIds,
                    'second' => $second->selectedIds,
                    'status' => $stored ? (string) $stored->status : 'missing',
                ];
                $claimedOnce = $actual['first'] === [(int) $action->id]
                    && $actual['second'] === []
                    && in_array($actual['status'], ['processing', 'running', 'completed'], true);

                if ($claimedOnce) {
                    FcTest::fail(
                        'KNOWN-FAILURE unexpectedly passed; reclassify the scheduler claim invariant.'
                    );
                } elseif ($actual === [
                    'first'  => [(int) $action->id],
                    'second' => [(int) $action->id],
                    'status' => 'pending',
                ]) {
                    FcTest::skip(
                        'KNOWN-FAILURE — JobRunner.php:37-48 selects pending rows '
                        . 'without an atomic claim; both workers selected ID '
                        . (int) $action->id
                    );
                } else {
                    FcTest::fail(
                        'KNOWN-FAILURE behavior drifted from the documented unclaimed-row defect.'
                        . "\n  actual: " . wp_json_encode($actual)
                    );
                }
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
