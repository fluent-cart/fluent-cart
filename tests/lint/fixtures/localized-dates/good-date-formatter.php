<?php
/**
 * FIXTURE — must NEVER be reported. This is the supported route, plus the one
 * legitimate use of a literal month token: wp_date() DOES apply the locale's
 * month names, so naming 'F' there is correct rather than a bypass.
 */

use FluentCart\App\Services\DateTime\DateFormatter;

$dueDate   = DateFormatter::format($dueAt, true, $order);
$orderDate = DateFormatter::format($order->created_at, false, $orderTz);
$monthName = wp_date('F', gmmktime(12, 0, 0, $month, 1, 2001), new \DateTimeZone('UTC'));
