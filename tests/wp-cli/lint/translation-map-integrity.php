<?php
/**
 * S0 lint — admin SPA translation maps must contain keys the built JS can
 * actually look up, and sources must not feed translate() untranslatable text.
 *
 * WHY THIS EXISTS
 * ---------------
 * The admin SPA translates by EXACT string match: the built bundle calls
 * translate('You must type "%1$s" exactly to proceed') and that byte string is
 * looked up as an array key in app/Services/Translations/admin-translation.php.
 * Three key-corruption shapes make an entry unreachable while grep still says
 * it "exists" (customer report, 2026-08-11):
 *
 *   1. \" inside a single-quoted PHP key. Single quotes keep the backslash, so
 *      the key contains a literal \" while the bundle looks up a plain ".
 *   2. &quot; entities. Vue's template compiler decodes entities at build
 *      time, so the bundle looks up plain quotes; the key keeps &quot;.
 *   3. ${...} fragments. A JS template literal was passed to translate(); the
 *      msgid is frozen source code and the runtime string never matches it.
 *
 * Additionally:
 *   4. A sprintf placeholder immediately followed by a lone % ("%s%") is an
 *      invalid php-format msgid — .pot tooling rejects it.
 *   5. Router meta titles surface in the browser tab and settings headings.
 *      Each must be translate('...') and its literal must be a map key,
 *      otherwise the title silently ships untranslated.
 *   6. translate(`...${expr}...`) in source produces shape 3 — flagged at the
 *      call site so it never reaches a map again.
 *   7. translate(cond ? 'a' : 'b') or translate(msg || 'fallback'), also as
 *      blocktranslate() or this.translate(): the extractor only reads a literal
 *      right after "(", so neither string is mapped.
 *
 * Usage:  php tests/wp-cli/lint/translation-map-integrity.php
 * Exit:   0 clean, 1 violations found
 */

$root = is_dir(__DIR__ . '/../../../app') ? dirname(__DIR__, 3) : getcwd();

$mapDir = $root . '/app/Services/Translations';
$adminMapFile = $mapDir . '/admin-translation.php';
if (!is_dir($mapDir) || !is_file($adminMapFile)) {
    fwrite(STDERR, "translation-map-integrity: missing {$mapDir}\n");
    exit(2);
}

// Route definition files whose meta titles feed the browser tab
// (useNavigationMenuUpdateService), the settings heading (StoreSettings) and
// the payment page name (GlobalPaymentComponents) — mapped to the translation
// map their bundle's translate util reads at runtime. The extra Vite entries
// (withdrawal, order-bump, addon-assets, attributes) share the
// admin translator; the customer-profile portal ships its own translator and
// reads customer-profile-translation.php instead.
$routeFiles = [
    'resources/admin/routes.js' => 'admin-translation.php',
    'resources/admin/Modules/Subscriptions/subscription.js' => 'admin-translation.php',
    'resources/admin/Modules/Shipping/shipping.js' => 'admin-translation.php',
    'resources/admin/Modules/Coupons/coupons.js' => 'admin-translation.php',
    'resources/withdrawal/withdrawal.js' => 'admin-translation.php',
    'resources/order-bump/order-bump.js' => 'admin-translation.php',
    'resources/addon-assets/addon-assets.js' => 'admin-translation.php',
    'resources/attributes/attributes.js' => 'admin-translation.php',
    'resources/public/customer-profile/Start.js' => 'customer-profile-translation.php',
];

$violations = [];

/**
 * Decode a PHP string literal token to its runtime value.
 * Single quotes: only \' and \\ are escapes — everything else is literal,
 * which is exactly how a \" survives into the runtime key.
 */
$decodeLiteral = function ($token) {
    $quote = $token[0];
    $body = substr($token, 1, -1);
    if ($quote === "'") {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
    }
    return str_replace(
        ['\\\\', '\\"', '\\n', '\\t', '\\r', '\\$'],
        ['\\', '"', "\n", "\t", "\r", '$'],
        $body
    );
};

// ---------------------------------------------------------------------------
// 1) Map files: every array key must be a reachable, valid msgid.
// ---------------------------------------------------------------------------
$mapKeys = []; // file basename => [runtime key => line]
foreach (glob($mapDir . '/*.php') as $mapFile) {
    $base = basename($mapFile);
    if ($base === 'index.php' || $base === 'TransStrings.php') {
        continue;
    }
    $mapKeys[$base] = [];
    $tokens = token_get_all(file_get_contents($mapFile));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        // An array key is a string literal whose next significant token is =>
        $next = $i + 1;
        while (
            $next < $count
            && is_array($tokens[$next])
            && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT], true)
        ) {
            $next++;
        }
        $isKey = $next < $count
            && is_array($tokens[$next])
            && $tokens[$next][0] === T_DOUBLE_ARROW;
        if (!$isKey) {
            continue;
        }

        $line = $tokens[$i][2];
        $key = $decodeLiteral($tokens[$i][1]);
        $mapKeys[$base][$key] = $line;

        $rel = 'app/Services/Translations/' . $base;
        if (strpos($key, '\\"') !== false) {
            $violations[] = [
                'file' => $rel,
                'line' => $line,
                'kind' => 'backslash-quote key (bundle looks up a plain ")',
                'code' => $key,
            ];
        }
        if (strpos($key, '&quot;') !== false) {
            $violations[] = [
                'file' => $rel,
                'line' => $line,
                'kind' => '&quot; entity key (Vue decodes entities at build time)',
                'code' => $key,
            ];
        }
        if (strpos($key, '${') !== false) {
            $violations[] = [
                'file' => $rel,
                'line' => $line,
                'kind' => 'template-literal msgid (frozen JS source, never matches)',
                'code' => $key,
            ];
        }
        if (preg_match('/%(?:\d+\$)?s%(?![%s0-9])/', $key)) {
            $violations[] = [
                'file' => $rel,
                'line' => $line,
                'kind' => 'placeholder followed by lone % (invalid php-format msgid)',
                'code' => $key,
            ];
        }
    }
}
$adminKeys = isset($mapKeys['admin-translation.php'])
    ? $mapKeys['admin-translation.php']
    : [];

// ---------------------------------------------------------------------------
// 2) Route meta titles: translate('...') wrapped AND present in the admin map.
// ---------------------------------------------------------------------------
foreach ($routeFiles as $rel => $mapBase) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        fwrite(STDERR, "translation-map-integrity: missing route file {$rel}\n");
        exit(2);
    }
    $bundleKeys = isset($mapKeys[$mapBase]) ? $mapKeys[$mapBase] : [];
    $lines = preg_split('/\R/', file_get_contents($path));
    foreach ($lines as $idx => $lineText) {
        if (!preg_match('/^\s*title:\s*(.+?),?\s*$/', $lineText, $m)) {
            continue;
        }
        $value = trim($m[1]);
        $lineNo = $idx + 1;

        if (preg_match('/^(?:translate|Translate)\(\s*([\'"])(.*)\1\s*\)$/', $value, $tm)) {
            $literal = $tm[2];
            if (!isset($bundleKeys[$literal])) {
                $violations[] = [
                    'file' => $rel,
                    'line' => $lineNo,
                    'kind' => "route title not in {$mapBase}",
                    'code' => $literal,
                ];
            }
            continue;
        }

        if (preg_match('/^([\'"])(?:(?!\1).)*\1$/', $value)) {
            $violations[] = [
                'file' => $rel,
                'line' => $lineNo,
                'kind' => 'route title is a bare literal (never translated)',
                'code' => $value,
            ];
        }
        // Anything else (computed titles) is out of this lint's scope.
    }
}

// ---------------------------------------------------------------------------
// 3) Source: translate(`...${...}...`) template-literal call sites.
// ---------------------------------------------------------------------------
$srcRoot = $root . '/resources';
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS)
);
$scanned = 0;
$flagWrappedLiterals = function ($relative, $contents) use (&$violations) {
    if (!preg_match_all('/(?:(?<![\w$])(?:block)?translate|\$t)\(\s*[^\'"`()]*?(?:\?|\|\|)\s*[\'"`]/', $contents, $wrapped, PREG_OFFSET_CAPTURE)) {
        return;
    }
    foreach ($wrapped[0] as $match) {
        $violations[] = [
            'file' => $relative,
            'line' => substr_count(substr($contents, 0, $match[1]), "\n") + 1,
            'kind' => 'translate() wraps a ternary or || fallback: translate each literal on its own',
            'code' => preg_replace('/\s+/', ' ', substr($match[0], 0, 120)),
        ];
    }
};
// Served by PHP and compiled in the admin app, so it shares the admin map
foreach (glob($root . '/app/Modules/StorageDrivers/S3/VueTemplates/*.vue') as $templateFile) {
    $flagWrappedLiterals(str_replace($root . '/', '', $templateFile), (string) file_get_contents($templateFile));
}
// translate('Short_Key') calls: a key with an underscore and no space is a reservedWords.json key (section 4)
$reservedUses = [];
$reservedOutside = [];
foreach ($rii as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['js', 'vue', 'jsx'], true)) {
        continue;
    }
    $path = $file->getPathname();
    if (strpos($path, '/node_modules/') !== false) {
        continue;
    }
    $scanned++;
    $contents = file_get_contents($path);
    // A long sentence in an admin bundle belongs in reservedWords.json (the block editor and portal keep their own maps)
    $relative = str_replace($root . '/', '', $path);
    $flagWrappedLiterals($relative, $contents);
    if (preg_match('#^resources/(admin|withdrawal|order-bump|addon-assets|attributes|accounting-export)/#', $relative) && strpos($relative, '/BlockEditor/') === false
        && preg_match_all('/(?:(?<![\w$.])translate|\$t)\(\s*([\'"`])((?:\\\\.|(?!\1)[^\\\\])*)\1/', $contents, $longMatches, PREG_OFFSET_CAPTURE)) {
        foreach ($longMatches[2] as $literal) {
            if (strpos($literal[0], '${') === false && preg_match_all('/./su', stripslashes($literal[0])) >= 80) {
                $violations[] = [
                    'file' => $relative,
                    'line' => substr_count(substr($contents, 0, $literal[1]), "\n") + 1,
                    'kind' => 'admin sentence of 80+ chars: put it in reservedWords.json and call translate(\'Key\')',
                    'code' => substr(stripslashes($literal[0]), 0, 100),
                ];
            }
        }
    }
    if (preg_match_all('/(?:(?<![\w$.])translate|\$t)\(\s*([\'"])([A-Za-z0-9]+(?:_[A-Za-z0-9]+)+)\1/', $contents, $keyMatches, PREG_OFFSET_CAPTURE)) {
        $isAdminBundle = preg_match('#^resources/(admin|withdrawal|order-bump|addon-assets|attributes|accounting-export)/#', $relative) && strpos($relative, '/BlockEditor/') === false;
        foreach ($keyMatches[2] as $keyMatch) {
            $place = $relative . ':' . (substr_count(substr($contents, 0, $keyMatch[1]), "\n") + 1);
            if ($isAdminBundle) {
                $reservedUses[$keyMatch[0]][] = $place;
            } else {
                // The block editor and portal print their own maps without reserved keys
                $reservedOutside[$keyMatch[0]][] = $place;
            }
        }
    }
    if (
        !preg_match_all(
            '/(?:\btranslate|\$t)\s*\(\s*`[^`]*\$\{[^`]*`/s',
            $contents,
            $matches,
            PREG_OFFSET_CAPTURE
        )
    ) {
        continue;
    }
    foreach ($matches[0] as $match) {
        $lineNo = substr_count(substr($contents, 0, $match[1]), "\n") + 1;
        $violations[] = [
            'file' => str_replace($root . '/', '', $path),
            'line' => $lineNo,
            'kind' => 'translate() fed a template literal (untranslatable msgid)',
            'code' => preg_replace('/\s+/', ' ', substr($match[0], 0, 120)),
        ];
    }
}

// ---------------------------------------------------------------------------
// 4) reservedWords.json: long admin sentences under a short key. A key missing
//    from the file would ship the raw key to the screen; an entry nothing uses
//    any more is dead weight printed on every admin page.
// ---------------------------------------------------------------------------
$reservedFile = $root . '/reservedWords.json';
$reserved = is_file($reservedFile) ? json_decode((string) file_get_contents($reservedFile), true) : [];
if (!is_array($reserved)) {
    $violations[] = ['file' => 'reservedWords.json', 'line' => 1, 'kind' => 'reservedWords.json is not valid JSON', 'code' => ''];
    $reserved = [];
}
foreach ($reservedUses as $key => $places) {
    if (!array_key_exists($key, $reserved)) {
        list($file, $line) = explode(':', $places[0]);
        $violations[] = ['file' => $file, 'line' => (int) $line, 'kind' => 'reserved key missing from reservedWords.json (the raw key would show)', 'code' => $key];
    }
}
foreach ($reservedOutside as $key => $places) {
    if (array_key_exists($key, $reserved)) {
        list($file, $line) = explode(':', $places[0]);
        $violations[] = ['file' => $file, 'line' => (int) $line, 'kind' => 'reserved key outside the admin bundles: this map has no reserved keys, the raw key would show', 'code' => $key];
    }
}
foreach ($reserved as $key => $sentence) {
    if (!isset($reservedUses[$key])) {
        $violations[] = ['file' => 'reservedWords.json', 'line' => 1, 'kind' => 'reservedWords.json entry no source uses', 'code' => $key];
    }
    if (!isset($adminKeys[$key])) {
        $violations[] = ['file' => 'app/Services/Translations/admin-translation.php', 'line' => 1, 'kind' => 'reserved key missing from the admin map: run npm run translate', 'code' => $key];
    }
}

echo 'translation-map-integrity: '
    . count($mapKeys) . ' map files, '
    . count($adminKeys) . ' admin keys, '
    . count($routeFiles) . " route files, {$scanned} source files\n";

if (!$violations) {
    echo "OK — map keys reachable, route titles translated and mapped, no template-literal msgids.\n";
    exit(0);
}

echo "\nFAIL — " . count($violations) . " violation(s):\n\n";
foreach ($violations as $v) {
    echo "  {$v['file']}:{$v['line']}\n";
    echo "    {$v['kind']}\n";
    echo '    ' . (strlen($v['code']) > 140 ? substr($v['code'], 0, 137) . '...' : $v['code']) . "\n\n";
}
echo "The admin SPA translates by exact-match lookup of the built source string.\n";
echo "Keys must be byte-identical to what the bundle looks up: plain quotes (no\n";
echo "\\\" or &quot;), no \${...}, numbered placeholders, and every route meta\n";
echo "title wrapped in translate() with a matching admin-translation.php entry.\n";
exit(1);
