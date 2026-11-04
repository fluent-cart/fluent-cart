<?php
/**
 * FIXTURE — the Helper renewal-anchor shape: an English month name dropped
 * into an otherwise translated sentence. MUST be flagged. Never "fix" this file.
 */

$onMonthDay = sprintf(__('on %s', 'fluent-cart'), gmdate('M', gmmktime(12, 0, 0, $month, 1, 2001)) . ' ' . $day);
$inMonth    = sprintf(__('in %s', 'fluent-cart'), gmdate('F', gmmktime(12, 0, 0, $month, 1, 2001)));
