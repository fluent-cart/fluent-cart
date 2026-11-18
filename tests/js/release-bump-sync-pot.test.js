import {existsSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {afterEach, describe, expect, it} from 'vitest';

const script = path.resolve('dev/release-bump/sync-pot.php');
const tempRoots = [];

// A WordPress root whose wp-load.php stubs the Loco Translate API sync-pot.php calls.
function stubWordPress() {
    const wpRoot = mkdtempSync(path.join(tmpdir(), 'fluent-cart-pot-'));
    const pluginRoot = path.join(wpRoot, 'wp-content/plugins/fluent-cart');
    tempRoots.push(wpRoot);
    mkdirSync(pluginRoot, {recursive: true});

    writeFileSync(path.join(wpRoot, 'wp-load.php'), `<?php
$callbacks = $GLOBALS['wp_filter']['pre_option_active_plugins'][10] ?? array();
$callback = $callbacks[0]['function'] ?? null;

if ( ! is_callable( $callback ) || array( 'loco-translate/loco.php' ) !== call_user_func( $callback ) ) {
    fwrite( STDERR, "POT bootstrap did not isolate active plugins.\\n" );
    exit(90);
}

define( 'ABSPATH', __DIR__ . '/' );

function get_plugins() {
    return array();
}

function loco_constant( $name ) {
    return defined( $name ) ? constant( $name ) : null;
}

class MockProject {
    public function getName() {
        return 'FluentCart';
    }

    public function getDomain() {
        return 'fluent-cart';
    }

    public function getPot() {
        $pot = getenv( 'TARGET_PLUGIN_ROOT' ) . '/language/fluent-cart.pot';
        return file_exists( $pot ) ? new Loco_fs_LocaleFile( $pot ) : null;
    }
}

class MockBundle implements IteratorAggregate {
    public function getIterator(): Traversable {
        yield new MockProject();
    }

    public function getDirectoryPath() {
        return getenv( 'TARGET_PLUGIN_ROOT' );
    }
}

class Loco_package_Plugin {
    public static function getAll() {
        return array( new MockBundle() );
    }
}

class Loco_fs_LocaleFile {
    private $path;

    public function __construct( $path ) {
        $this->path = $path;
    }

    public function exists() {
        return file_exists( $this->path );
    }

    public function getPath() {
        return $this->path;
    }
}

class MockSource implements Countable {
    private $headers;

    public function __construct( $date = '2026-11-09 08:00+0000' ) {
        $this->headers = new ArrayObject( array( 'POT-Creation-Date' => $date ) );
    }

    public function count(): int {
        return 1;
    }

    public function sort() {
    }

    public function clear() {
    }

    public function getHeaders() {
        return $this->headers;
    }
}

class Loco_gettext_Data {
    public static function load( $file ) {
        preg_match( '/POT-Creation-Date: ([^\\n]+)/', file_get_contents( $file->getPath() ), $date );
        return new MockSource( $date[1] );
    }
}

class Loco_gettext_Matcher {
    public function __construct( $project ) {
    }

    public function loadRefs( $source, $translate ) {
    }

    public function setFuzziness( $fuzziness ) {
    }

    public function merge( $existing, $merged ) {
    }
}

class Loco_gettext_Extraction {
    public function __construct( $bundle ) {
    }

    public function addProject( $project ) {
    }

    public function getSkipped() {
        return array();
    }

    public function includeMeta() {
        return $this;
    }

    public function getTemplate( $domain ) {
        return new MockSource();
    }
}

class Loco_gettext_Compiler {
    private $file;

    public function __construct( $file ) {
        $this->file = $file;
    }

    public function writePo( $source ) {
        $contents = 'msgid ""' . PHP_EOL . 'POT-Creation-Date: ' . $source->getHeaders()['POT-Creation-Date'] . PHP_EOL;
        file_put_contents( $this->file->getPath(), $contents );

        return strlen( $contents );
    }
}
`);

    return {wpRoot, pluginRoot};
}

function runSync({wpRoot, pluginRoot}) {
    return spawnSync('php', [script], {
        encoding: 'utf8',
        env: {
            ...process.env,
            WP_ROOT: wpRoot,
            TEXT_DOMAIN: 'fluent-cart',
            TARGET_PLUGIN_ROOT: pluginRoot,
        },
    });
}

describe('release bump POT generation', () => {

    afterEach(() => {
        tempRoots.splice(0).forEach(root => rmSync(root, {recursive: true, force: true}));
    });

    it('boots only Loco Translate before extracting plugin strings', () => {
        const site = stubWordPress();
        const result = runSync(site);

        expect(result.status, result.stderr || result.stdout).toBe(0);
        expect(existsSync(path.join(site.pluginRoot, 'language/fluent-cart.pot'))).toBe(true);
    });

    it('dates a merged POT now, not when the first POT was made', () => {
        const site = stubWordPress();
        const pot = path.join(site.pluginRoot, 'language/fluent-cart.pot');
        mkdirSync(path.dirname(pot));
        writeFileSync(pot, 'msgid ""\nPOT-Creation-Date: 2026-09-11 12:57+0000\n');

        const result = runSync(site);
        const date = /POT-Creation-Date: (.+)/.exec(readFileSync(pot, 'utf8'))[1];

        expect(result.status, result.stderr || result.stdout).toBe(0);
        expect(result.stdout).toContain('Merge complete.');
        expect(Date.now() - Date.parse(date.replace(' ', 'T').replace('+0000', 'Z'))).toBeLessThan(120000);
    });
});
