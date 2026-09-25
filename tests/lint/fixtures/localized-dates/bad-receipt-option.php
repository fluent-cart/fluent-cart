<?php
/**
 * FIXTURE — the ReceiptRenderer payment-table shape: localized names, but the
 * WordPress option read directly, so the store's date_time_format_source
 * setting is bypassed and one document renders two different formats.
 * MUST be flagged. Never "fix" this file.
 */

$date = wp_date(
    get_option('date_format'),
    $timestamp,
    new \DateTimeZone($orderTz)
);
