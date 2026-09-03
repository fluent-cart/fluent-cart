<?php
/**
 * Static GET-surface coverage lint.
 *
 * Reads configured route source files and the Phase 1 smoke manifest as plain
 * PHP. It never boots WordPress or invokes a route.
 */

$startedAt = microtime(true);
$pluginDir = dirname(__DIR__, 2);
$configFile = $pluginDir . '/tests/suite.config.php';
$manifestFile = $pluginDir . '/tests/smoke/routes.manifest.php';
$errors = [];

$addError = static function ($location, $reason) use (&$errors) {
    $errors[] = [
        'location' => (string) $location,
        'reason'   => (string) $reason,
    ];
};

if (!is_file($configFile)) {
    fwrite(STDERR, "route-coverage: missing config: tests/suite.config.php\n");
    exit(1);
}

$config = require $configFile;
if (!is_array($config)) {
    fwrite(STDERR, "route-coverage: tests/suite.config.php must return an array\n");
    exit(1);
}

$routeFiles = isset($config['routes_files']) && is_array($config['routes_files'])
    ? array_values($config['routes_files'])
    : [];
$routeFileTypes = isset($config['route_file_types']) && is_array($config['route_file_types'])
    ? $config['route_file_types']
    : [];

if (!$routeFiles) {
    $addError('tests/suite.config.php', 'routes_files must configure at least one route source file');
}

$seenConfiguredFiles = [];
foreach ($routeFiles as $index => $file) {
    if (!is_string($file) || $file === '') {
        $addError(
            'tests/suite.config.php',
            'routes_files[' . $index . '] must be a non-empty relative path'
        );
        continue;
    }
    if (isset($seenConfiguredFiles[$file])) {
        $addError('tests/suite.config.php', 'duplicate configured route file: ' . $file);
    }
    $seenConfiguredFiles[$file] = true;
    if (!isset($routeFileTypes[$file])) {
        $addError(
            'tests/suite.config.php',
            'route_file_types is missing configured file: ' . $file
        );
    } elseif (!in_array($routeFileTypes[$file], ['rest', 'web', 'faker'], true)) {
        $addError(
            'tests/suite.config.php',
            'unsupported route_file_types value for ' . $file . ': '
                . var_export($routeFileTypes[$file], true)
        );
    }
}
foreach ($routeFileTypes as $file => $type) {
    if (!isset($seenConfiguredFiles[$file])) {
        $addError(
            'tests/suite.config.php',
            'route_file_types contains stale unconfigured file: ' . $file
        );
    }
}

$decodeLiteral = static function ($literal) {
    if (!is_string($literal) || strlen($literal) < 2) {
        return null;
    }

    $quote = $literal[0];
    if (($quote !== "'" && $quote !== '"') || substr($literal, -1) !== $quote) {
        return null;
    }

    $body = substr($literal, 1, -1);
    if ($quote === "'") {
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
    }

    return stripcslashes($body);
};

$tokenize = static function ($source) {
    $rawTokens = token_get_all($source);
    $tokens = [];
    $currentLine = 1;

    foreach ($rawTokens as $rawToken) {
        if (is_array($rawToken)) {
            $text = $rawToken[1];
            $line = $rawToken[2];
            $tokens[] = [
                'id'   => $rawToken[0],
                'text' => $text,
                'line' => $line,
            ];
            $currentLine = $line + substr_count($text, "\n");
        } else {
            $tokens[] = [
                'id'   => null,
                'text' => $rawToken,
                'line' => $currentLine,
            ];
            $currentLine += substr_count($rawToken, "\n");
        }
    }

    return $tokens;
};

$nextSignificant = static function (array $tokens, $index) {
    $count = count($tokens);
    for ($i = $index + 1; $i < $count; $i++) {
        if (
            $tokens[$i]['id'] === T_WHITESPACE
            || $tokens[$i]['id'] === T_COMMENT
            || $tokens[$i]['id'] === T_DOC_COMMENT
        ) {
            continue;
        }
        return $i;
    }
    return null;
};

$routerMethodAt = static function (array $tokens, $index, $method) use ($nextSignificant) {
    if (
        !isset($tokens[$index])
        || $tokens[$index]['id'] !== T_VARIABLE
        || $tokens[$index]['text'] !== '$router'
    ) {
        return null;
    }

    $operator = $nextSignificant($tokens, $index);
    $name = $operator === null ? null : $nextSignificant($tokens, $operator);
    if (
        $operator === null
        || $name === null
        || $tokens[$operator]['text'] !== '->'
        || $tokens[$name]['id'] !== T_STRING
        || strtolower($tokens[$name]['text']) !== strtolower($method)
    ) {
        return null;
    }

    return $name;
};

$matchingBraces = static function (array $tokens) {
    $stack = [];
    $matches = [];
    foreach ($tokens as $index => $token) {
        if ($token['text'] === '{') {
            $stack[] = $index;
        } elseif ($token['text'] === '}' && $stack) {
            $open = array_pop($stack);
            $matches[$open] = $index;
        }
    }
    return $matches;
};

$joinRoute = static function (array $parts) {
    $clean = [];
    foreach ($parts as $part) {
        $part = trim((string) $part, '/');
        if ($part !== '') {
            $clean[] = $part;
        }
    }
    return implode('/', $clean);
};

$extractRest = static function (
    $relativeFile,
    $source
) use (
    $tokenize,
    $nextSignificant,
    $routerMethodAt,
    $matchingBraces,
    $decodeLiteral,
    $joinRoute,
    $addError
) {
    $tokens = $tokenize($source);
    $braceMatches = $matchingBraces($tokens);
    $prefixRanges = [];

    foreach ($tokens as $index => $token) {
        $methodIndex = $routerMethodAt($tokens, $index, 'prefix');
        if ($methodIndex === null) {
            continue;
        }

        $openParen = $nextSignificant($tokens, $methodIndex);
        $literalIndex = $openParen === null ? null : $nextSignificant($tokens, $openParen);
        $prefix = $literalIndex === null
            ? null
            : $decodeLiteral($tokens[$literalIndex]['text']);
        if (
            $openParen === null
            || $tokens[$openParen]['text'] !== '('
            || $prefix === null
        ) {
            $addError(
                $relativeFile . ':' . $token['line'],
                'unsupported dynamic router prefix; route coverage requires a literal prefix'
            );
            continue;
        }

        $sawGroup = false;
        $sawFunction = false;
        $groupOpen = null;
        $tokenCount = count($tokens);
        for ($cursor = $literalIndex + 1; $cursor < $tokenCount; $cursor++) {
            if ($tokens[$cursor]['text'] === ';') {
                break;
            }
            if (
                $tokens[$cursor]['id'] === T_STRING
                && strtolower($tokens[$cursor]['text']) === 'group'
            ) {
                $sawGroup = true;
            } elseif ($tokens[$cursor]['id'] === T_FUNCTION) {
                $sawFunction = true;
            } elseif (
                $tokens[$cursor]['text'] === '{'
                && $sawGroup
                && $sawFunction
            ) {
                $groupOpen = $cursor;
                break;
            }
        }

        if ($groupOpen === null || !isset($braceMatches[$groupOpen])) {
            $addError(
                $relativeFile . ':' . $token['line'],
                'literal router prefix is not attached to a statically bounded group'
            );
            continue;
        }

        $prefixRanges[] = [
            'prefix' => $prefix,
            'open'   => $groupOpen,
            'close'  => $braceMatches[$groupOpen],
        ];
    }

    usort($prefixRanges, static function ($left, $right) {
        return $left['open'] <=> $right['open'];
    });

    $surfaces = [];
    foreach ($tokens as $index => $token) {
        $methodIndex = $routerMethodAt($tokens, $index, 'get');
        if ($methodIndex === null) {
            continue;
        }

        $openParen = $nextSignificant($tokens, $methodIndex);
        $literalIndex = $openParen === null ? null : $nextSignificant($tokens, $openParen);
        $route = $literalIndex === null
            ? null
            : $decodeLiteral($tokens[$literalIndex]['text']);
        if (
            $openParen === null
            || $tokens[$openParen]['text'] !== '('
            || $route === null
        ) {
            $addError(
                $relativeFile . ':' . $token['line'],
                'unsupported dynamic GET declaration; first argument must be a literal route'
            );
            continue;
        }

        $parts = [];
        foreach ($prefixRanges as $range) {
            if ($index > $range['open'] && $index < $range['close']) {
                $parts[] = $range['prefix'];
            }
        }
        $parts[] = $route;

        $surfaces[] = [
            'id'          => $relativeFile . ':' . $token['line'],
            'source_file' => $relativeFile,
            'source_line' => $token['line'],
            'route'       => $joinRoute($parts),
            'transport'   => 'rest',
        ];
    }

    return $surfaces;
};

$extractPageBranches = static function (
    $relativeFile,
    $source,
    $transport,
    $includeConditionals
) use (
    $tokenize,
    $nextSignificant,
    $matchingBraces,
    $decodeLiteral,
    $addError
) {
    $tokens = $tokenize($source);
    $braceMatches = $matchingBraces($tokens);
    $pageSwitches = [];

    foreach ($tokens as $index => $token) {
        if ($token['id'] !== T_SWITCH) {
            continue;
        }

        $hasPage = false;
        $open = null;
        $tokenCount = count($tokens);
        for ($cursor = $index + 1; $cursor < $tokenCount; $cursor++) {
            if (
                $tokens[$cursor]['id'] === T_VARIABLE
                && $tokens[$cursor]['text'] === '$page'
            ) {
                $hasPage = true;
            }
            if ($tokens[$cursor]['text'] === '{') {
                $open = $cursor;
                break;
            }
            if ($tokens[$cursor]['text'] === ';') {
                break;
            }
        }
        if ($hasPage && $open !== null && isset($braceMatches[$open])) {
            $pageSwitches[] = ['open' => $open, 'close' => $braceMatches[$open]];
        }
    }

    $surfaces = [];
    foreach ($tokens as $index => $token) {
        if ($token['id'] === T_CASE) {
            $inPageSwitch = false;
            foreach ($pageSwitches as $range) {
                if ($index > $range['open'] && $index < $range['close']) {
                    $inPageSwitch = true;
                    break;
                }
            }
            if (!$inPageSwitch) {
                continue;
            }

            $literalIndex = $nextSignificant($tokens, $index);
            $page = $literalIndex === null
                ? null
                : $decodeLiteral($tokens[$literalIndex]['text']);
            if ($page === null) {
                $addError(
                    $relativeFile . ':' . $token['line'],
                    'unsupported dynamic page case; route coverage requires a literal case value'
                );
                continue;
            }
            $surfaces[] = [
                'id'          => $relativeFile . ':' . $token['line'],
                'source_file' => $relativeFile,
                'source_line' => $token['line'],
                'route'       => '?fluent-cart=' . $page,
                'transport'   => $transport,
            ];
        }

        if (
            $includeConditionals
            && $token['id'] === T_VARIABLE
            && $token['text'] === '$page'
        ) {
            $operator = $nextSignificant($tokens, $index);
            $literalIndex = $operator === null
                ? null
                : $nextSignificant($tokens, $operator);
            if (
                $operator === null
                || $literalIndex === null
                || $tokens[$operator]['id'] !== T_IS_IDENTICAL
            ) {
                continue;
            }
            $page = $decodeLiteral($tokens[$literalIndex]['text']);
            if ($page === null) {
                $addError(
                    $relativeFile . ':' . $token['line'],
                    'unsupported dynamic page comparison; route coverage requires a literal value'
                );
                continue;
            }
            $surfaces[] = [
                'id'          => $relativeFile . ':' . $token['line'],
                'source_file' => $relativeFile,
                'source_line' => $token['line'],
                'route'       => '?fluent-cart=' . $page,
                'transport'   => $transport,
            ];
        }
    }

    usort($surfaces, static function ($left, $right) {
        return $left['source_line'] <=> $right['source_line'];
    });

    return $surfaces;
};

$stats = [];
$discovered = [];
foreach ($routeFiles as $relativeFile) {
    $stats[$relativeFile] = [
        'source'     => 0,
        'manifest'   => 0,
        'cases'      => 0,
        'variations' => 0,
    ];

    $absoluteFile = $pluginDir . '/' . $relativeFile;
    if (!is_file($absoluteFile)) {
        $addError($relativeFile, 'configured route source file is missing');
        continue;
    }

    $source = file_get_contents($absoluteFile);
    if ($source === false) {
        $addError($relativeFile, 'configured route source file cannot be read');
        continue;
    }

    $type = isset($routeFileTypes[$relativeFile]) ? $routeFileTypes[$relativeFile] : null;
    if ($type === 'rest') {
        $surfaces = $extractRest($relativeFile, $source);
    } elseif ($type === 'web') {
        $surfaces = $extractPageBranches($relativeFile, $source, 'web', true);
    } elseif ($type === 'faker') {
        $surfaces = $extractPageBranches($relativeFile, $source, 'faker', false);
    } else {
        $surfaces = [];
    }

    foreach ($surfaces as $surface) {
        $stats[$relativeFile]['source']++;
        if (isset($discovered[$surface['id']])) {
            $addError(
                $surface['id'],
                'duplicate source declaration ID; first surface is GET /'
                    . $discovered[$surface['id']]['route']
                    . ', duplicate is GET /' . $surface['route']
            );
            continue;
        }
        $discovered[$surface['id']] = $surface;
    }
}

$manifest = null;
if (!is_file($manifestFile)) {
    $addError('tests/smoke/routes.manifest.php', 'smoke manifest file is missing');
} else {
    try {
        $manifest = require $manifestFile;
    } catch (Throwable $exception) {
        $addError(
            'tests/smoke/routes.manifest.php:' . $exception->getLine(),
            'manifest could not be loaded: ' . get_class($exception)
                . ': ' . $exception->getMessage()
        );
    }
}

if (
    !is_array($manifest)
    || !isset($manifest['route_files'], $manifest['declarations'], $manifest['cases'])
    || !is_array($manifest['route_files'])
    || !is_array($manifest['declarations'])
    || !is_array($manifest['cases'])
) {
    $addError(
        'tests/smoke/routes.manifest.php',
        'manifest must return route_files, declarations, and cases arrays'
    );
    $manifest = [
        'route_files'  => [],
        'declarations' => [],
        'cases'        => [],
    ];
}

$manifestRouteFiles = array_values($manifest['route_files']);
if ($manifestRouteFiles !== $routeFiles) {
    $missingFromManifestConfig = array_values(array_diff($routeFiles, $manifestRouteFiles));
    $staleManifestConfig = array_values(array_diff($manifestRouteFiles, $routeFiles));
    if ($missingFromManifestConfig) {
        $addError(
            'tests/smoke/routes.manifest.php',
            'manifest route_files omits configured file(s): '
                . implode(', ', $missingFromManifestConfig)
        );
    }
    if ($staleManifestConfig) {
        $addError(
            'tests/smoke/routes.manifest.php',
            'manifest route_files contains stale file(s): '
                . implode(', ', $staleManifestConfig)
        );
    }
    if (!$missingFromManifestConfig && !$staleManifestConfig) {
        $addError(
            'tests/smoke/routes.manifest.php',
            'manifest route_files order or duplicate entries differ from suite.config.php'
        );
    }
}

$declarations = [];
foreach ($manifest['declarations'] as $index => $declaration) {
    $location = 'tests/smoke/routes.manifest.php declaration #' . ($index + 1);
    if (!is_array($declaration)) {
        $addError($location, 'manifest declaration must be an array');
        continue;
    }

    $id = isset($declaration['id']) ? (string) $declaration['id'] : '';
    $file = isset($declaration['source_file']) ? (string) $declaration['source_file'] : '';
    $line = isset($declaration['source_line']) ? (int) $declaration['source_line'] : 0;
    $route = isset($declaration['route']) ? ltrim((string) $declaration['route'], '/') : '';
    $transport = isset($declaration['transport']) ? (string) $declaration['transport'] : '';
    $metadataId = $file !== '' && $line > 0 ? $file . ':' . $line : '';
    if ($metadataId !== '') {
        $location = $metadataId;
    }

    if ($id === '' || $file === '' || $line <= 0 || $transport === '') {
        $addError($location, 'manifest declaration is missing required ID/source/transport metadata');
        continue;
    }
    if ($id !== $metadataId) {
        $addError(
            $location,
            'declaration ID mismatch: id=' . $id . ', source metadata=' . $metadataId
        );
    }
    if (isset($declarations[$id])) {
        $addError($location, 'duplicate manifest declaration ID: ' . $id);
        continue;
    }

    $declarations[$id] = [
        'id'          => $id,
        'source_file' => $file,
        'source_line' => $line,
        'route'       => $route,
        'transport'   => $transport,
    ];

    if (!isset($stats[$file])) {
        $addError(
            $location,
            'stale manifest declaration references unconfigured route file: ' . $file
        );
    } else {
        $stats[$file]['manifest']++;
    }

    if (!isset($discovered[$id])) {
        $addError(
            $location,
            'stale manifest declaration: no source GET exists at exact ID '
                . $id . ' (manifest GET /' . $route . ')'
        );
        continue;
    }

    $sourceSurface = $discovered[$id];
    if ($route !== $sourceSurface['route']) {
        $addError(
            $location,
            'route mismatch for ' . $id . ': source GET /' . $sourceSurface['route']
                . ', manifest GET /' . $route
        );
    }
    if ($transport !== $sourceSurface['transport']) {
        $addError(
            $location,
            'transport mismatch for ' . $id . ': source='
                . $sourceSurface['transport'] . ', manifest=' . $transport
        );
    }
}

foreach ($discovered as $id => $surface) {
    if (!isset($declarations[$id])) {
        $addError(
            $id,
            'missing manifest declaration: source GET /' . $surface['route']
                . ' has ID ' . $id
        );
    }
}

$caseCounts = [];
$consumerLineCounts = [];
$routeMatchesDeclaration = static function ($declarationRoute, $caseRoute) {
    if ($declarationRoute === $caseRoute) {
        return true;
    }

    $patternParts = [];
    foreach (explode('/', $declarationRoute) as $part) {
        if (preg_match('/^\{[^{}]+\}$/', $part)) {
            $patternParts[] = '[^/]+';
        } else {
            $patternParts[] = preg_quote($part, '~');
        }
    }

    return preg_match('~^' . implode('/', $patternParts) . '$~', $caseRoute) === 1;
};
foreach ($manifest['cases'] as $index => $case) {
    $caseNumber = $index + 1;
    $location = 'tests/smoke/routes.manifest.php case #' . $caseNumber;
    if (!is_array($case)) {
        $addError($location, 'manifest case must be an array');
        continue;
    }

    $declarationId = isset($case['declaration_id'])
        ? (string) $case['declaration_id']
        : '';
    $file = isset($case['source_file']) ? (string) $case['source_file'] : '';
    $line = isset($case['source_line']) ? (int) $case['source_line'] : 0;
    if ($file !== '' && $line > 0) {
        $location = $file . ':' . $line . ' case #' . $caseNumber;
    }

    if ($declarationId === '' || !isset($declarations[$declarationId])) {
        $addError(
            $location,
            'case references unknown declaration ID: '
                . ($declarationId !== '' ? $declarationId : '(missing)')
        );
        continue;
    }

    $declaration = $declarations[$declarationId];
    $caseCounts[$declarationId] = isset($caseCounts[$declarationId])
        ? $caseCounts[$declarationId] + 1
        : 1;
    if (isset($stats[$declaration['source_file']])) {
        $stats[$declaration['source_file']]['cases']++;
    }

    if ($file !== $declaration['source_file'] || $line !== $declaration['source_line']) {
        $addError(
            $location,
            'case source metadata does not match declaration ' . $declarationId
                . ': expected ' . $declaration['source_file'] . ':'
                . $declaration['source_line']
        );
    }

    $caseRoute = isset($case['route']) ? ltrim((string) $case['route'], '/') : '';
    if (!$routeMatchesDeclaration($declaration['route'], $caseRoute)) {
        $addError(
            $location,
            'case route does not match declaration shape ' . $declarationId
                . ': expected GET /' . $declaration['route']
                . ', case GET /' . $caseRoute
        );
    }

    $caseTransport = isset($case['transport']) ? (string) $case['transport'] : '';
    if ($caseTransport !== $declaration['transport']) {
        $addError(
            $location,
            'case transport does not match declaration ' . $declarationId
                . ': expected ' . $declaration['transport']
                . ', case=' . ($caseTransport !== '' ? $caseTransport : '(missing)')
        );
    }
    if ($declaration['transport'] !== 'rest' && empty($case['skip'])) {
        $addError(
            $location,
            'non-REST case is executable: transport=' . $declaration['transport']
                . ' requires a precise skip reason'
        );
    }

    if (!empty($case['variation'])) {
        if (isset($stats[$declaration['source_file']])) {
            $stats[$declaration['source_file']]['variations']++;
        }
        $consumerFile = isset($case['consumer_file']) ? (string) $case['consumer_file'] : '';
        $consumerLine = isset($case['consumer_line']) ? (int) $case['consumer_line'] : 0;
        if ($consumerFile === '' || $consumerLine <= 0) {
            $addError(
                $location,
                'variation case lacks consumer file:line evidence for declaration '
                    . $declarationId
            );
            continue;
        }

        if ($consumerFile[0] === '/' || strpos($consumerFile, '../') !== false) {
            $addError(
                $location,
                'variation consumer path must stay inside the plugin: ' . $consumerFile
            );
            continue;
        }

        $consumerAbsolute = $pluginDir . '/' . $consumerFile;
        if (!is_file($consumerAbsolute)) {
            $addError(
                $consumerFile . ':' . $consumerLine,
                'variation consumer evidence file is missing for declaration '
                    . $declarationId
            );
            continue;
        }

        if (!isset($consumerLineCounts[$consumerFile])) {
            $consumerLines = file($consumerAbsolute);
            $consumerLineCounts[$consumerFile] = is_array($consumerLines)
                ? count($consumerLines)
                : 0;
        }
        if ($consumerLine > $consumerLineCounts[$consumerFile]) {
            $addError(
                $consumerFile . ':' . $consumerLine,
                'variation consumer evidence line is outside the file ('
                    . $consumerLineCounts[$consumerFile] . ' lines) for declaration '
                    . $declarationId
            );
        }
    }
}

foreach ($declarations as $id => $declaration) {
    if (empty($caseCounts[$id])) {
        $addError($id, 'manifest declaration has no smoke case: ' . $id);
    }
}

$sourceTotal = count($discovered);
$manifestTotal = count($declarations);
$caseTotal = count($manifest['cases']);
$runtime = number_format(microtime(true) - $startedAt, 3, '.', '');

echo 'route-coverage: configured ' . count($routeFiles)
    . ' files; discovered ' . $sourceTotal
    . ' GET surfaces; manifest ' . $manifestTotal
    . ' declarations, ' . $caseTotal . " cases\n";
foreach ($stats as $file => $fileStats) {
    echo sprintf(
        "  %s source=%d manifest=%d cases=%d variations=%d\n",
        $file,
        $fileStats['source'],
        $fileStats['manifest'],
        $fileStats['cases'],
        $fileStats['variations']
    );
}

if ($errors) {
    echo "\nFAIL — " . count($errors) . " route coverage violation(s):\n\n";
    foreach ($errors as $error) {
        echo '  ' . $error['location'] . "\n";
        echo '    ' . $error['reason'] . "\n\n";
    }
    echo 'route-coverage runtime: ' . $runtime . "s\n";
    exit(1);
}

echo "\nOK — every configured GET surface has an exact manifest declaration and case; "
    . 'manifest references are internally consistent.' . "\n";
echo 'route-coverage runtime: ' . $runtime . "s\n";
exit(0);
