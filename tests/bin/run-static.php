<?php
/**
 * S0 static gates in one WordPress process.
 *
 * Usage:
 *   wp eval-file tests/bin/run-static.php
 *   wp eval-file tests/bin/run-static.php -- full-php-lint=1
 */

require_once dirname(__DIR__) . '/lib/protected-tables.php';

$pluginDir = dirname(__DIR__, 2);
$config = require dirname(__DIR__) . '/suite.config.php';
$protectedTables = array_values($config['protected_tables']);
$baseline = FcProtectedTables::capture($protectedTables);
$results = [];

$fullPhpLint = false;
foreach (isset($args) && is_array($args) ? $args : [] as $argument) {
    if ($argument === 'full-php-lint=1' || $argument === '--full-php-lint') {
        $fullPhpLint = true;
    }
}

$hardAssertProtected = static function ($context) use ($baseline, $protectedTables) {
    try {
        return FcProtectedTables::assertUnchanged(
            $baseline,
            $protectedTables,
            $context
        );
    } catch (RuntimeException $e) {
        WP_CLI::log('HARD FAILURE — ' . $e->getMessage());
        WP_CLI::log('FC_PROTECTED_FAILURE=1');
        WP_CLI::halt(97);
    }
};

$runCommand = static function ($label, $command) use (
    &$results,
    $hardAssertProtected
) {
    $hardAssertProtected('before ' . $label);

    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    if ($output) {
        WP_CLI::log(implode("\n", $output));
    }

    $results[$label] = $code === 0 ? 0 : 1;
    $hardAssertProtected('after ' . $label);

    return [$code, $output];
};

$collectFullPhpFiles = static function () use ($pluginDir) {
    $files = [];
    foreach (['app', 'api', 'boot', 'database', 'tests'] as $relativeDir) {
        $directory = $pluginDir . '/' . $relativeDir;
        if (!is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (
                strpos($path, '/vendor/') !== false
                || strpos($path, 'scoped-vendor') !== false
                || strpos($path, '/Libs/csv/') !== false
                || strpos($path, '/Emogrifier/') !== false
            ) {
                continue;
            }
            $files[] = $path;
        }
    }

    sort($files);
    return $files;
};

$collectChangedPhpFiles = static function () use ($pluginDir) {
    $quotedPluginDir = escapeshellarg($pluginDir);
    $baseOutput = [];
    $baseCode = 0;
    exec(
        'git -C ' . $quotedPluginDir
        . " merge-base HEAD '@{upstream}' 2>/dev/null",
        $baseOutput,
        $baseCode
    );
    $base = $baseCode === 0 && isset($baseOutput[0])
        ? trim($baseOutput[0])
        : 'HEAD';

    $changed = [];
    $diffCode = 0;
    exec(
        'git -C ' . $quotedPluginDir
        . ' diff --name-only --diff-filter=ACMR '
        . escapeshellarg($base)
        . " -- '*.php'",
        $changed,
        $diffCode
    );
    if ($diffCode !== 0) {
        throw new RuntimeException('Could not discover changed PHP files.');
    }

    $untracked = [];
    $untrackedCode = 0;
    exec(
        'git -C ' . $quotedPluginDir
        . " ls-files --others --exclude-standard -- '*.php'",
        $untracked,
        $untrackedCode
    );
    if ($untrackedCode !== 0) {
        throw new RuntimeException('Could not discover untracked PHP files.');
    }

    $files = [];
    foreach (array_unique(array_merge($changed, $untracked)) as $relativePath) {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            continue;
        }

        $path = $pluginDir . '/' . $relativePath;
        if (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            $files[] = $path;
        }
    }

    sort($files);
    return $files;
};

WP_CLI::log(
    'protected run-start counts: ' . FcProtectedTables::format($baseline)
);

$hardAssertProtected('before php -l');
try {
    $phpFiles = $fullPhpLint
        ? $collectFullPhpFiles()
        : $collectChangedPhpFiles();
} catch (RuntimeException $e) {
    WP_CLI::log('php -l discovery failed: ' . $e->getMessage());
    $phpFiles = [];
    $results['php-l'] = 1;
}

$syntaxErrors = [];
if (!isset($results['php-l'])) {
    foreach ($phpFiles as $phpFile) {
        $lintOutput = [];
        $lintCode = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($phpFile) . ' 2>&1',
            $lintOutput,
            $lintCode
        );
        if ($lintCode !== 0) {
            $syntaxErrors[] = implode("\n", $lintOutput);
        }
    }

    if ($syntaxErrors) {
        WP_CLI::log(implode("\n", $syntaxErrors));
        $results['php-l'] = 1;
    } else {
        WP_CLI::log(sprintf(
            'php -l: clean (%s scan, %d files)',
            $fullPhpLint ? 'full' : 'changed',
            count($phpFiles)
        ));
        $results['php-l'] = 0;
    }
}
$hardAssertProtected('after php -l');

$phpBinary = escapeshellarg(PHP_BINARY);
$rawSqlLint = escapeshellarg($pluginDir . '/tests/lint/raw-sql-prefix.php');
$runCommand(
    'raw-sql-prefix',
    $phpBinary . ' ' . $rawSqlLint
);
$runCommand(
    'route-coverage',
    $phpBinary . ' ' . escapeshellarg($pluginDir . '/tests/lint/route-coverage.php')
);
$runCommand(
    'permission-inventory',
    $phpBinary . ' ' . escapeshellarg($pluginDir . '/tests/lint/permission-inventory.php')
);
$nameModeLint = escapeshellarg($pluginDir . '/tests/lint/name-mode-forms.php');
$runCommand(
    'name-mode-forms',
    $phpBinary . ' ' . $nameModeLint
);
$runCommand(
    'translation-map-integrity',
    $phpBinary . ' ' . escapeshellarg($pluginDir . '/tests/lint/translation-map-integrity.php')
);

$hardAssertProtected('before lint-self-test');
$fixtureOutput = [];
$fixtureCode = 0;
exec(
    $phpBinary . ' ' . $rawSqlLint . ' '
    . escapeshellarg($pluginDir . '/tests/lint/fixtures')
    . ' 2>&1',
    $fixtureOutput,
    $fixtureCode
);
$fixtureText = implode("\n", $fixtureOutput);
$requiredFixtureEvidence = [
    'FAIL — 7 violation(s)',
    'SELECT id FROM ' . $config['table_prefix'] . 'orders WHERE 1=0',
    'SELECT ' . $config['table_prefix'] . 'orders.id',
    'FROM ' . $config['table_prefix'] . 'orders',
];
$forbiddenFixtureEvidence = [
    '$ok1 =',
    '$ok2 =',
    '$ok3 =',
    'fixtureConcatenatedPrefixIsFine',
];
$fixtureProofOk = $fixtureCode === 1;
foreach ($requiredFixtureEvidence as $evidence) {
    $fixtureProofOk = $fixtureProofOk
        && strpos($fixtureText, $evidence) !== false;
}
foreach ($forbiddenFixtureEvidence as $evidence) {
    $fixtureProofOk = $fixtureProofOk
        && strpos($fixtureText, $evidence) === false;
}
$nameModeOutput = [];
$nameModeCode = 0;
exec(
    $phpBinary . ' ' . $nameModeLint . ' '
    . escapeshellarg($pluginDir . '/tests/lint/fixtures/name-mode')
    . ' 2>&1',
    $nameModeOutput,
    $nameModeCode
);
$nameModeText = implode("\n", $nameModeOutput);
// Both bug shapes must fire: a form with no split inputs at all, and one that
// mentions the guard token without ever branching on it.
$requiredNameModeEvidence = [
    'FAIL — 2 violation(s)',
    'bad-full-name-only.vue',
    'bad-token-without-branch.vue',
];
// A mode-aware form, a form that hoists the guard into a local, and a customer
// search box must never be reported.
$forbiddenNameModeEvidence = [
    'good-mode-aware.vue',
    'good-hoisted-guard.vue',
    'good-search-box.vue',
];
$nameModeProofOk = $nameModeCode === 1;
foreach ($requiredNameModeEvidence as $evidence) {
    $nameModeProofOk = $nameModeProofOk
        && strpos($nameModeText, $evidence) !== false;
}
foreach ($forbiddenNameModeEvidence as $evidence) {
    $nameModeProofOk = $nameModeProofOk
        && strpos($nameModeText, $evidence) === false;
}

if ($fixtureProofOk && $nameModeProofOk) {
    WP_CLI::log(
        'lint self-test: all raw-prefix and name-mode bug shapes fire and false-positive guards stay clean'
    );
    $results['lint-self-test'] = 0;
} else {
    if (!$fixtureProofOk) {
        WP_CLI::log($fixtureText);
    }
    if (!$nameModeProofOk) {
        WP_CLI::log($nameModeText);
    }
    WP_CLI::log(
        'lint self-test FAILED — fixture coverage or false-positive guards regressed'
    );
    $results['lint-self-test'] = 1;
}
$hardAssertProtected('after lint-self-test');

$markerKeys = [
    'php-l',
    'raw-sql-prefix',
    'route-coverage',
    'permission-inventory',
    'name-mode-forms',
    'translation-map-integrity',
    'lint-self-test',
];
foreach ($markerKeys as $markerKey) {
    WP_CLI::log(
        'FC_STATIC_RESULT ' . $markerKey . '=' . $results[$markerKey]
    );
}

$hardAssertProtected('static tier end');
WP_CLI::log(
    'protected run-end counts: '
    . FcProtectedTables::format(FcProtectedTables::capture($protectedTables))
);

WP_CLI::halt(array_sum($results) === 0 ? 0 : 1);
