<?php
/**
 * S0 lint — every state/province name in states.php must be a distinct
 * translation entry, and no country may declare the same state code twice.
 *
 * WHY THIS EXISTS
 * ---------------
 * gettext merges byte-identical msgids that share a context into ONE entry.
 * Ghana's "Western" region and Zambia's "Western" province were both
 * __('Western', 'fluent-cart'), so translators had a single slot for two
 * places whose wording differs by language (WordPress.org support report;
 * PR #2874 fixed Western, a later report found Eastern and Northern too).
 * A repeated name must carry an _x() context naming its country, so each
 * (msgid, context) pair belongs to exactly one country.
 *
 * Country names (countries.php) join the same pool: Nigeria's "Niger" state
 * and the country Niger would otherwise share one entry, as would Georgia
 * before it gained its 'US state of Georgia' context.
 *
 * Also flags a state code repeated inside one country array — PHP silently
 * keeps the last value, so the earlier line is dead code.
 *
 * Usage:  php tests/lint/state-translation-contexts.php [path/to/states.php]
 * Exit:   0 clean, 1 violations found
 */

$root = is_dir(__DIR__ . '/../../app') ? dirname(__DIR__, 2) : getcwd();
$rel = 'app/Services/Localization/i18n/states.php';
$file = isset($argv[1]) ? $argv[1] : $root . '/' . $rel;
if (!is_file($file)) {
    fwrite(STDERR, "state-translation-contexts: missing {$file}\n");
    exit(2);
}

// A single- or double-quoted PHP string literal, captured with its quotes.
$string = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';
$callPattern = '/\b(__|_x)\(\s*' . $string . '\s*,\s*' . $string . '/';
$unquote = function ($literal) {
    return stripslashes(substr($literal, 1, -1));
};

$entries = []; // "msgid\0context" => [[country, line], ...]
$violations = [];
$country = null;
$codes = [];
$calls = 0;

// Country names are owned by the pseudo-country "countries.php".
$countriesFile = dirname($file) . '/countries.php';
if (is_file($countriesFile)) {
    foreach (explode("\n", file_get_contents($countriesFile)) as $idx => $lineText) {
        if (preg_match($callPattern, $lineText, $m)) {
            $context = $m[1] === '_x' ? $unquote($m[3]) : '';
            $entries[$unquote($m[2]) . "\0" . $context][] = ['countries.php', $idx + 1];
        }
    }
}

foreach (explode("\n", file_get_contents($file)) as $idx => $lineText) {
    $lineNo = $idx + 1;
    if (preg_match("/^    '([A-Z]{2})' => (?:array\(|\[)/", $lineText, $m)) {
        $country = $m[1];
        $codes = [];
    }
    if ($country === null || !preg_match($callPattern, $lineText, $m)) {
        continue;
    }
    $calls++;
    $msgid = $unquote($m[2]);
    // For __() the second argument is the text domain, not a context.
    $context = $m[1] === '_x' ? $unquote($m[3]) : '';
    $entries[$msgid . "\0" . $context][] = [$country, $lineNo];

    if (preg_match("/^\s*'([^']+)'\s*=>/", $lineText, $km)) {
        if (isset($codes[$km[1]])) {
            $violations[] = "{$rel}:{$lineNo}\n    {$country} state code '{$km[1]}' already declared on line {$codes[$km[1]]} (dead entry)";
        } else {
            $codes[$km[1]] = $lineNo;
        }
    }
}

foreach ($entries as $key => $uses) {
    $countries = array_unique(array_column($uses, 0));
    if (count($countries) < 2) {
        continue;
    }
    list($msgid, $context) = explode("\0", $key);
    $where = implode(', ', array_map(function ($use) {
        return $use[0] . ' line ' . $use[1];
    }, $uses));
    $label = $context === '' ? "__('{$msgid}')" : "_x('{$msgid}', '{$context}')";
    $violations[] = "{$rel}\n    {$label} shared by {$where} — one translation slot for " . count($countries) . ' countries';
}
$entries = array_filter($entries, function ($uses) {
    return array_diff(array_column($uses, 0), ['countries.php']) !== [];
});

echo "state-translation-contexts: {$calls} translated names, " . count($entries) . " distinct entries\n";

if (!$violations) {
    echo "OK — every state name has its own translation entry.\n";
    exit(0);
}

echo "\nFAIL — " . count($violations) . " violation(s):\n\n";
foreach ($violations as $v) {
    echo "  {$v}\n\n";
}
echo "A name used by more than one country must be _x('Name', '<Country> <Subdivision>', 'fluent-cart')\n";
echo "so translators can word each country's entry independently.\n";
exit(1);
