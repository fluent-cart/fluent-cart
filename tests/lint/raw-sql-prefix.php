<?php
/**
 * S0 lint — raw SQL must not contain unprefixed plugin table identifiers.
 *
 * WHY THIS EXISTS
 * ---------------
 * In WPFluent, qualified identifiers passed to `where()`, `select()`,
 * `join()` and `groupBy()` all pass through BaseGrammar::wrap(), which prepends
 * $wpdb->prefix. `raw()` / `selectRaw()` / `whereRaw()` DO NOT — the string is
 * handed to the driver verbatim. So this:
 *
 *     ->selectRaw("SUM(CASE WHEN plugin_table.status = 'paid' ...)")
 *
 * produces an unprefixed table reference while every other identifier in the
 * same query is correctly prefixed. PHP syntax checks and code review cannot
 * detect that runtime SQL mismatch. This rule is the cheap guard.
 *
 * THE FIX IT WANTS
 *     global $wpdb;
 *     $t = $wpdb->prefix . '<configured table>';
 *     ->selectRaw("SUM(CASE WHEN `{$t}`.`status` = 'paid' ...)")
 *
 * or better — keep plain qualified columns in select() and put only the
 * aggregate in selectRaw().
 *
 * Usage:  php tests/lint/raw-sql-prefix.php
 * Exit:   0 clean, 1 violations found
 */

// Resolve the plugin root from the current working directory when this script
// lives outside the plugin (e.g. run from a shared skill/assets copy), and from
// the script's own location when it has been installed into tests/lint/.
$root = is_dir(__DIR__ . '/../../app') ? dirname(__DIR__, 2) : getcwd();
$configFile = $root . '/tests/suite.config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "raw-sql-prefix: missing tests/suite.config.php\n");
    exit(2);
}

$config = require $configFile;
$tablePrefix = isset($config['table_prefix']) ? (string) $config['table_prefix'] : '';
if (!preg_match('/^[a-z][a-z0-9_]*_$/', $tablePrefix)) {
    fwrite(STDERR, "raw-sql-prefix: invalid table_prefix in tests/suite.config.php\n");
    exit(2);
}

// Default scan targets. An explicit path argument overrides them, which is what
// the self-test uses to prove this rule actually fires:
//   php tests/lint/raw-sql-prefix.php tests/lint/fixtures   ->  must exit 1
$scanDirs = ['app', 'api', 'boot', 'database'];
if (isset($argv[1]) && $argv[1] !== '') {
    $scanDirs = [rtrim($argv[1], '/')];
}

$rawCallNames = [
    'selectraw',
    'whereraw',
    'havingraw',
    'orderbyraw',
    'groupbyraw',
    'orwhereraw',
    'raw',
];

// Raw SQL can reference a plugin table either as a qualified column
// (`fct_orders.id`) or as the table following a SQL table keyword
// (`FROM fct_orders`). Backticks around the table name are optional.
$qualifiedPattern = '/(?<![a-zA-Z0-9_])`?('
    . preg_quote($tablePrefix, '/')
    . '[a-z0-9_]+)`?\s*\./i';
$tableReferencePattern = '/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?('
    . preg_quote($tablePrefix, '/')
    . '[a-z0-9_]+)`?(?![a-zA-Z0-9_])/i';

// A prefix concatenated immediately before a string literal resolves that
// literal at runtime and is therefore not a bare identifier.
$concatenatedPrefixPattern = '/(?:\$wpdb->prefix|getTablePrefix\s*\(\s*\)|'
    . '\$[a-zA-Z_][a-zA-Z0-9_]*)\s*\.\s*$/s';

$violations = [];
$scanned = 0;

$iterate = function ($dir) use (&$iterate) {
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $path = $file->getPathname();
            // Never lint vendored or scoped-vendor trees.
            if (strpos($path, '/vendor/') !== false || strpos($path, 'scoped-vendor') !== false) {
                continue;
            }
            $out[] = $path;
        }
    }
    return $out;
};

foreach ($scanDirs as $dir) {
    // Accept absolute paths (self-test from a shared copy) as well as paths
    // relative to the plugin root.
    $target = ($dir !== '' && $dir[0] === '/') ? $dir : $root . '/' . $dir;
    foreach ($iterate($target) as $path) {
        $scanned++;
        $contents = file_get_contents($path);
        if ($contents === false) {
            fwrite(STDERR, "raw-sql-prefix: could not read {$path}\n");
            exit(2);
        }

        // Fixtures use a neutral marker so the real prefix remains
        // centralized in suite.config.php while still exercising the
        // configured regex.
        $contents = str_replace('__PLUGIN_TABLE_PREFIX__', $tablePrefix, $contents);
        $sourceLines = preg_split('/\R/', $contents);
        $tokens = token_get_all($contents);
        $tokenMeta = [];
        $offset = 0;
        $currentLine = 1;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $tokenMeta[] = [
                'id'     => is_array($token) ? $token[0] : null,
                'text'   => $text,
                'line'   => is_array($token) ? $token[2] : $currentLine,
                'offset' => $offset,
            ];
            $offset += strlen($text);
            $currentLine += substr_count($text, "\n");
        }

        $tokenCount = count($tokenMeta);
        for ($i = 0; $i < $tokenCount; $i++) {
            $callToken = $tokenMeta[$i];
            if (
                $callToken['id'] !== T_STRING
                || !in_array(strtolower($callToken['text']), $rawCallNames, true)
            ) {
                continue;
            }

            $openIndex = $i + 1;
            while (
                $openIndex < $tokenCount
                && in_array(
                    $tokenMeta[$openIndex]['id'],
                    [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                    true
                )
            ) {
                $openIndex++;
            }
            if (
                $openIndex >= $tokenCount
                || $tokenMeta[$openIndex]['text'] !== '('
            ) {
                continue;
            }

            $depth = 1;
            for ($j = $openIndex + 1; $j < $tokenCount && $depth > 0; $j++) {
                $literalToken = $tokenMeta[$j];
                if ($literalToken['text'] === '(') {
                    $depth++;
                    continue;
                }
                if ($literalToken['text'] === ')') {
                    $depth--;
                    continue;
                }
                if (
                    $literalToken['id'] !== T_CONSTANT_ENCAPSED_STRING
                    && $literalToken['id'] !== T_ENCAPSED_AND_WHITESPACE
                ) {
                    continue;
                }

                $beforeLiteral = substr(
                    $contents,
                    $tokenMeta[$openIndex]['offset'] + 1,
                    $literalToken['offset'] - $tokenMeta[$openIndex]['offset'] - 1
                );
                if (preg_match($concatenatedPrefixPattern, $beforeLiteral)) {
                    continue;
                }

                $checks = [
                    [
                        'kind'    => 'qualified identifier',
                        'pattern' => $qualifiedPattern,
                    ],
                    [
                        'kind'    => 'table reference',
                        'pattern' => $tableReferencePattern,
                    ],
                ];
                foreach ($checks as $check) {
                    if (!preg_match_all(
                        $check['pattern'],
                        $literalToken['text'],
                        $matches,
                        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
                    )) {
                        continue;
                    }

                    foreach ($matches as $match) {
                        $identifier = $match[1][0];
                        $identifierOffset = $match[1][1];
                        $line = $literalToken['line']
                            + substr_count(
                                substr($literalToken['text'], 0, $identifierOffset),
                                "\n"
                            );

                        $violations[] = [
                            'file'  => str_replace($root . '/', '', $path),
                            'line'  => $line,
                            'ident' => $identifier,
                            'kind'  => $check['kind'],
                            'code'  => isset($sourceLines[$line - 1])
                                ? trim($sourceLines[$line - 1])
                                : trim($literalToken['text']),
                        ];
                    }
                }
            }
        }
    }
}

echo "raw-sql-prefix: scanned {$scanned} PHP files\n";

if (!$violations) {
    echo "OK — no unprefixed plugin table identifiers inside raw SQL.\n";
    exit(0);
}

echo "\nFAIL — " . count($violations) . " violation(s):\n\n";
foreach ($violations as $v) {
    echo "  {$v['file']}:{$v['line']}\n";
    echo "    unprefixed {$v['kind']}: {$v['ident']}\n";
    echo "    " . (strlen($v['code']) > 140 ? substr($v['code'], 0, 137) . '...' : $v['code']) . "\n\n";
}
echo "Raw SQL bypasses the query grammar. Resolve the prefix explicitly:\n";
echo "  global \$wpdb; \$t = \$wpdb->prefix . '" . $tablePrefix . "orders';\n";
echo "  ->selectRaw(\"... `{\$t}`.`status` ...\")\n";
exit(1);
