<?php
/**
 * FIXTURE — the renewal_overdue e-mail shape. `tests/lint/localized-dates.php`
 * MUST flag it. If this file ever stops being reported, the rule has
 * regressed. Never "fix" this file; it exists to stay broken.
 *
 *   php tests/lint/localized-dates.php tests/lint/fixtures/localized-dates
 *   # must exit 1
 */

// The literal every other e-mail view had already dropped.
$dueDate = $dueAt ? \FluentCart\App\Services\DateTime\DateTime::gmtToTimezone($dueAt)->format('M d, Y h:i A') : '';
