<?php
/**
 * Sync and save the fluent-cart POT file.
 *
 * Run from terminal:
 *   php wp-content/plugins/fluent-cart/dev/release-bump/sync-pot.php
 *
 * Or with a different WP root:
 *   WP_ROOT=/var/www/html php dev/release-bump/sync-pot.php
 */

// ── Bootstrap WordPress ───────────────────────────────────────────────────────

$wp_root = getenv('WP_ROOT') ?: dirname(__DIR__, 3); // 3 levels up from plugin dir
$wp_load = $wp_root . '/wp-load.php';

if ( ! file_exists( $wp_load ) ) {
    echo "ERROR: wp-load.php not found at: $wp_load\n";
    echo "Set the WP_ROOT env var to your WordPress root directory.\n";
    exit(1);
}

// Prevent any output/redirect from WordPress during bootstrap
define( 'DOING_CRON', true );

require $wp_load;

set_time_limit(300); // bail after 5 min if WP bootstrap or extraction hangs

// get_plugins() lives in wp-admin/includes/plugin.php — load it if not already available
if ( ! function_exists( 'get_plugins' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// ── Check Loco Translate is active ────────────────────────────────────────────

if ( ! function_exists( 'loco_constant' ) ) {
    echo "ERROR: Loco Translate plugin is not active.\n";
    exit(1);
}

// ── Find the fluent-cart bundle by scanning all active plugins ────────────────

$domain_id = getenv('TEXT_DOMAIN') ?: 'fluent-cart';
$bundle    = null;
$project   = null;

foreach ( Loco_package_Plugin::getAll() as $b ) {
    foreach ( $b as $p ) {
        if ( (string) $p->getDomain() === $domain_id ) {
            $bundle  = $b;
            $project = $p;
            break 2;
        }
    }
}

if ( ! $bundle || ! $project ) {
    echo "ERROR: No Loco project found for text domain '$domain_id'.\n";
    echo "Make sure fluent-cart is active and Loco Translate has detected it.\n";
    exit(1);
}

echo "Project: " . $project->getName() . "\n";
echo "Domain:  " . $project->getDomain() . "\n";

// ── Locate POT file ───────────────────────────────────────────────────────────

$potfile = $project->getPot();

if ( ! $potfile ) {
    // No POT configured — create one next to the plugin languages dir
    $lang_dir = $bundle->getDirectoryPath() . '/language';
    if ( ! is_dir( $lang_dir ) ) {
        mkdir( $lang_dir, 0755, true );
    }
    $potfile = new Loco_fs_LocaleFile( $lang_dir . '/' . $domain_id . '.pot' );
    echo "No POT file configured — will create: " . $potfile->getPath() . "\n";
} else {
    echo "POT file: " . $potfile->getPath() . "\n";
}

// ── Extract strings from source code ─────────────────────────────────────────

echo "\nExtracting strings from source...\n";

try {
    $extr = new Loco_gettext_Extraction( $bundle );
    $extr->addProject( $project );

    // Warn about skipped files (too large)
    if ( $skipped = $extr->getSkipped() ) {
        echo "WARNING: " . count( $skipped ) . " file(s) skipped (too large):\n";
        foreach ( $skipped as $f ) {
            echo "  - $f\n";
        }
    }

    $source = $extr->includeMeta()->getTemplate( $domain_id );
} catch ( Exception $e ) {
    echo "ERROR during extraction: " . $e->getMessage() . "\n";
    exit(1);
}

$count = count( $source );
echo "Extracted $count string(s).\n";

// ── Merge with existing POT (preserve translator comments) ───────────────────

if ( $potfile->exists() ) {
    echo "\nMerging with existing POT...\n";
    try {
        $existing = Loco_gettext_Data::load( $potfile );
        $matcher  = new Loco_gettext_Matcher( $project );
        $matcher->loadRefs( $source, false );
        $matcher->setFuzziness( '0' ); // hard sync for POT files

        $merged = clone $existing;
        $merged->clear();
        $matcher->merge( $existing, $merged );
        $merged->sort();

        $source = $merged;
        echo "Merge complete.\n";
    } catch ( Exception $e ) {
        echo "WARNING: Could not merge with existing POT (" . $e->getMessage() . "). Saving fresh extraction.\n";
    }
} else {
    echo "\nNo existing POT — saving fresh extraction.\n";
    $source->sort();
}

// ── Save POT file ─────────────────────────────────────────────────────────────

echo "\nSaving POT file...\n";

try {
    $compiler = new Loco_gettext_Compiler( $potfile );
    $bytes    = $compiler->writePo( $source );
    echo "Done. Wrote $bytes bytes to: " . $potfile->getPath() . "\n";
} catch ( Exception $e ) {
    echo "ERROR saving POT: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nAll done.\n";
