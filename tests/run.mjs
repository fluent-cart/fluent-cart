#!/usr/bin/env node
/**
 * `npm test` entry point. With no arguments in a terminal it shows a menu and runs the
 * modules you pick; with arguments it runs one module directly:
 *
 *   npm test -- wp-cli                 whole wp-cli      npm test -- codeception unit
 *   npm test -- wp-cli smoke orders    one tier + filter  npm test -- codeception functional Rest/Orders/CreateOrderCest
 *   npm test -- js
 *   npm test -- e2e                                     npm test -- safe   (wp-cli + codeception unit + js)
 *
 * Every module keeps its own runner; this file only picks and sequences them.
 */
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createInterface } from 'node:readline/promises';
import { parseEnvDb } from './codeception/bin/lib/db-guard.mjs';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const NODE = process.execPath;
const PHP_ENV = join(ROOT, 'tests', 'codeception', '.env');

const gate = (...preset) => (args) => ['bash', 'tests/wp-cli/bin/run-all.sh', ...preset, ...args];
// run-suite.mjs resolves a test path from tests/codeception, so a repo-relative path loses that prefix.
const php = (suite) => (args) => [NODE, 'tests/codeception/bin/run-suite.mjs', suite, ...args.map(a => a.replace(/^tests\/codeception\//, ''))];

const MODULES = [
    { id: 'wp-cli',                 group: 'wp-cli (REAL store, read-mostly)', cmd: gate(),              blurb: 'whole gate: static + pure + smoke + permissions + integration (~22s)' },
    { id: 'wp-cli static',          group: 'wp-cli',                                   cmd: gate('static'),      blurb: 'php -l and the lints' },
    { id: 'wp-cli pure',            group: 'wp-cli',                                   cmd: gate('pure'),        blurb: 'pure functions, provider fakes' },
    { id: 'wp-cli smoke',           group: 'wp-cli',                                   cmd: gate('smoke'),       blurb: 'every REST GET answers' },
    { id: 'wp-cli permissions',     group: 'wp-cli',                                   cmd: gate('permissions'), blurb: 'every mutating route denies anonymous and subscriber' },
    { id: 'wp-cli integration',     group: 'wp-cli',                                   cmd: gate('integration'), blurb: 'real-DB integration cases' },
    { id: 'e2e',                  group: 'wp-cli',                                   cmd: (a) => ['bash', 'tests/wp-cli/bin/run-e2e.sh', ...a], blurb: 'money flows against an inert gateway' },
    { id: 'codeception unit',             group: 'codeception (DISPOSABLE database)', cmd: php('Unit'),           blurb: 'no WordPress, no database' },
    { id: 'codeception integration',      group: 'codeception',                                    cmd: php('Integration'),    blurb: 'DESTRUCTIVE: installs WP into the test DB', destructive: true },
    { id: 'codeception functional',       group: 'codeception',                                    cmd: php('Functional'),     blurb: 'DESTRUCTIVE: REST contracts via rest_do_request()', destructive: true },
    { id: 'codeception integration-pro',  group: 'codeception',                                    cmd: php('IntegrationPro'), blurb: 'DESTRUCTIVE: needs fluent-cart-pro installed', destructive: true },
    { id: 'js',                   group: 'js',                           cmd: (a) => [NODE, 'node_modules/vitest/vitest.mjs', 'run', ...a], blurb: 'Vitest: admin request layer, stores, formatters' },
];
const COMPOSITES = {
    codeception: { members: ['codeception unit', 'codeception integration', 'codeception functional', 'codeception integration-pro'], blurb: 'unit, integration, functional, then integration-pro' },
    safe: { members: ['wp-cli', 'codeception unit', 'js'],                        blurb: 'everything that touches no disposable database' },
};

const byId = (id) => MODULES.find(m => m.id === id);

// `wp-cli smoke orders` → module "wp-cli smoke" + ["orders"]; `codeception unit Foo.php` → "codeception unit" + [path].
function resolveArgs(argv) {
    if (COMPOSITES[argv[0]] && argv.length === 1) {
        return COMPOSITES[argv[0]].members.map(id => ({ module: byId(id), args: [] }));
    }
    const two = argv.slice(0, 2).join(' ');
    if (byId(two)) return [{ module: byId(two), args: argv.slice(2) }];
    if (byId(argv[0])) return [{ module: byId(argv[0]), args: argv.slice(1) }];
    return null;
}

function readDbTarget() {
    if (!existsSync(PHP_ENV)) return null;
    const t = parseEnvDb(readFileSync(PHP_ENV, 'utf8'));
    return t.host && t.name && !t.duplicates ? `${t.host}:${t.name}` : null;
}

async function ask(question) {
    const rl = createInterface({ input: process.stdin, output: process.stdout });
    try { return (await rl.question(question)).trim(); } finally { rl.close(); }
}

// The guard itself lives in run-suite.mjs; this only spares a TTY user from retyping the opt-in.
let dbAllow = process.env.FCT_TEST_DB_ALLOW || null;
let dbAsked = false;
async function confirmDestructive() {
    if (dbAllow || dbAsked || !process.stdin.isTTY) return;
    dbAsked = true;
    const target = readDbTarget();
    if (!target) return;
    const answer = await ask(`\n  This suite installs WordPress into ${target} and TRUNCATES its fct_* tables.\n  Allow for this run? [y/N] `);
    if (/^y(es)?$/i.test(answer)) dbAllow = target;
}

async function runOne({ module, args }) {
    if (module.destructive) await confirmDestructive();
    const env = { ...process.env };
    if (module.destructive && dbAllow) env.FCT_TEST_DB_ALLOW = dbAllow;
    const [cmd, ...cmdArgs] = module.cmd(args);
    const { status, error } = spawnSync(cmd, cmdArgs, { cwd: ROOT, stdio: 'inherit', env });
    if (error) { console.error(error.message); return 1; }
    return status ?? 1;
}

async function runAll(selection) {
    const results = [];
    for (const entry of selection) results.push({ entry, code: await runOne(entry) });
    if (results.length > 1) {
        console.log('\nSUMMARY');
        for (const { entry, code } of results) console.log(`  ${code === 0 ? 'PASS' : 'FAIL'}  ${entry.module.id}${code ? ` (exit ${code})` : ''}`);
    }
    return results.some(r => r.code !== 0) ? 1 : 0;
}

function menuText() {
    const lines = ['', 'FluentCart tests. Pick one or more (1  or  2,7,11), q to quit.', ''];
    let group = '';
    MODULES.forEach((m, i) => {
        const g = m.group.startsWith('wp-cli') ? 'wp-cli (REAL store, read-mostly)'
            : m.group.startsWith('codeception') ? 'codeception (DISPOSABLE database)' : 'js';
        if (g !== group) { group = g; lines.push(`  ${g}`); }
        lines.push(`  ${String(i + 1).padStart(3)}  ${m.id.padEnd(22)} ${m.blurb}`);
    });
    lines.push('', '  Combined');
    Object.entries(COMPOSITES).forEach(([id, c], i) => {
        lines.push(`  ${String(MODULES.length + i + 1).padStart(3)}  ${id.padEnd(22)} ${c.blurb}`);
    });
    lines.push('');
    return lines.join('\n');
}

function parseChoice(input) {
    const picks = input.split(/[\s,]+/).filter(Boolean);
    const selection = [];
    for (const p of picks) {
        const n = Number(p);
        if (!Number.isInteger(n) || n < 1) return null;
        if (n <= MODULES.length) selection.push({ module: MODULES[n - 1], args: [] });
        else {
            const comp = Object.values(COMPOSITES)[n - MODULES.length - 1];
            if (!comp) return null;
            comp.members.forEach(id => selection.push({ module: byId(id), args: [] }));
        }
    }
    return selection.length ? selection : null;
}

function help() {
    const pad = 28;
    console.log(`
FluentCart test commands

  npm test                      menu (terminal only)
  npm test -- <module> [args]   run one module; aliases: npm run test:wp-cli, test:codeception, test:unit, test:js, test:e2e

  Modules`);
    for (const m of MODULES) console.log(`    ${m.id.padEnd(pad)} ${m.blurb}`);
    for (const [id, c] of Object.entries(COMPOSITES)) console.log(`    ${id.padEnd(pad)} ${c.blurb}`);
    console.log(`
  wp-cli arguments               (after the tier) a substring filter, e.g. wp-cli integration coupon
    wp-cli all --axis=strict-sql   hostile MySQL ONLY_FULL_GROUP_BY profile
    wp-cli all --axis=non-utc      non-zero gmt_offset profile
    wp-cli all --axis=pro-absent   FluentCart Pro deactivated
    wp-cli static --full-php-lint  php -l over every file, not only the changed ones

  codeception arguments                a test path, then codecept flags: codeception integration Integration/Models/OrderTest.php --html
    FCT_TEST_DB_ALLOW="host:db"  opt-in for the destructive suites; the menu asks once per run
    FLUENTCART_NO_TEST_INSTALL=1 never install Codeception or create .env; fail with instructions

  Config files: tests/codeception/.env (PHP suites). Map: tests/README.md
`);
}

async function main() {
    const argv = process.argv.slice(2);
    if (argv.includes('--help') || argv.includes('-h')) { help(); return 0; }
    if (argv.length) {
        const selection = resolveArgs(argv);
        if (!selection) { console.error(`Unknown module: ${argv.join(' ')}`); help(); return 2; }
        return runAll(selection);
    }
    if (!process.stdin.isTTY || !process.stdout.isTTY) {
        console.error(menuText());
        console.error('  No terminal: pass the module, e.g. npm test -- wp-cli');
        return 2;
    }
    console.log(menuText());
    const answer = await ask('> ');
    if (/^q(uit)?$/i.test(answer) || answer === '') return 0;
    const selection = parseChoice(answer);
    if (!selection) { console.error(`Not a valid choice: ${answer}`); return 2; }
    return runAll(selection);
}

process.exit(await main());
