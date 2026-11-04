<?php
/**
 * FIXTURE — must NEVER be reported. Every pattern here is numeric, so it is a
 * wire format or a stable identifier that no locale may change. If this file
 * ever starts being reported, the rule has become a false-positive machine.
 */

$column    = $order->created_at->format('Y-m-d H:i:s');
$dayStamp  = gmdate('Y-m-d', $timestamp);
$clock     = $moment->format('H:i:s');
$slashed   = date('d/m/Y', $timestamp);
$escapedMy = $moment->format('\M\a\y');  // escaped literals, not tokens
