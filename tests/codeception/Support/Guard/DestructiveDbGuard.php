<?php

namespace Tests\Support\Guard;

use lucatume\WPBrowser\WordPress\Installation;
use RuntimeException;
use Throwable;

/**
 * TC-047 — in-process destructive-DB preflight guard.
 *
 * The Integration/Functional suites install WordPress into the configured
 * test database and truncate every fct_* table. Nothing may reach that
 * database unless the operator has named the EXACT `host:database` to
 * sacrifice in the FCT_TEST_DB_ALLOW environment variable.
 *
 * This is the terminal guard the JS wrappers (bin/lib/db-guard.mjs) defer
 * to: it validates the values Codeception ACTUALLY resolved — post-params,
 * post-override, inside the process about to connect — so no .env parsing
 * approximation exists to bypass. It is invoked from two places:
 *
 *  - DestructiveDbGuardExtension on MODULE_INIT, before WPLoader's
 *    _initialize() opens any DB connection (covers bare
 *    `vendor/bin/codecept run`), and
 *  - the truncating/dropping helpers themselves (resetFluentCartTables(),
 *    realDropAllFctTables()), re-checked against the LIVE connection.
 *
 * The opt-in is read with getenv() only: Codeception's params loader writes
 * .env values into $_ENV/$_SERVER (phpdotenv default adapters) but
 * never into the real process environment, so an FCT_TEST_DB_ALLOW line
 * stashed in .env cannot satisfy the guard — only the operator's
 * shell can.
 */
class DestructiveDbGuard
{
    public const ENV_VAR = 'FCT_TEST_DB_ALLOW';

    /**
     * Abort unless FCT_TEST_DB_ALLOW names exactly the host:database this
     * run is about to destroy.
     *
     * @param string $host    Resolved DB host (WPLoader `dbHost` or live DB_HOST).
     * @param string $name    Resolved DB name (WPLoader `dbName` or live DB_NAME).
     * @param string $context Human-readable call site for the abort message.
     */
    public static function assertDbAllowed(string $host, string $name, string $context): void
    {
        // A target that still carries placeholder syntax or whitespace was
        // never resolved — matching against it would prove nothing.
        foreach (['host' => $host, 'database' => $name] as $label => $value) {
            if ($value === '' || preg_match('/[\s${}%]/', $value)) {
                self::abort($context, sprintf(
                    "The resolved DB %s ('%s') is empty or unresolved — refusing to guess the destructive target.",
                    $label,
                    $value
                ));
            }
        }

        $expected = $host . ':' . $name;
        $allow = getenv(self::ENV_VAR);

        if ($allow !== $expected) {
            self::abort($context, sprintf(
                "This run TRUNCATES every fct_* table in `%s`. Name the exact sacrificial database to proceed:\n\n" .
                "    %s=\"%s\" vendor/bin/codecept run ...\n\n" .
                "Only set this for a database whose data you are willing to destroy.",
                $expected,
                self::ENV_VAR,
                $expected
            ));
        }
    }

    /**
     * Abort unless the FluentCart entrypoint WPLoader is about to load IS
     * this repository's fluent-cart.php. A .env pointing at another
     * WordPress install with a stale FluentCart copy would otherwise produce
     * verdicts about code that never ran.
     *
     * @param array<string,mixed> $wpLoaderConfig The WPLoader module's validated config.
     * @param string              $context        Human-readable call site for the abort message.
     */
    public static function assertPluginIdentity(array $wpLoaderConfig, string $context): void
    {
        $repoEntrypoint = realpath(dirname(__DIR__, 4) . '/fluent-cart.php');
        if ($repoEntrypoint === false) {
            self::abort($context, 'Cannot locate this repository\'s fluent-cart.php — the guard itself is misplaced.');
        }

        $pluginsDir = self::resolvePluginsDir($wpLoaderConfig, $context);

        $entrypoints = [];
        foreach ((array)($wpLoaderConfig['plugins'] ?? []) as $plugin) {
            if (basename((string)$plugin) === 'fluent-cart.php') {
                $entrypoints[] = (string)$plugin;
            }
        }

        if ($entrypoints === []) {
            self::abort($context, 'No fluent-cart.php entrypoint is configured in WPLoader `plugins` — refusing to run a FluentCart suite that does not load FluentCart.');
        }

        foreach ($entrypoints as $entry) {
            $resolved = realpath(rtrim($pluginsDir, '\\/') . '/' . ltrim($entry, '\\/'));
            if ($resolved !== $repoEntrypoint) {
                self::abort($context, sprintf(
                    "WPLoader is about to load a FluentCart copy that is NOT this repository:\n\n" .
                    "    configured: %s\n" .
                    "    resolves to: %s\n" .
                    "    this repo:   %s\n\n" .
                    "Point WORDPRESS_ROOT_DIR / FLUENT_CART in .env at the WordPress install whose plugins dir contains THIS working copy.",
                    $entry,
                    $resolved === false ? '(missing file)' : $resolved,
                    $repoEntrypoint
                ));
            }
        }
    }

    /**
     * Plugins dir exactly as WPLoader::getPluginsFolder() will resolve it:
     * module config WP_PLUGIN_DIR, then pluginsFolder, then
     * WP_CONTENT_DIR/plugins, then the installation's own directory layout
     * (which honors wp-config.php constants). Filesystem-only — the
     * Installation is created with $checkDb = false, same as WPLoader's.
     */
    private static function resolvePluginsDir(array $wpLoaderConfig, string $context): string
    {
        if (!empty($wpLoaderConfig['WP_PLUGIN_DIR'])) {
            return (string)$wpLoaderConfig['WP_PLUGIN_DIR'];
        }
        if (!empty($wpLoaderConfig['pluginsFolder'])) {
            return (string)$wpLoaderConfig['pluginsFolder'];
        }
        if (!empty($wpLoaderConfig['WP_CONTENT_DIR'])) {
            return rtrim((string)$wpLoaderConfig['WP_CONTENT_DIR'], '\\/') . '/plugins';
        }

        $wpRootFolder = (string)($wpLoaderConfig['wpRootFolder'] ?? '');
        if ($wpRootFolder === '') {
            self::abort($context, 'WPLoader config carries no wpRootFolder — cannot verify which FluentCart copy would load.');
        }

        try {
            return (new Installation($wpRootFolder, false))->getPluginsDir();
        } catch (Throwable $e) {
            self::abort($context, sprintf(
                "Could not inspect the WordPress installation at '%s' to verify the FluentCart copy (%s) — refusing to run against an unverifiable install.",
                $wpRootFolder,
                $e->getMessage()
            ));
        }
    }

    private static function abort(string $context, string $reason): never
    {
        throw new RuntimeException(sprintf(
            "\n⛔ Destructive-DB guard [%s]\n\n%s\n",
            $context,
            $reason
        ));
    }
}
