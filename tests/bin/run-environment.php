<?php
/**
 * Phase 20 opt-in environment-axis preflight.
 */

require_once dirname(__DIR__) . '/lib/harness.php';

FcTest::boot();
FcTest::interceptCronMutations();
FcTest::interceptActionScheduler();

$axis = (string) getenv('WP_PLUGIN_TEST_ENVIRONMENT_AXIS');
$inventory = require dirname(__DIR__) . '/environment/axes.php';

if (!isset($inventory[$axis])) {
    WP_CLI::error('Unknown Phase 20 environment axis: ' . $axis);
}

FcTest::case($inventory[$axis]['name'], $inventory[$axis]['run']);

WP_CLI::log(sprintf(
    'Environment-axis safety guards: outbound_http=%d mail=%d cron=%d action_scheduler=%d',
    count(FcTest::externalCalls()),
    count(FcTest::sentMails()),
    count(FcTest::cronAttempts()),
    count(FcTest::actionSchedulerAttempts())
));

FcTest::finish('ENVIRONMENT AXIS PREFLIGHT');
