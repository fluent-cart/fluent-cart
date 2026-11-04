/**
 * Browser setup, login, config prompts, and network monitoring.
 */
import { chromium, firefox, webkit } from 'playwright-core';
import { existsSync, readdirSync, mkdirSync, readFileSync } from 'fs';
import { homedir } from 'os';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';
import { createRequire } from 'module';
import { spawnSync } from 'child_process';
import { createInterface } from 'readline';

// ─── Load .env file ──────────────────────────────────────────────────────────

const __dirname = dirname(fileURLToPath(import.meta.url));
const envPath = join(__dirname, '.env');

if (existsSync(envPath)) {
  const envContent = readFileSync(envPath, 'utf-8');
  for (const line of envContent.split('\n')) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const [key, ...rest] = trimmed.split('=');
    const value = rest.join('=').trim();
    if (key && value && !process.env[key]) {
      process.env[key] = value;
    }
  }
}

// ─── Prompts ─────────────────────────────────────────────────────────────────

export function prompt(question, defaultValue = '') {
  const rl = createInterface({ input: process.stdin, output: process.stdout });
  const suffix = defaultValue ? ` (${defaultValue})` : '';
  return new Promise(resolve => {
    rl.question(`${question}${suffix}: `, answer => {
      rl.close();
      resolve(answer.trim() || defaultValue);
    });
  });
}

export function promptChoice(question, choices) {
  return new Promise(async (resolve) => {
    console.log(`\n  ${question}`);
    choices.forEach((c, i) => console.log(`    ${i + 1}. ${c}`));
    const answer = await prompt('  Choose', '1');
    const idx = parseInt(answer, 10) - 1;
    resolve(idx >= 0 && idx < choices.length ? idx : 0);
  });
}

export async function promptYesNo(question, defaultYes = true) {
  const answer = await prompt(`  ${question}`, defaultYes ? 'y' : 'n');
  return answer.toLowerCase().startsWith('y');
}

export async function waitForUser(message = 'Press Enter to continue...') {
  await prompt(`\n  ⏸️  ${message}`);
}

// ─── Config ──────────────────────────────────────────────────────────────────

/**
 * A bare host like `freshcart.test` is not a URL page.goto() accepts — it needs a
 * scheme. Add https:// when one is missing, and drop any trailing slash so the
 * `${baseUrl}/wp-login.php` concatenations downstream stay well-formed.
 */
export function normalizeBaseUrl(url) {
  const trimmed = (url || '').trim();
  if (!trimmed || trimmed === 'about:blank') return trimmed;
  const withScheme = /^[a-z][a-z0-9+.-]*:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
  return withScheme.replace(/\/+$/, '');
}

/**
 * Headless unless asked otherwise. Resolution mirrors getBrowserName() so the two knobs
 * behave the same way — `--safari` and `--headed` are set side by side on one command
 * line, and it would be a trap for one of them to be env-only:
 *   1. `--headed` on the command line (`npm test -- playwright --safari --headed`)
 *   2. npm config flag (`npm run test:playwright --safari --headed` sets npm_config_headed=true)
 *   3. HEADED=true|1
 *   4. headless
 */
export function isHeaded() {
  if (process.argv.slice(2).includes('--headed') || process.env.npm_config_headed === 'true') return true;
  return process.env.HEADED === 'true' || process.env.HEADED === '1';
}

export async function getConfig(title = 'FluentCart Browser Tests', extraPrompts = {}, opts = {}) {
  const isCI = process.env.CI === 'true';

  // No login needed for this run (only self-contained tests) — don't prompt for
  // WordPress credentials. baseUrl is irrelevant (tests run on about:blank).
  if (opts.skipLoginPrompt) {
    return {
      baseUrl: normalizeBaseUrl(process.env.WP_URL || 'about:blank'),
      user: process.env.WP_USER || '',
      pass: process.env.WP_PASS || '',
      headed: isHeaded(),
      ...Object.fromEntries(Object.entries(extraPrompts).map(([k, v]) => [k, process.env[v.env] || v.default || '']))
    };
  }

  if (isCI || (process.env.WP_URL && process.env.WP_USER && process.env.WP_PASS)) {
    return {
      baseUrl: normalizeBaseUrl(process.env.WP_URL),
      user: process.env.WP_USER,
      pass: process.env.WP_PASS,
      headed: isHeaded(),
      ...Object.fromEntries(Object.entries(extraPrompts).map(([k, v]) => [k, process.env[v.env] || v.default || '']))
    };
  }

  console.log(`🧪 ${title} — Setup\n`);
  const baseUrl = await prompt('  Site URL', process.env.WP_URL || 'https://wp.test');
  const user = await prompt('  Admin username', process.env.WP_USER || 'admin');
  const pass = await prompt('  Admin password', process.env.WP_PASS || '');

  const extra = {};
  for (const [key, opts] of Object.entries(extraPrompts)) {
    extra[key] = await prompt(`  ${opts.label}`, process.env[opts.env] || opts.default || '');
  }

  return {
    baseUrl: normalizeBaseUrl(baseUrl),
    user,
    pass,
    headed: isHeaded(),
    ...extra
  };
}

// ─── Browser ─────────────────────────────────────────────────────────────────

async function findChromium() {
  const cacheDir = join(homedir(), 'Library', 'Caches', 'ms-playwright');
  const linuxCache = join(homedir(), '.cache', 'ms-playwright');
  const winCache = join(homedir(), 'AppData', 'Local', 'ms-playwright');

  for (const dir of [cacheDir, linuxCache, winCache].filter(existsSync)) {
    for (const entry of readdirSync(dir).filter(e => e.startsWith('chromium-')).sort().reverse()) {
      const found = [
        join(dir, entry, 'chrome-mac-arm64', 'Google Chrome for Testing.app', 'Contents', 'MacOS', 'Google Chrome for Testing'),
        join(dir, entry, 'chrome-mac', 'Google Chrome for Testing.app', 'Contents', 'MacOS', 'Google Chrome for Testing'),
        join(dir, entry, 'chrome-linux', 'chrome'),
        join(dir, entry, 'chrome-win', 'chrome.exe'),
      ].find(existsSync);
      if (found) return found;
    }
  }

  return [
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome', '/usr/bin/chromium-browser',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  ].find(existsSync) || null;
}

// Engine aliases → Playwright browser type. Safari is driven by WebKit, the same
// engine Safari ships; Playwright cannot drive the Safari.app binary itself.
const ENGINES = {
  '--firefox':  'firefox',
  '--safari':   'webkit',
  '--webkit':   'webkit',
  '--chrome':   'chromium',
  '--chromium': 'chromium',
};

/**
 * Which engine to drive. Resolution order:
 *   1. `--firefox` / `--safari` / `--chrome` on the command line (`npm test -- playwright --safari`)
 *   2. npm config flags (`npm run test:playwright --safari` sets npm_config_safari=true)
 *   3. BROWSER=firefox|safari|webkit|chrome|chromium
 *   4. chromium
 */
export function getBrowserName() {
  const argv = process.argv.slice(2);
  for (const [flag, engine] of Object.entries(ENGINES)) {
    if (argv.includes(flag) || process.env[`npm_config_${flag.slice(2)}`] === 'true') return engine;
  }

  const env = (process.env.BROWSER || '').toLowerCase();
  return ENGINES[`--${env}`] || 'chromium';
}

const isMissingEngine = (e) => /Executable doesn't exist|Please run the following command/.test(e.message);

/**
 * Download a Playwright engine on demand. Firefox and WebKit are not bundled with
 * playwright-core, and requiring a separate install step before the first Safari or
 * Firefox run is friction with no upside — the command already told us which engine
 * the user wants. Chromium is never installed here: it resolves from an existing
 * cache or system Chrome.
 *
 * Set FLUENTCART_NO_BROWSER_INSTALL=1 to opt out (CI images that pre-bake engines,
 * or anyone who'd rather not have a test command pull ~80MB over the network).
 */
function installEngine(installName, label) {
  // playwright-core does not expose ./cli.js in its "exports" map, so resolve the package
  // entry point and walk to the CLI beside it rather than requiring the subpath directly.
  const pkgRoot = dirname(createRequire(import.meta.url).resolve('playwright-core'));
  const cli = join(pkgRoot, 'cli.js');
  if (!existsSync(cli)) {
    throw new Error(`Cannot find the Playwright CLI at ${cli} — install ${label} manually with:  npm run test:playwright:install`);
  }

  console.log(`\n  ⬇️  ${label} is not installed — downloading it now (one-off, ~80MB)...`);
  console.log(`     Skip this with FLUENTCART_NO_BROWSER_INSTALL=1\n`);

  const { status, error } = spawnSync(process.execPath, [cli, 'install', installName], { stdio: 'inherit' });
  if (error) throw error;
  if (status !== 0) throw new Error(`Failed to install ${label} (playwright-core install ${installName} exited ${status}).`);

  console.log(`\n  ✅ ${label} installed.\n`);
}

async function launchInstalled(browserType, engine, installName, label, headed) {
  try {
    return await browserType.launch({ headless: !headed });
  } catch (e) {
    if (!isMissingEngine(e)) throw e;

    if (process.env.FLUENTCART_NO_BROWSER_INSTALL === '1') {
      throw new Error(
        `${label} is not installed for Playwright.\n` +
        `  Install it with:  npm run test:playwright:install\n` +
        `  (auto-install is disabled by FLUENTCART_NO_BROWSER_INSTALL=1)\n\n` +
        e.message
      );
    }

    installEngine(installName, label);

    // Retry once. A second miss means the download succeeded but the binary still
    // isn't where Playwright looks — a real problem, so let it surface unwrapped.
    return browserType.launch({ headless: !headed });
  }
}

// The engine actually launched, so diagnostics name the real browser rather than
// re-deriving it from argv (callers may pass browserName explicitly).
let activeBrowserName = null;

export async function launchBrowser(headed = true, browserName = getBrowserName()) {
  activeBrowserName = browserName;

  if (browserName === 'firefox') return launchInstalled(firefox, 'firefox', 'firefox', 'Firefox', headed);
  if (browserName === 'webkit')  return launchInstalled(webkit, 'webkit', 'webkit', 'Safari (WebKit)', headed);

  let path;

  if (process.env.CHROMIUM_PATH) {
    path = process.env.CHROMIUM_PATH;
  } else if (headed) {
    // In headed mode, prefer system Chrome for smooth performance
    path = [
      '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
      '/usr/bin/google-chrome',
      '/usr/bin/google-chrome-stable',
      '/usr/bin/chromium-browser',
      'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    ].find(existsSync) || await findChromium();
  } else {
    // Headless — use Playwright's testing browser (faster, no GPU)
    path = await findChromium();
  }

  const opts = {
    headless: !headed,
    args: ['--ignore-certificate-errors'],
    ...(headed && { channel: undefined }), // don't use channel when we have a path
  };
  if (path) opts.executablePath = path;
  return chromium.launch(opts);
}

// ─── Monitoring ──────────────────────────────────────────────────────────────

const networkErrors = [];
const consoleErrors = [];
const blockedInsecureOrigins = new Set();

export function getMonitorErrors() {
  return { networkErrors: [...networkErrors], consoleErrors: [...consoleErrors] };
}

/**
 * Origins whose SCRIPTS or STYLESHEETS the browser refused to load as mixed content.
 * Only actively-blocked resources land here — a browser that merely warns about, or
 * auto-upgrades, an insecure request has not broken anything.
 */
export function getBlockedInsecureOrigins() {
  return [...blockedInsecureOrigins];
}

// Passive content (an image) being blocked is cosmetic. Active content (script, module,
// stylesheet) being blocked stops the admin app from booting. Only the latter explains a
// suite-wide failure, so only the latter earns the banner.
const PASSIVE_ASSET = /\.(svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|mp4|webm)(\?|#|$)/i;

/**
 * True only when the browser says it BLOCKED the request. Every engine words this
 * differently, and each also emits look-alike messages for insecure content it went
 * ahead and loaded (WebKit: "This content was not upgraded"; Chrome: "automatically
 * upgraded"). Matching on "insecure content" alone reports failures that never happened.
 */
function isBlockMessage(text) {
  return /\[blocked\]|has been blocked|was blocked|blocked loading mixed|blocked mixed/i.test(text);
}

/**
 * Pull the offending http:// URL out of a console sentence. Engines quote it differently —
 * Firefox uses curly quotes (“http://…/logo.png”), Chrome straight ones, WebKit none at all
 * and just ends the sentence with a period. Any quote or punctuation left clinging to the
 * URL defeats the PASSIVE_ASSET extension check, which is what tells a blocked image
 * (cosmetic) apart from a blocked script (fatal).
 */
function extractInsecureUrl(text) {
  return (text.match(/https?:\/\/[^\s"'“”‘’()<>[\]]+/g) || [])
    .map(u => u.replace(/[.,;:!?]+$/, ''))
    .find(u => u.startsWith('http://'));
}

/**
 * Given a console message, return the origin of an ACTIVE resource (script/stylesheet) the
 * browser actually BLOCKED as mixed content — or null. Null for insecure content that was
 * merely warned about or auto-upgraded, and null for passive content (images, fonts), which
 * is cosmetic and cannot explain a suite-wide failure.
 */
export function blockedActiveInsecureOrigin(text) {
  // Firefox words this "mixed active content" / "mixed display content", so match loosely.
  if (!/insecure|mixed \w* ?content/i.test(text)) return null;
  if (!isBlockMessage(text)) return null;

  // Firefox names the category outright, and "display" content is passive by definition —
  // an image or font it refused to show. Nothing to do with the admin bundle failing.
  if (/mixed display content/i.test(text)) return null;

  const insecure = extractInsecureUrl(text);
  if (!insecure || PASSIVE_ASSET.test(insecure)) return null;

  try {
    return new URL(insecure).origin;
  } catch {
    return null; // unparseable URL in the console text — nothing to record
  }
}

function recordIfInsecureContentBlocked(text) {
  const origin = blockedActiveInsecureOrigin(text);
  if (!origin || blockedInsecureOrigins.has(origin)) return;

  blockedInsecureOrigins.add(origin);
  printMixedContentWarning(origin);
}

export function printMixedContentWarning(origin) {
  const engine = activeBrowserName || getBrowserName();
  const browser = engine === 'webkit' ? 'Safari (WebKit)' : engine;
  // Only guess at the cause when the origin actually looks like a local dev server.
  const isLocalDevServer = /^https?:\/\/(localhost|127\.0\.0\.1)(:|$)/i.test(origin);

  console.log(`
  ╭─────────────────────────────────────────────────────────────────────────────╮
  │  ⚠️   ${browser} blocked a script or stylesheet as mixed content
  ├─────────────────────────────────────────────────────────────────────────────┤
  │  Blocked origin:  ${origin}
  │
  │  The page is HTTPS but this asset is plain HTTP, so the browser refused it.
  │  WebKit blocks such requests outright; Chromium and Firefox exempt localhost
  │  as a trustworthy origin and load them, which is why engines disagree here.
  │
  │  If the blocked asset is part of the admin bundle, Vue will not mount, no
  │  REST call is made, and table tests report "Initial API ... no response".${isLocalDevServer ? `
  │
  │  That origin is a local dev server — the target site is most likely running
  │  FluentCart with  'env' => 'dev'  (config/app.php), which enqueues admin
  │  assets from Vite instead of the built files in assets/.
  │
  │  Fix — on the TARGET site (the one you pointed WP_URL at):
  │      1. set  'env' => 'production'  in config/app.php
  │      2. run  npm run build
  │  Or run that site's tests in Chromium or Firefox:  npm test -- playwright --firefox` : `
  │
  │  Serve that asset over HTTPS, or point the tests at a site that does.`}
  ╰─────────────────────────────────────────────────────────────────────────────╯
`);
}

function attachMonitor(page, label = '') {
  const prefix = label ? `[${label}] ` : '';
  const isIgnorableUrl = (url = '') => url.includes('/favicon.ico');
  const isIgnorableRequestFailure = (req) => {
    const url = req.url();
    const errorText = req.failure()?.errorText || '';
    if (isIgnorableUrl(url)) return true;
    if (!url.includes('/wp-admin/admin-ajax.php')) return false;
    if (!errorText.includes('ERR_ABORTED')) return false;

    const pageUrl = page.url();
    return !pageUrl.includes('page=fluent-cart');
  };

  page.on('response', res => {
    const url = res.url();
    const status = res.status();
    if (isIgnorableUrl(url)) return;
    if ((url.includes('fluent-cart') || url.includes('fluent_cart') || url.includes('fct_')) && status >= 400) {
      const entry = `${prefix}HTTP ${status}: ${res.request().method()} ${url.split('?')[0]}`;
      networkErrors.push(entry);
      console.log(`  ⚠️  ${entry}`);
    }
  });

  page.on('requestfailed', req => {
    const url = req.url();
    if (isIgnorableRequestFailure(req)) return;
    if (url.includes('fluent-cart') || url.includes('fluent_cart') || url.includes('admin-ajax')) {
      const entry = `${prefix}NETWORK FAIL: ${req.method()} ${url.split('?')[0]} — ${req.failure()?.errorText || 'unknown'}`;
      networkErrors.push(entry);
      console.log(`  ⚠️  ${entry}`);
    }
  });

  // Only track errors from our site — skip external domains (google, stripe, paypal, etc.)
  const isOurUrl = (url) => {
    try {
      const host = new URL(url).hostname;
      const pageHost = new URL(page.url()).hostname;
      return host === pageHost;
    } catch { return false; }
  };

  page.on('console', msg => {
    // Mixed-content blocks arrive as warnings, not errors — check every message.
    recordIfInsecureContentBlocked(msg.text());

    if (msg.type() === 'error') {
      const text = msg.text();
      const location = msg.location()?.url || '';

      // Skip external and dev noise
      if (!isOurUrl(location) && location) return;
      if (isIgnorableUrl(location)) return;
      if (text.includes('stripe.com') || text.includes('paypal.com') || text.includes('Dynamic Dark Icon')
        || text.includes('MIME type') || text.includes('module script') || text.includes('google.com')
        || text.includes('favicon') || text.includes('hcaptcha')) return;

      const url = location ? ` @ ${location}` : '';
      const entry = `${prefix}CONSOLE: ${text.substring(0, 200)}${url}`;
      consoleErrors.push(entry);
      console.log(`  ⚠️  ${entry}`);
    }
  });

  // Auto-dismiss Vite error overlays
  page.on('load', () => {
    page.evaluate(() => {
      window.__fctViteOverlayObserver__?.disconnect();

      const observer = new MutationObserver(() => {
        const o = document.querySelector('vite-error-overlay');
        if (o) {
          o.remove();
        }
      });

      observer.observe(document.body || document.documentElement, { childList: true, subtree: true });
      window.__fctViteOverlayObserver__ = observer;
    }).catch(() => {});
  });
}

export async function createPage(browser, label = '') {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  attachMonitor(page, label);
  return page;
}

export async function createIncognitoPage(browser, label = 'Guest') {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  attachMonitor(page, label);
  return { page, context: ctx };
}

// ─── Login ───────────────────────────────────────────────────────────────────

export async function login(page, baseUrl, user, pass) {
  await page.goto(`${baseUrl}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin/);
}

// ─── Utilities ───────────────────────────────────────────────────────────────

export function ensureScreenshotDir() {
  mkdirSync('.playwright-mcp/test-results', { recursive: true });
}

export function printMonitorSummary() {
  if (networkErrors.length > 0) {
    console.log(`\n⚠️  Network errors (${networkErrors.length}):`);
    networkErrors.forEach(e => console.log(`   - ${e}`));
  }
  if (consoleErrors.length > 0) {
    console.log(`\n⚠️  Console errors (${consoleErrors.length}):`);
    consoleErrors.forEach(e => console.log(`   - ${e}`));
  }
  // Repeat the mixed-content banner at the end — during a full run the first one
  // scrolls far above the failure list, and it explains every one of those failures.
  blockedInsecureOrigins.forEach(printMixedContentWarning);
}
