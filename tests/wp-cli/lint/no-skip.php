<?php
/**
 * S0 lint — no test may skip, in the wp-cli gate or the Codeception suites.
 *
 * A skipped case reports green without running. Every case passes or fails: a
 * case that lacks a store record creates it, and a Pro-only case is not
 * registered under --axis=pro-absent.
 *
 * The scan covers tests/, without third-party and generated code.
 *
 * Usage:  php tests/wp-cli/lint/no-skip.php [path]
 * Exit:   0 clean, 1 skip call found
 */

$root = dirname(__DIR__, 3);
$scanDir = isset($argv[1]) && $argv[1] !== '' ? rtrim($argv[1], '/') : $root . '/tests';

$pattern = '/\b(?:FcTest::skip|markTestSkipped|markTestIncomplete)\s*\(/';
$self = realpath(__FILE__);
$violations = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scanDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php' || $file->getRealPath() === $self) {
        continue;
    }
    if (preg_match('#/(?:vendor|node_modules|_generated|_output)/#', $file->getPathname())) {
        continue;
    }
    foreach (file($file->getPathname()) as $index => $line) {
        if (preg_match($pattern, $line)) {
            $violations[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($index + 1) . '  ' . trim($line);
        }
    }
}

// A passing lint prints nothing, so anything it writes is an unexpected diagnostic.
if (!$violations) {
    exit(0);
}

echo 'no-skip: FAIL — ' . count($violations) . " skip call(s):\n";
foreach ($violations as $violation) {
    echo "  {$violation}\n";
}
echo "\nCreate the missing record inside the case and delete it by exact ID, or register the case only where its plugin loads.\n";
exit(1);
