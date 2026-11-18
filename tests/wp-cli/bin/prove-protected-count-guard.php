<?php
/**
 * Intentional-red proof that the run-local protected-table guard fires.
 *
 * The uniquely owned Customer exists only inside the try/finally boundary.
 * A successful proof exits 97 after cleanup.
 */

require_once dirname(__DIR__) . '/lib/protected-tables.php';
require_once dirname(__DIR__) . '/lib/fixtures.php';

$config = require dirname(__DIR__) . '/suite.config.php';
$protectedTables = array_values($config['protected_tables']);
$identity = 'wp-plugin-phase7-protected-guard-'
    . strtolower(wp_generate_password(12, false, false))
    . '@example.invalid';

FcFixture::initialize($identity);
$baseline = FcProtectedTables::capture($protectedTables);
$detected = null;
$portableCounts = [];
$portableResult = null;
$portableFile = tempnam(sys_get_temp_dir(), 'fc-static');

try {
    FcFixture::customer();
    $portableCounts = FcProtectedTables::capture($protectedTables);
    // The static tier reports its start and end counts through a result file, not stdout.
    $portableResult = WP_CLI::runcommand(
        'eval-file ' . escapeshellarg(dirname(__DIR__) . '/bin/run-static.php')
            . ' -- result-file=' . escapeshellarg($portableFile),
        ['return' => 'all', 'exit_error' => false]
    );

    try {
        FcProtectedTables::assertUnchanged(
            $baseline,
            $protectedTables,
            'intentional protected-count proof'
        );
    } catch (RuntimeException $e) {
        $detected = $e;
    }
} finally {
    FcFixture::cleanupAll();
}

$restored = FcProtectedTables::capture($protectedTables);
if ($restored !== $baseline) {
    WP_CLI::error(
        'Protected-count proof cleanup failed: start='
        . wp_json_encode($baseline)
        . ' end=' . wp_json_encode($restored)
    );
}
$portableCode = (int) $portableResult->return_code;
$portableText = (string) file_get_contents($portableFile);
unlink($portableFile);
if ($portableCode !== 0) {
    WP_CLI::log($portableResult->stdout . $portableResult->stderr);
    WP_CLI::error(
        'Portable-baseline proof tier failed with exit ' . $portableCode . '.'
    );
}
$portableCountText = FcProtectedTables::format($portableCounts);
if (
    strpos($portableText, 'protected_start=' . $portableCountText . "\n") === false
    || strpos($portableText, 'protected_end=' . $portableCountText . "\n") === false
) {
    WP_CLI::log($portableText);
    WP_CLI::error(
        'Portable-baseline proof did not observe the shifted counts at both boundaries.'
    );
}
if ($detected === null) {
    WP_CLI::error('Protected-count proof failed to detect the inserted Customer.');
}

WP_CLI::log(
    'portable baseline proof passed: ' . $portableCountText
    . ' tier_exit=' . $portableCode
);
WP_CLI::log('HARD FAILURE — ' . $detected->getMessage());
WP_CLI::log(
    'proof cleanup restored counts: ' . FcProtectedTables::format($restored)
);
WP_CLI::halt(97);
