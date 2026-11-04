<?php

namespace Tests\Support\Extension;

use Codeception\Event\SuiteEvent;
use Codeception\Events;
use Codeception\Extension;
use lucatume\WPBrowser\Module\WPLoader;
use Tests\Support\Guard\DestructiveDbGuard;

/**
 * TC-047 — fires the destructive-DB preflight before WPLoader can connect.
 *
 * Ordering proof, from vendor sources (codeception 5.3.5 / wp-browser
 * 4.5.15): SuiteManager::initialize() dispatches Events::MODULE_INIT to all
 * listeners BEFORE calling any module's _initialize()
 * (vendor/codeception/codeception/src/Codeception/SuiteManager.php:56-61),
 * and WPLoader opens its first DB connection — and, with loadOnly:false,
 * installs WordPress — inside _initialize()
 * (vendor/lucatume/wp-browser/src/Module/WPLoader.php:461,552). A throw
 * here therefore aborts the run with zero connections to the target.
 *
 * At MODULE_INIT the modules are already constructed and validated
 * (SuiteManager::__construct), so $module->_getConfig() returns the values
 * WPLoader will actually use — dbUrl/DB_* precedence included — with no
 * re-parsing. Suites without WPLoader (Unit) pass through untouched.
 *
 * Registered globally in codeception.yml `extensions.enabled`, so bare
 * `vendor/bin/codecept run` is covered, not just the bin/*.mjs wrappers.
 */
class DestructiveDbGuardExtension extends Extension
{
    /**
     * @var array<string,string>
     */
    public static array $events = [
        Events::MODULE_INIT => 'preflight',
    ];

    public function preflight(SuiteEvent $event): void
    {
        $suite = $event->getSuite();
        if ($suite === null) {
            return;
        }

        $context = sprintf('suite %s, before WPLoader init', $suite->getBaseName());

        foreach ($suite->getModules() as $module) {
            if (!$module instanceof WPLoader) {
                continue;
            }

            $config = (array)$module->_getConfig();

            DestructiveDbGuard::assertDbAllowed(
                (string)($config['dbHost'] ?? ''),
                (string)($config['dbName'] ?? ''),
                $context
            );
            DestructiveDbGuard::assertPluginIdentity($config, $context);
        }
    }
}
