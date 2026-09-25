<?php
/**
 * FIXTURE — must NEVER be reported. A scheduler reading the wall clock wants a
 * locale-STABLE value; localizing these would break the comparisons they feed.
 * These are the real StoreDigestService shapes.
 */

$hour     = (int) current_time('G');        // site-local hour, 0-23
$weekday  = (int) current_time('N');        // ISO weekday number
$isoWeek  = 'W-' . current_time('o-W');
$dayKey   = 'D-' . current_time('Y-m-d');
$mysqlNow = current_time('mysql');
$epoch    = current_time('timestamp');

// RFC-1123: a protocol format a machine parses. Localizing it would break the
// AWS request signature computed over it.
$httpDate = gmdate('D, d M Y H:i:s T');
