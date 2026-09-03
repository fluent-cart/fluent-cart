import {existsSync, mkdtempSync, mkdirSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {afterEach, describe, expect, it} from 'vitest';

const script = path.resolve('dev/release-bump/sync-pot.php');
const tempRoots = [];

describe('release bump POT generation', () => {

    afterEach(() => {
        tempRoots.splice(0).forEach(root => rmSync(root, {recursive: true, force: true}));
    });

    it('boots only Loco Translate before extracting plugin strings', () => {
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
        return null;
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
        return false;
    }

    public function getPath() {
        return $this->path;
    }
}

class MockSource implements Countable {
    public function count(): int {
        return 1;
    }

    public function sort() {
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
        $contents = 'msgid ""' . PHP_EOL;
        file_put_contents( $this->file->getPath(), $contents );

        return strlen( $contents );
    }
}
`);

        const result = spawnSync('php', [script], {
            encoding: 'utf8',
            env: {
                ...process.env,
                WP_ROOT: wpRoot,
                TEXT_DOMAIN: 'fluent-cart',
                TARGET_PLUGIN_ROOT: pluginRoot,
            },
        });

        expect(result.status, result.stderr || result.stdout).toBe(0);
        expect(existsSync(path.join(pluginRoot, 'language/fluent-cart.pot'))).toBe(true);
    });
});
