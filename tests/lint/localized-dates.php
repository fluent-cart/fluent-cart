<?php
/**
 * S0 lint — a user-facing PHP date must render through DateFormatter.
 *
 * WHY THIS EXISTS
 * ---------------
 * PHP's `DateTime::format()` and `gmdate()` have no locale. `'M j, Y'` is
 * always `Aug 16, 2026`, whatever the site language is, and the day/month
 * order and the 12-hour clock are baked into the source string. That is the
 * defect the date/time settings were built to remove: the store's format, its
 * timezone, and WordPress's translated month and weekday names all live behind
 * `DateFormatter::format()`.
 *
 * Every call site that kept its own literal silently opts out. The bug is
 * invisible on an English site — the literal and the localized render agree —
 * so it only ever surfaces on a translated store, which is exactly where
 * nobody is running the test suite. Three separate call sites were missed on
 * the first pass and all three were found by a human reading a German
 * receipt:
 *
 *   - ReceiptRenderer's payment-table column kept `wp_date(get_option(...))`
 *     while its own header used DateFormatter, so ONE receipt rendered two
 *     different date formats.
 *   - The three renewal_overdue customer e-mails kept
 *     `DateTime::gmtToTimezone($dueAt)->format('M d, Y h:i A')`, the literal
 *     every other e-mail view had already dropped.
 *   - Helper's renewal-anchor labels built `in %s` from `gmdate('F', ...)`,
 *     so a German sentence carried an English month while the weekday branch
 *     directly above it used translated `__('Monday')` strings.
 *
 * WHAT THIS RULE CHECKS
 * ---------------------
 * In the user-facing PHP surfaces (`app/Views`, the receipt/invoice
 * renderers, and the helpers and integrations that build display strings), a
 * date-formatting call must not carry a literal month or weekday token.
 *
 * Flagged, because each produces an unlocalizable month/weekday name:
 *
 *     ->format('M d, Y h:i A')            // DateTime::format has no locale
 *     gmdate('F', $timestamp)             // nor does gmdate
 *     date('D, d M Y', $timestamp)
 *     wp_date(get_option('date_format'))  // localizes, but bypasses the
 *                                         // store's own format setting
 *
 * Not flagged:
 *
 *     ->format('Y-m-d H:i:s')             // machine/wire format, no names
 *     DateFormatter::format($d, true, $o) // the supported route
 *     wp_date('F', $ts, new DateTimeZone('UTC'))  // explicitly localized
 *
 * The token test is what keeps this precise: only `D`, `l`, `M` and `F`
 * render a name that a locale can change. A pattern built purely from
 * numeric tokens (`Y-m-d`, `H:i:s`, `d/m/Y`) is a wire format or a stable
 * identifier and is left alone, which is why every `'Y-m-d H:i:s'` column
 * write stays clean.
 *
 * A handful of PROTOCOL formats do carry `D`/`M` and still must never be
 * localized, because a machine parses them: the RFC-1123 HTTP date that
 * `S3Driver` signs an AWS request over is the live example. Those are
 * allow-listed verbatim in $protocolFormats, so any other format in the same
 * file is still reported.
 *
 * KNOWN LIMIT — this is a regex rule, not a PHP parser. It matches the literal
 * in the argument position of a formatting call; a pattern assembled into a
 * variable first (`$fmt = 'M j, Y'; $d->format($fmt);`) is not seen. Catching
 * that needs real dataflow, and the shape has not appeared in this codebase.
 * The rule is aimed at the shape that HAS shipped three times: a literal typed
 * directly into the call.
 *
 * THE FIX IT WANTS
 *     use FluentCart\App\Services\DateTime\DateFormatter;
 *
 *     // $order gives the timezone captured at checkout; omit it only when
 *     // there is genuinely no order behind the date.
 *     $dueDate = DateFormatter::format($dueAt, true, $order);
 *
 * Usage:  php tests/lint/localized-dates.php [path]
 * Exit:   0 clean, 1 violations found
 */

$root = is_dir(__DIR__ . '/../../app') ? dirname(__DIR__, 2) : getcwd();

// Default scan targets — the surfaces that render a date for a human to read.
// An explicit path argument overrides them, which is what the self-test uses
// to prove this rule actually fires:
//   php tests/lint/localized-dates.php tests/lint/fixtures/localized-dates
$scanDirs = [
    'app/Views',
    'app/Services',
    'app/Helpers',
    'app/Modules/Integrations',
];
if (isset($argv[1]) && $argv[1] !== '') {
    $scanDirs = [rtrim($argv[1], '/')];
}

// DateFormatter and DayjsFormatter own the literals on purpose: the fallback
// constants ARE the pre-settings patterns, and the Day.js bridge has to name
// PHP tokens to map them. Excluded by path so the rule cannot eat its own
// implementation.
$exemptPaths = [
    'app/Services/DateTime/',
];

// Protocol formats that MUST stay English regardless of locale: these are
// wire values a machine parses, not text a human reads. RFC 1123 is the HTTP
// date (an AWS request signature is computed over it, so localizing it would
// break the signature); RFC 2822 is the e-mail date header. They are listed
// verbatim rather than pattern-matched, so any OTHER format in the same file
// is still reported.
$protocolFormats = [
    'D, d M Y H:i:s T',   // RFC 1123 / HTTP-date
    'D, d M Y H:i:s O',   // RFC 2822
    'D, d M Y H:i:s',
    'D, d M Y',
];

// Only these PHP date tokens render a name a locale can change. A format
// built from anything else is numeric and therefore locale-stable.
$localeSensitiveTokens = ['D', 'l', 'M', 'F'];

/**
 * Does this PHP date() format string contain a month or weekday NAME token?
 *
 * Walks the pattern honouring backslash escapes, so `'\M\a\y'` — three escaped
 * literal characters — is correctly read as text rather than as a month token.
 */
$hasLocaleSensitiveToken = function ($format) use ($localeSensitiveTokens) {
    $length = strlen($format);
    for ($i = 0; $i < $length; $i++) {
        $char = $format[$i];
        if ($char === '\\') {
            $i++; // The next character is a literal, never a token.
            continue;
        }
        if (in_array($char, $localeSensitiveTokens, true)) {
            return true;
        }
    }
    return false;
};

// A formatting call whose first (or, for ->format(), only) argument is a
// single-quoted or double-quoted literal. Captures the pattern.
//
// wp_date() is deliberately absent: it DOES apply the locale's month and
// weekday names, so `wp_date('F', $ts)` is a correct way to render a month
// name and must not be reported. Its own failure mode — reading the WordPress
// option instead of the store setting — is shape 2 below.
$literalCallPattern =
    '/(?:->format|\bgmdate|\bdate)\s*\(\s*'
    . '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/';

// `wp_date(get_option('date_format'))` and friends localize the NAMES but read
// WordPress's option directly, skipping the store's date_time_format_source
// setting. That is the ReceiptRenderer split-format bug, so it is its own
// shape rather than a literal-token match.
$optionCallPattern = '/\bwp_date\s*\(\s*get_option\s*\(/';

// current_time(<first argument>) -- the argument is captured so shape 3 can
// tell a locale-stable literal from a name-producing one.
// A quoted literal is matched FIRST, or a format containing a comma
// ('M j, Y') would be cut at the comma and misread as unverifiable.
$currentTimeCallPattern = '/\bcurrent_time\s*\(\s*('
    . '\'(?:[^\'\\\\]|\\\\.)*\''
    . '|"(?:[^"\\\\]|\\\\.)*"'
    . '|[^,)]+'
    . ')/';

$violations = [];
$scanned = 0;

$iterate = function ($dir) use (&$iterate) {
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (strpos($path, '/node_modules/') !== false
            || strpos($path, '/vendor/') !== false
        ) {
            continue;
        }
        $out[] = $path;
    }
    return $out;
};

$lineOf = function ($contents, $offset) {
    return substr_count(substr($contents, 0, $offset), "\n") + 1;
};

foreach ($scanDirs as $dir) {
    // Accept absolute paths as well as paths relative to the plugin root.
    $target = ($dir !== '' && $dir[0] === '/') ? $dir : $root . '/' . $dir;
    foreach ($iterate($target) as $path) {
        $relative = strpos($path, $root . '/') === 0
            ? substr($path, strlen($root) + 1)
            : $path;

        $exempt = false;
        foreach ($exemptPaths as $exemptPath) {
            if (strpos($relative, $exemptPath) === 0) {
                $exempt = true;
                break;
            }
        }
        if ($exempt) {
            continue;
        }

        $scanned++;
        $contents = file_get_contents($path);
        if ($contents === false) {
            fwrite(STDERR, "localized-dates: could not read {$path}\n");
            exit(2);
        }

        // Shape 1 — a literal pattern carrying a month/weekday name token.
        if (preg_match_all($literalCallPattern, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                // Group 1 is the single-quoted body, group 2 the double-quoted.
                $format = isset($match[1]) && $match[1][1] !== -1 && $match[1][0] !== ''
                    ? $match[1][0]
                    : (isset($match[2]) && $match[2][1] !== -1 ? $match[2][0] : '');
                if ($format === ''
                    || in_array($format, $protocolFormats, true)
                    || !$hasLocaleSensitiveToken($format)
                ) {
                    continue;
                }
                $violations[] = [
                    'file'   => $relative,
                    'line'   => $lineOf($contents, $match[0][1]),
                    'format' => $format,
                    'reason' => 'literal date format carries a month/weekday name token; '
                        . 'DateTime::format() and gmdate() cannot localize it',
                ];
            }
        }

        // Shape 2 — localized names, but the store's format setting bypassed.
        if (preg_match_all($optionCallPattern, $contents, $optionMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($optionMatches[0] as $optionMatch) {
                $violations[] = [
                    'file'   => $relative,
                    'line'   => $lineOf($contents, $optionMatch[1]),
                    'format' => "wp_date(get_option(...))",
                    'reason' => 'reads the WordPress option directly, so it ignores the '
                        . "store's date_time_format_source setting",
                ];
            }
        }

        // Shape 3 — current_time() asked to render a human-readable date.
        //
        // current_time() is gmdate() underneath, so it cannot localize. The
        // {{date}} e-mail shortcode used current_time(get_option('date_format'))
        // and rendered '14. October 2026' on a German store: the store's own
        // field order with an English month name.
        //
        // Most current_time() calls are legitimate -- a scheduler reading
        // current_time('G') or current_time('Y-m-d') wants a locale-STABLE
        // value and must not be reported. Only a call that can produce a name
        // is flagged: a literal carrying D/l/M/F, or an argument this rule
        // cannot read at all, which is what the shortcode bug looked like.
        if (preg_match_all($currentTimeCallPattern, $contents, $timeMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($timeMatches as $match) {
                $argument = trim($match[1][0]);

                // A quoted literal: judge it by its tokens.
                if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$|^"((?:[^"\\\\]|\\\\.)*)"$/', $argument, $quoted)) {
                    $format = isset($quoted[2]) && $quoted[2] !== '' ? $quoted[2] : $quoted[1];
                    if ($format === 'mysql' || $format === 'timestamp') {
                        continue;
                    }
                    if (!$hasLocaleSensitiveToken($format)) {
                        continue;
                    }
                    $violations[] = [
                        'file'   => $relative,
                        'line'   => $lineOf($contents, $match[0][1]),
                        'format' => 'current_time(' . $argument . ')',
                        'reason' => 'current_time() is gmdate() underneath and cannot localize '
                            . 'a month or weekday name',
                    ];
                    continue;
                }

                $violations[] = [
                    'file'   => $relative,
                    'line'   => $lineOf($contents, $match[0][1]),
                    'format' => 'current_time(' . $argument . ')',
                    'reason' => 'current_time() cannot localize, and this format is not a '
                        . 'literal this rule can verify; render it through DateFormatter',
                ];
            }
        }
    }
}

if (!$violations) {
    echo "localized-dates: clean ({$scanned} files scanned)\n";
    exit(0);
}

echo 'FAIL — ' . count($violations) . " violation(s):\n";
foreach ($violations as $violation) {
    echo "\n  {$violation['file']}:{$violation['line']}\n";
    echo "    {$violation['reason']}\n";
    echo "    {$violation['format']}\n";
}
echo "\nRender it through DateFormatter::format(\$date, \$withTime, \$order) instead.\n";
exit(1);
