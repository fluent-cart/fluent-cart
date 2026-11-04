/**
 * PHP suite runner — bootstraps whatever is missing, then hands off to Codeception.
 *
 * Usage:
 *   node tests/codeception/bin/run-suite.mjs Unit
 *   node tests/codeception/bin/run-suite.mjs Functional --html
 *   node tests/codeception/bin/run-suite.mjs Integration Integration/Models/OrderTest.php
 *
 * A fresh clone has none of Codeception's prerequisites: tests/codeception/vendor/,
 * .env, and the generated actor classes are all gitignored. Rather than failing
 * with `sh: vendor/bin/codecept: No such file or directory`, we install them on demand.
 *
 * Env:
 *   FLUENTCART_NO_TEST_INSTALL=1   Never bootstrap; fail with instructions instead.
 */
import { existsSync, copyFileSync, readFileSync } from 'fs';
import { spawnSync } from 'child_process';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { parseEnvDb, guardOk, checkDbBinding } from './lib/db-guard.mjs';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url))); // tests/codeception
const CODECEPT = join(ROOT, 'vendor', 'bin', 'codecept');
const ENV_FILE = join(ROOT, ".env");
const ENV_EXAMPLE = join(ROOT, ".env.example");
const GENERATED = join(ROOT, "Support", "_generated");

// The only suite selectors this wrapper will forward.
const SUITES = ['Unit', 'Integration', 'Functional', 'IntegrationPro'];

// Suites that boot WordPress against TEST_DB_NAME and drop its tables between runs.
const DESTRUCTIVE_SUITES = ['Integration', 'Functional', 'IntegrationPro'];

// Values straight out of .env.example that no real setup would keep.
const PLACEHOLDERS = ['/path/to/wordpress'];

// Codeception options that decide which config — and therefore which DATABASE — the run
// actually uses. The guard below reads .env, so any of these makes the file it read
// the wrong one: `-c other.yml` swaps the params file, `--env` selects a different env
// block, `-o` overwrites WPLoader's dbName outright, `--ext` loads an extension that can
// rewrite config at boot, and `--bootstrap` runs an arbitrary PHP script before the suite
// — which can repoint the config, or simply drop the tables itself. Approving one database
// and destroying another is exactly the accident this wrapper exists to prevent.
//
// The `-c` half must mirror Codeception's OWN preloader, not Symfony's. Before Symfony ever
// validates argv, Application::getCoreArguments() pre-scans every token with
// `#^(?:-([^c-]*)?c|--config(?:=|$))#` and, on a match, loads that config and runs its
// bootstrap — so a token Symfony would reject as malformed (`-1c evil.yml`, prefix `1`)
// still executes code first. A letter-only prefix (`-[a-zA-Z]*c`) misses those; `-[^-]*`
// matches exactly the set Codeception's `[^c-]*` preloader accepts. The `[oe]` short flags
// (`-o`/`--override`, `-e`/`--ext`) are NOT preloaded — Symfony parses them normally and
// rejects a bad prefix — so a plain non-dash cluster covers them. Long forms and the
// long-only `--env`/`--bootstrap` are listed explicitly. Attached (`-cfoo`, `--config=foo`)
// and clustered (`-vc`) forms fall out of the same patterns.
const CONFIG_FLAGS = /^(?:--(?:config|override|env|ext|bootstrap)(?:=|$)|-[^-]*[coe])/;

const [suite, ...passthrough] = process.argv.slice(2);
const autoInstall = process.env.FLUENTCART_NO_TEST_INSTALL !== '1';

const usage = () => console.error(`Usage: run-suite.mjs <${SUITES.join('|')}> [test path] [codecept args...]`);

if (!suite) {
  usage();
  process.exit(1);
}

// ── 0. The first argument must be a bare suite name ─────────────────────────
// `codecept run` treats its first argument as a *selector*, not a suite name: given no
// second argument it resolves `Integration`, `Integration/Foo.php`, `tests`
// or a wildcard like `Int*` back to one or more suites (Run::matchSingleTest). Every one
// of those reaches the destructive suites, so anything but an exact suite name would sail
// past the .env guard below. Test paths are still allowed — as the *second* argument,
// where Codeception keeps the suite literal.
if (!SUITES.includes(suite)) {
  console.error(`\n  ❌ Unknown suite "${suite}". Paths and wildcards are not accepted as the first argument.\n`);
  usage();
  process.exit(1);
}

const run = (cmd, args, label) => {
  const { status, error } = spawnSync(cmd, args, { cwd: ROOT, stdio: 'inherit' });
  if (error) throw error;
  if (status !== 0) {
    console.error(`\n  ❌ ${label} failed (exit ${status}).`);
    process.exit(status);
  }
};

/**
 * Has anyone actually configured .env, or is it still the shipped template?
 *
 * Two signals, either one damning:
 *   - the file is byte-identical to .env.example (nobody has touched it), or
 *   - it still carries a placeholder value that cannot possibly be a real setup.
 *
 * Deliberately conservative: any edit at all reads as "a human looked at this", because
 * the goal is to stop an *unreviewed* destructive run, not to validate the credentials.
 */
function isUnreviewedEnv() {
  if (!existsSync(ENV_FILE)) return true;

  const env = readFileSync(ENV_FILE, 'utf8');

  if (existsSync(ENV_EXAMPLE) && env.trim() === readFileSync(ENV_EXAMPLE, 'utf8').trim()) return true;

  return PLACEHOLDERS.some(p => env.includes(p));
}

const refuse = (what, fix) => {
  console.error(
    `\n  ❌ ${what}\n` +
    `     Fix:  ${fix}\n` +
    `     (auto-setup is disabled by FLUENTCART_NO_TEST_INSTALL=1)\n`
  );
  process.exit(1);
};

// ── 1. Composer dependencies ────────────────────────────────────────────────
if (!existsSync(CODECEPT)) {
  if (!autoInstall) refuse('Codeception is not installed.', 'cd tests/codeception && composer install');

  console.log('\n  📦 Codeception is not installed — running composer install (one-off)...\n');
  const composer = spawnSync('composer', ['--version'], { stdio: 'ignore' }).status === 0;
  if (!composer) {
    console.error(
      '\n  ❌ composer is required to install the PHP test dependencies, but it is not on your PATH.\n' +
      '     Install it: https://getcomposer.org/download/\n'
    );
    process.exit(1);
  }
  run('composer', ['install'], 'composer install');
}

// ── 2. Test environment file ────────────────────────────────────────────────
// codeception.yml reads .env for the DB credentials and WP root. It is gitignored,
// so a fresh clone has none and Codeception dies with "Params file ... not found".
if (!existsSync(ENV_FILE)) {
  if (!autoInstall) refuse('tests/codeception/.env is missing.', 'cp tests/codeception/.env.example tests/codeception/.env');
  if (!existsSync(ENV_EXAMPLE)) {
    console.error(`\n  ❌ Neither .env nor .env.example exists in ${ROOT}.\n`);
    process.exit(1);
  }

  copyFileSync(ENV_EXAMPLE, ENV_FILE);
  console.log(`
  ╭─────────────────────────────────────────────────────────────────────────────╮
  │  📝  Created tests/codeception/.env from .env.example
  ╰─────────────────────────────────────────────────────────────────────────────╯
`);
}

// ── 2a. The guard reads .env — so .env must be the file that runs ──
// Checking one config and then executing another is not a guard at all. `-c other.yml`,
// `--env`, `-o`, `--ext` and `--bootstrap` each let the run reach a database this wrapper
// never inspected — the first four by repointing the effective config, the last by running
// arbitrary PHP before the suite starts. Refuse them outright for the destructive suites;
// there is no legitimate reason to redirect the config here, and anyone who genuinely needs
// to can call vendor/bin/codecept directly and own the risk.
const configFlag = DESTRUCTIVE_SUITES.includes(suite) && passthrough.find(a => CONFIG_FLAGS.test(a));
if (configFlag) {
  console.error(`
  ╭─────────────────────────────────────────────────────────────────────────────╮
  │  ⛔  Refusing to run the ${suite} suite — "${configFlag}" redirects the config
  ├─────────────────────────────────────────────────────────────────────────────┤
  │  -c/--config, --env, -o/--override, --ext and --bootstrap can all send this run
  │  at a database this wrapper never inspected — the first four by changing which
  │  configuration Codeception loads, --bootstrap by running arbitrary PHP first.
  │  It vets tests/codeception/.env, and cannot vouch for anything else.
  │
  │  Point .env at the database you want, and run without that flag.
  ╰─────────────────────────────────────────────────────────────────────────────╯
`);
  process.exit(1);
}

// ── 2b. Refuse to destroy a database nobody has reviewed ────────────────────
// Integration and Functional install WordPress into TEST_DB_NAME and DROP its tables.
// The bar is "did a human ever look at this config", NOT "did we create it just now" —
// `npm run test:unit` creates the template .env too, and a later Integration run would
// otherwise sail straight past this check and wipe the template's database.
//
// An untouched copy of the template is the signal: if the file still matches .env.example
// byte for byte, or still carries its placeholder values, nobody has configured it.
// Editing it at all counts as review. Unit is exempt — it opens no connection.
if (DESTRUCTIVE_SUITES.includes(suite) && isUnreviewedEnv()) {
  console.error(`
  ╭─────────────────────────────────────────────────────────────────────────────╮
  │  ⛔  Refusing to run the ${suite} suite — .env is unconfigured
  ├─────────────────────────────────────────────────────────────────────────────┤
  │  This suite installs WordPress into TEST_DB_NAME and WILL DROP ITS TABLES,
  │  but tests/codeception/.env is still the untouched template — its database
  │  name and WordPress path are placeholders, not your setup. Running now could
  │  destroy a database you never approved.
  │
  │  Open tests/codeception/.env, point WORDPRESS_ROOT_DIR and TEST_DB_* at a
  │  throwaway database, then run this command again.
  ╰─────────────────────────────────────────────────────────────────────────────╯
`);
  process.exit(1);
}

// ── 2b. Destructive-DB opt-in ────────────────────────────────────────────────
// A reviewed .env proves a human LOOKED at it once; it does not prove this
// particular run may destroy that database today. The destructive suites
// additionally require FCT_TEST_DB_ALLOW to name the exact host:database
// (same fail-closed parse as baseline-check.mjs — duplicates, export
// prefixes, comments, quoted names all handled; see bin/lib/db-guard.mjs).
// This is the early, friendly layer; the terminal guard is TC-047's
// in-process check (Support/Extension/DestructiveDbGuardExtension.php),
// which validates the values Codeception actually resolved and covers bare
// `vendor/bin/codecept run` too.
if (DESTRUCTIVE_SUITES.includes(suite)) {
  // Config binding first: the opt-in below validates .env, which is
  // only sound while WPLoader actually resolves its destructive target from
  // that file. A suite yml hardcoding dbName, adding dbUrl, or using the
  // uppercase DB_* keys would aim WPLoader at a database the guard never
  // saw — same check, same files as baseline-check.mjs.
  const bindingProblems = checkDbBinding(
    readFileSync(join(ROOT, 'codeception.yml'), 'utf8'),
    ['Unit.suite.yml', 'Integration.suite.yml', 'Functional.suite.yml', 'IntegrationPro.suite.yml']
      .filter(f => existsSync(join(ROOT, f)))
      .map(f => ({ name: f, text: readFileSync(join(ROOT, f), 'utf8') })));
  if (bindingProblems.length) {
    console.error(`\n  ⛔ Refusing to run the ${suite} suite — Codeception config is not bound to .env:\n`);
    for (const p of bindingProblems) console.error(`     ✗ ${p}`);
    console.error('');
    process.exit(1);
  }
  const target = parseEnvDb(readFileSync(ENV_FILE, 'utf8'));
  if (!guardOk(target, process.env.FCT_TEST_DB_ALLOW || '')) {
    console.error(`
  ╭─────────────────────────────────────────────────────────────────────────────╮
  │  ⛔  Refusing to run the ${suite} suite — no destructive-DB opt-in
  ├─────────────────────────────────────────────────────────────────────────────┤
  │  This suite installs WordPress into TEST_DB_NAME and TRUNCATES every fct_*
  │  table. Name the exact sacrificial database to proceed:
  │
  │    FCT_TEST_DB_ALLOW="${target.host || '<host>'}:${target.name || '<database>'}" node bin/run-suite.mjs ${suite} ...
  │
  │  (resolved from .env: ${target.host || '?'}:${target.name || '?'}${target.duplicates ? ' — POISONED: a key is assigned more than once' : ''})
  │  Only set this for a database whose data you are willing to destroy.
  ╰─────────────────────────────────────────────────────────────────────────────╯
`);
    process.exit(1);
  }
}

// ── 3. Generated actor classes ──────────────────────────────────────────────
// Support/_generated/ is gitignored; without it the Tester classes don't exist.
if (!existsSync(GENERATED)) {
  if (!autoInstall) refuse('Codeception actor classes are not generated.', 'cd tests/codeception && vendor/bin/codecept build');

  console.log('  🔧 Generating Codeception actor classes (codecept build)...\n');
  run(CODECEPT, ['build'], 'codecept build');
}

// ── 4. Hand off ─────────────────────────────────────────────────────────────
const { status } = spawnSync(CODECEPT, ['run', suite, ...passthrough], { cwd: ROOT, stdio: 'inherit' });
process.exit(status ?? 1);
