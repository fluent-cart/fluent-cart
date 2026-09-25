<?php
/**
 * FIXTURE — the {{date}} e-mail shortcode shape. current_time() is gmdate()
 * underneath, so on a German store this rendered '14. October 2026': the
 * store's own field order with an English month name. MUST be flagged.
 * Never "fix" this file.
 */

// The format is held in a variable, so the token test cannot read it. That is
// precisely the shape that shipped, so an unreadable argument is flagged.
$dateFormat = get_option('date_format');
$today = current_time($dateFormat);

// A literal naming a month is flagged on its own tokens.
$stamp = current_time('M j, Y');
