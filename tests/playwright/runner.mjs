/**
 * Test Runner — FluentCart Browser Tests
 *
 * Usage:
 *   npm test -- playwright                            # Run every suite (chromium)
 *   npm test -- playwright admin/orders               # Run one suite
 *   npm test -- playwright admin/orders/search        # Run one test
 *   npm test -- playwright admin/orders --firefox     # Another engine: --firefox or --safari
 *   npm test -- playwright admin/orders --headed      # Show the browser (any engine)
 *   npm test -- playwright status                     # List suites and tests
 *
 * Runs headless unless --headed (or HEADED=true) is passed.
 * Firefox and Safari need a one-off engine download:  npm run test:playwright:install
 *
 * Env:
 *   HEADED=true        Show browser (headed mode — sequential + dialogs); same as --headed
 *   BROWSER=firefox    Engine to drive (chromium | firefox | safari; default chromium)
 *   PACE=500           ms pause between steps (headed default: 1500)
 *   MAX_BROWSERS=1     Max parallel browser instances in headless mode (default: 1)
 */

import BaseTest from './base-test.mjs';
import { getConfig, launchBrowser, getBrowserName, createPage, login, ensureScreenshotDir, printMonitorSummary } from './setup.mjs';
import { createServer } from 'http';
import { writeFileSync, readFileSync, existsSync, mkdirSync, readdirSync, unlinkSync } from 'fs';
import { fileURLToPath, pathToFileURL } from 'url';
import { dirname, join, relative, sep } from 'path';
import { execSync, spawn } from 'child_process';

const ROOT = dirname(fileURLToPath(import.meta.url));
const OUTPUT_DIR = join(ROOT, '_output');
let _runStartMs = 0;

// ─── Test Registry ────────────────────────────────────────────────────────────

// A folder with an index.mjs is a suite: a full run executes the classes it exports.
// Every *.test.mjs runs alone by its path, so admin/orders/search.test.mjs is
// "admin/orders/search". A test file the index leaves out runs only by name.
const NOT_TESTS = new Set(['_output', 'helpers', 'node_modules']);
const suites = {};
const testMap = {};

(function discover(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
    const file = join(dir, entry.name);
    const key = relative(ROOT, file).split(sep).join('/');
    if (entry.isDirectory()) {
      if (!NOT_TESTS.has(entry.name)) discover(file);
    } else if (entry.name === 'index.mjs' && dir !== ROOT) {
      suites[dirname(key)] = () => import(pathToFileURL(file).href);
    } else if (entry.name.endsWith('.test.mjs')) {
      testMap[key.slice(0, -'.test.mjs'.length)] = () => import(pathToFileURL(file).href).then(m => m.default);
    }
  }
})(ROOT);

// Tests of one suite only, so admin/products does not list admin/products/edit/*.
const testsOf = (suiteName) => Object.keys(testMap).filter(k => k.slice(0, k.lastIndexOf('/')) === suiteName);

// ─── Dashboard helpers ────────────────────────────────────────────────────────

function saveResults() {
  try {
    const runsDir = join(OUTPUT_DIR, 'runs');
    mkdirSync(runsDir, { recursive: true });
    const { passed, failed, skipped, total } = BaseTest.summary;
    const data = {
      timestamp: new Date().toISOString(),
      passed,
      failed,
      skipped,
      total,
      duration: _runStartMs ? (Date.now() - _runStartMs) / 1000 : 0,
      results: BaseTest._results,
    };
    const ts = new Date().toISOString().replace(/:/g, '-').replace(/\..+/, '').replace('T', '_');
    writeFileSync(join(runsDir, 'run-' + ts + '.json'), JSON.stringify(data, null, 2));
    writeFileSync(join(OUTPUT_DIR, 'results.json'), JSON.stringify(data, null, 2));
  } catch {}
}

function cmdStatus() {
  const suiteList = Object.entries(suites);
  console.log('\n\x1b[1m  Browser Test Registry\x1b[0m');
  console.log('  ' + '─'.repeat(60));
  let total = 0;
  for (const [suiteName] of suiteList) {
    const tests = testsOf(suiteName);
    total += tests.length;
    console.log('\n  \x1b[36m' + suiteName + '\x1b[0m  \x1b[90m(' + tests.length + ' tests)\x1b[0m');
    for (const t of tests) console.log('    \x1b[90m' + t + '\x1b[0m');
  }
  console.log('\n  ' + '─'.repeat(60));
  console.log('  Total: ' + suiteList.length + ' suites, ' + total + ' tests');
  const rp = join(OUTPUT_DIR, 'results.json');
  if (existsSync(rp)) {
    const d = JSON.parse(readFileSync(rp, 'utf8'));
    const ok = d.failed === 0;
    console.log('\n  Last Run: ' + new Date(d.timestamp).toLocaleString());
    console.log('  Result: ' + (ok ? '\x1b[32m✔ PASS\x1b[0m' : '\x1b[31m✘ FAIL\x1b[0m') +
      ' — ' + d.passed + ' passed, ' + d.failed + ' failed (' + d.duration.toFixed(1) + 's)');
  } else {
    console.log('\n  \x1b[90mNo results yet — run tests first\x1b[0m');
  }
  console.log('');
}

function cmdServe(port) {
  mkdirSync(OUTPUT_DIR, { recursive: true });

  // ── Run state ──
  const runnerPath = join(OUTPUT_DIR, '..', 'runner.mjs');
  const projectRoot = join(OUTPUT_DIR, '..', '..', '..');
  let runState = { running: false, filter: '', lines: [], exitCode: null, startTime: null, paused: false, pauseData: null };
  let sseClients = [];

  // Read tests/playwright/.env for WP credentials fallback
  const envFilePath = join(OUTPUT_DIR, '..', '.env');

  function readEnvFile() {
    const out = {};
    if (existsSync(envFilePath)) {
      try {
        for (const line of readFileSync(envFilePath, 'utf8').split('\n')) {
          const t = line.trim();
          if (!t || t.startsWith('#')) continue;
          const eq = t.indexOf('=');
          if (eq > 0) out[t.slice(0, eq).trim()] = t.slice(eq + 1).trim();
        }
      } catch {}
    }
    return out;
  }

  function readAllRuns() {
    const runsDir = join(OUTPUT_DIR, 'runs');
    if (!existsSync(runsDir)) return [];
    try {
      return readdirSync(runsDir)
        .filter(f => f.startsWith('run-') && f.endsWith('.json'))
        .sort().reverse()
        .slice(0, 50)
        .map(f => {
          try { return { filename: f, ...JSON.parse(readFileSync(join(runsDir, f), 'utf8')) }; }
          catch { return null; }
        })
        .filter(Boolean);
    } catch { return []; }
  }

  function writeEnvFile() {
    try {
      writeFileSync(envFilePath,
        'WP_URL=' + settings.wpUrl + '\n' +
        'WP_USER=' + settings.wpUser + '\n' +
        'WP_PASS=' + settings.wpPass + '\n'
      );
    } catch {}
  }

  const _initEnv = readEnvFile();
  let settings = {
    headed:             false,
    pauseBetweenSuites: false,
    wpUrl:  process.env.WP_URL  || _initEnv.WP_URL  || 'https://wp.test',
    wpUser: process.env.WP_USER || _initEnv.WP_USER || 'admin',
    wpPass: process.env.WP_PASS || _initEnv.WP_PASS || '',
  };

  function emit(event) {
    const data = 'data: ' + JSON.stringify(event) + '\n\n';
    sseClients = sseClients.filter(res => { try { res.write(data); return true; } catch { return false; } });
  }

  let activeChild = null;

  function startRun(filter) {
    if (runState.running) return false;
    const args = filter ? [runnerPath, filter] : [runnerPath];
    const child = spawn('node', args, {
      cwd: projectRoot,
      stdio: ['pipe', 'pipe', 'pipe'],
      env: Object.assign({}, process.env, {
        WP_URL:           settings.wpUrl,
        WP_USER:          settings.wpUser,
        WP_PASS:          settings.wpPass,
        HEADED:           settings.headed ? '1' : '0',
        CI:               'true',
        FCT_SUITE_PAUSE:  settings.headed && settings.pauseBetweenSuites ? '1' : '0',
      }),
    });
    activeChild = child;
    runState = { running: true, filter, lines: [], exitCode: null, startTime: Date.now(), paused: false, pauseData: null };
    emit({ type: 'start', filter });
    const onData = (data) => {
      const clean = data.toString().replace(/\x1b\[[0-9;]*[mGKHJF]/g, '').replace(/\r/g, '');
      for (const line of clean.split('\n')) {
        if (!line.trim()) continue;
        if (line.startsWith('__FCT_PAUSE__:')) {
          try {
            const pd = JSON.parse(line.slice('__FCT_PAUSE__:'.length));
            runState.paused = true;
            runState.pauseData = pd;
            emit({ type: 'pause', ...pd });
          } catch {}
          continue;
        }
        runState.lines.push(line);
        emit({ type: 'line', text: line });
      }
    };
    child.stdout.on('data', onData);
    child.stderr.on('data', onData);
    child.on('close', (code) => {
      activeChild = null;
      runState.running = false;
      runState.exitCode = code;
      emit({ type: 'done', exitCode: code, duration: (Date.now() - runState.startTime) / 1000 });
    });
    return true;
  }

  function stopRun() {
    if (!activeChild) return false;
    activeChild.kill('SIGTERM');
    return true;
  }

  function buildTree() {
    const tree = {};
    for (const s of Object.keys(suites)) {
      tree[s] = testsOf(s);
    }
    return tree;
  }

  function generateHTML() {
    const tree = buildTree();
    const totalTests = Object.values(tree).reduce((n, a) => n + a.length, 0);
    const totalSuites = Object.keys(tree).length;

    const suiteRows = Object.entries(tree).map(([s, tests]) =>
      '<tr><td><strong>' + s + '</strong></td><td>' + tests.length + '</td><td>' +
      tests.map(t =>
        '<span class="run-tag" onclick="runTests(\'' + s + '/' + t.replace(s + '/', '') + '\')">' +
        t.replace(s + '/', '') + '</span>'
      ).join(' ') +
      '</td><td><button class="run-btn" onclick="runTests(\'' + s + '\')">&#9654; Run</button></td></tr>'
    ).join('');

    const escHtml = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const renderItems = (results, runId) => {
      if (!results || !results.length) return '<div style="color:#475569;padding:0.75rem 1rem;font-size:0.85rem">No assertion details</div>';
      const suiteSet = [...new Set(results.map(r => r.suite || '').filter(Boolean))];
      const filterBar = '<div class="rf-bar" id="rf-' + runId + '">' +
        '<span class="rf-group">' +
        '<button class="rf-btn rf-status active" data-rid="' + runId + '" data-status="ALL" onclick="rfClick(this)">All</button>' +
        '<button class="rf-btn rf-status rf-pass" data-rid="' + runId + '" data-status="PASS" onclick="rfClick(this)">Pass</button>' +
        '<button class="rf-btn rf-status rf-fail" data-rid="' + runId + '" data-status="FAIL" onclick="rfClick(this)">Fail</button>' +
        '</span>' +
        (suiteSet.length > 1 ? '<span class="rf-group">' +
          '<button class="rf-btn rf-suite active" data-rid="' + runId + '" data-suite="ALL" onclick="rfClick(this)">All suites</button>' +
          suiteSet.map(s => '<button class="rf-btn rf-suite" data-rid="' + runId + '" data-suite="' + escHtml(s) + '" onclick="rfClick(this)">' + escHtml(s.replace(/^admin\//, '')) + '</button>').join('') +
          '</span>' : '') +
        '</div>';
      const items = results.map(r => {
        const pass = r.status === 'PASS';
        const badge = pass ? '<span class="badge badge-pass">PASS</span>' : '<span class="badge badge-fail">FAIL</span>';
        const nameColor = pass ? '#94a3b8' : '#fca5a5';
        const detail = r.detail ? escHtml(r.detail) : '';
        const attrs = ' data-rid="' + runId + '" data-status="' + r.status + '" data-suite="' + escHtml(r.suite || '') + '"';
        if (detail) {
          return '<details class="r-item"' + (pass ? '' : ' open') + attrs + '>' +
            '<summary>' + badge +
            '<span class="r-name" style="color:' + nameColor + '">' + escHtml(r.name) + '</span>' +
            '<span class="r-chevron">&#9654;</span></summary>' +
            '<div class="r-body">' + detail + '</div>' +
            '</details>';
        }
        return '<div class="r-item-flat"' + attrs + '>' + badge +
          '<span class="r-name" style="color:' + nameColor + '">' + escHtml(r.name) + '</span></div>';
      }).join('');
      return filterBar + items;
    };

    const allRuns = readAllRuns();
    const historyContent = allRuns.length
      ? allRuns.map((r, ri) => {
          const pass = r.failed === 0;
          const runId = 'r' + ri;
          const statusBadge = pass
            ? '<span class="badge badge-pass">All Passed</span>'
            : '<span class="badge badge-fail">' + r.failed + ' Failed</span>';
          const ts = new Date(r.timestamp).toLocaleString();
          return '<details class="h-run">' +
            '<summary>' +
            '<span class="h-ts">' + ts + '</span>' +
            '<span class="h-stats">' + (r.total || 0) + ' tests &bull; ' + (r.passed || 0) + ' passed' + (r.failed ? ' &bull; ' + r.failed + ' failed' : '') + (r.skipped ? ' &bull; ' + r.skipped + ' skipped' : '') + '</span>' +
            statusBadge +
            '<span class="h-dur">' + ((r.duration || 0)).toFixed(1) + 's</span>' +
            '<button class="h-delete" onclick="deleteRun(\'' + escHtml(r.filename) + '\',event)" title="Delete this run">&#128465;</button>' +
            '<span class="h-chevron">&#9654;</span>' +
            '</summary>' +
            '<div class="h-body">' +
            '<div class="run-cards">' +
            '<div class="run-card"><div class="run-card-label">Tests Run</div><div class="run-card-value rcv-blue">' + (r.total || 0) + '</div><div class="run-card-sub">' + ((r.duration || 0)).toFixed(1) + 's</div></div>' +
            '<div class="run-card"><div class="run-card-label">Passed</div><div class="run-card-value rcv-green">' + (r.passed || 0) + '</div></div>' +
            '<div class="run-card"><div class="run-card-label">Failed</div><div class="run-card-value ' + (r.failed > 0 ? 'rcv-amber' : 'rcv-green') + '">' + (r.failed || 0) + '</div></div>' +
            '<div class="run-card"><div class="run-card-label">Result</div><div class="run-card-value ' + (r.failed === 0 ? 'rcv-green' : 'rcv-amber') + '">' + (r.failed === 0 ? 'PASS' : 'FAIL') + '</div></div>' +
            '</div>' +
            renderItems(r.results, runId) + '</div>' +
            '</details>';
        }).join('')
      : '<div style="color:#475569;text-align:center;padding:2rem">No run history yet — run tests to see results here</div>';

    const cmdRows = [
      ['Run all (headless)', 'node tests/playwright/runner.mjs'],
      ['Run all (headed)',   'HEADED=true node tests/playwright/runner.mjs'],
      ...Object.keys(tree).map(s => ['Run ' + s, 'node tests/playwright/runner.mjs ' + s]),
      ['Show registry',     'node tests/playwright/runner.mjs status'],
      ['Open dashboard',    'node tests/playwright/runner.mjs serve'],
    ].map(([label, cmd]) =>
      '<div class="cmd-row"><span class="cmd-label">' + label + '</span>' +
      '<span class="cmd" onclick="copyCmd(\'' + cmd.replace(/'/g, "\\'") + '\')">' + cmd + '</span></div>'
    ).join('');

    const pauseChecked = settings.pauseBetweenSuites ? ' checked' : '';
    const pauseDisabled = settings.headed ? '' : ' disabled';

    const CSS = [
      '* { margin:0; padding:0; box-sizing:border-box; }',
      'body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:#0f172a; color:#e2e8f0; padding:2rem; max-width:1100px; margin:0 auto; }',
      'h1 { font-size:1.5rem; margin-bottom:0.25rem; color:#f8fafc; }',
      '.header { display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem; flex-wrap:wrap; gap:0.75rem; }',
      '.header-right { display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap; }',
      '.live-badge { background:#166534; color:#4ade80; font-size:0.7rem; padding:0.2rem 0.6rem; border-radius:9999px; font-weight:600; display:inline-flex; align-items:center; gap:0.3rem; }',
      '.live-dot { width:6px; height:6px; background:#4ade80; border-radius:50%; animation:pulse 2s infinite; }',
      '@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }',
      '.run-badge { font-size:0.75rem; padding:0.25rem 0.7rem; border-radius:9999px; font-weight:600; }',
      '.run-badge.idle    { background:#1e293b; color:#64748b; }',
      '.run-badge.running { background:#1e3a5f; color:#93c5fd; }',
      '.run-badge.paused  { background:#422006; color:#fbbf24; }',
      '.run-badge.passed  { background:#166534; color:#4ade80; }',
      '.run-badge.failed  { background:#450a0a; color:#fca5a5; }',
      '.run-btn-primary { background:#3b82f6; color:#fff; border:none; border-radius:8px; padding:0.5rem 1.1rem; font-size:0.85rem; font-weight:600; cursor:pointer; }',
      '.run-btn-primary:hover:not(:disabled) { background:#2563eb; }',
      '.run-btn-primary:disabled { opacity:0.4; cursor:default; }',
      '.stop-btn { background:#450a0a; color:#fca5a5; border:1px solid #7f1d1d; border-radius:8px; padding:0.5rem 1.1rem; font-size:0.85rem; font-weight:600; cursor:pointer; display:none; }',
      '.stop-btn:hover { background:#7f1d1d; }',
      '.settings-bar { display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem; padding:0.6rem 1rem; background:#1e293b; border-radius:10px; flex-wrap:wrap; }',
      '.s-label { font-size:0.72rem; color:#64748b; text-transform:uppercase; letter-spacing:0.06em; white-space:nowrap; }',
      '.s-input { background:#0f172a; border:1px solid #334155; border-radius:6px; color:#e2e8f0; padding:0.3rem 0.6rem; font-size:0.82rem; outline:none; }',
      '.s-input:focus { border-color:#3b82f6; }',
      '.s-url { width:165px; } .s-user { width:85px; } .s-pass { width:100px; }',
      '.s-save { background:#334155; color:#94a3b8; border:none; border-radius:6px; padding:0.3rem 0.75rem; font-size:0.8rem; cursor:pointer; }',
      '.s-save:hover { background:#475569; color:#e2e8f0; }',
      '.s-divider { color:#334155; }',
      '.s-toggle { display:inline-flex; align-items:center; gap:0.35rem; font-size:0.82rem; color:#94a3b8; cursor:pointer; user-select:none; }',
      '.s-toggle input { accent-color:#3b82f6; cursor:pointer; }',
      '.s-toggle input:disabled { opacity:0.4; cursor:default; }',
      '.s-toggle.muted { color:#475569; }',
      '.tabs-nav { display:flex; gap:0.25rem; margin-bottom:1.5rem; border-bottom:1px solid #1e293b; padding-bottom:0; }',
      '.tab-btn { background:transparent; color:#64748b; border:none; border-bottom:2px solid transparent; padding:0.5rem 1rem; font-size:0.88rem; font-weight:600; cursor:pointer; margin-bottom:-1px; }',
      '.tab-btn:hover { color:#94a3b8; }',
      '.tab-btn.active { color:#e2e8f0; border-bottom-color:#3b82f6; }',
      '.section { background:#1e293b; border-radius:12px; padding:1.5rem; margin-bottom:2rem; }',
      '.section h2 { font-size:1.1rem; margin-bottom:1rem; color:#f8fafc; }',
      '.run-cards { display:flex; gap:1rem; padding:1rem 1rem 0; flex-wrap:wrap; }',
      '.run-card { background:#1e293b; border-radius:10px; padding:1rem 1.25rem; flex:1; min-width:120px; }',
      '.run-card-label { font-size:0.7rem; color:#64748b; text-transform:uppercase; letter-spacing:0.06em; }',
      '.run-card-value { font-size:1.6rem; font-weight:700; margin-top:0.2rem; }',
      '.run-card-sub { font-size:0.7rem; color:#475569; margin-top:0.15rem; }',
      '.rcv-green{color:#4ade80} .rcv-blue{color:#60a5fa} .rcv-amber{color:#fbbf24}',
      'table { width:100%; border-collapse:collapse; }',
      'th { text-align:left; padding:0.6rem 1rem; color:#94a3b8; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #334155; }',
      'td { padding:0.6rem 1rem; border-bottom:1px solid #0f172a; font-size:0.9rem; vertical-align:middle; }',
      'tr:hover { background:#334155; }',
      '.run-tag { display:inline-block; background:#0f172a; color:#7dd3fc; font-size:0.7rem; padding:0.15rem 0.5rem; border-radius:4px; margin:0.1rem; cursor:pointer; border:1px solid transparent; }',
      '.run-tag:hover { border-color:#3b82f6; }',
      '.run-tag.disabled { pointer-events:none; color:#475569; }',
      '.run-btn { background:#1e3a5f; color:#93c5fd; border:1px solid #1e40af; border-radius:6px; padding:0.25rem 0.7rem; font-size:0.8rem; cursor:pointer; white-space:nowrap; }',
      '.run-btn:hover:not(:disabled) { background:#1d4ed8; color:#fff; }',
      '.run-btn:disabled { opacity:0.4; cursor:default; }',
      '.badge { display:inline-block; padding:0.15rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:600; flex-shrink:0; }',
      '.badge-pass{background:#166534;color:#4ade80} .badge-fail{background:#450a0a;color:#fca5a5}',
      'details.h-run { border-bottom:1px solid #0f172a; }',
      'details.h-run summary { display:flex; align-items:center; gap:0.75rem; padding:0.9rem 1rem; cursor:pointer; list-style:none; user-select:none; flex-wrap:wrap; }',
      'details.h-run summary::-webkit-details-marker { display:none; }',
      'details.h-run summary:hover { background:#263348; }',
      '.h-ts { font-size:0.82rem; font-weight:600; color:#e2e8f0; flex-shrink:0; }',
      '.h-stats { font-size:0.78rem; color:#64748b; flex:1; }',
      '.h-dur { font-size:0.75rem; color:#475569; flex-shrink:0; }',
      '.h-chevron { font-size:0.55rem; color:#475569; transition:transform 0.15s; flex-shrink:0; }',
      'details.h-run[open] .h-chevron { transform:rotate(90deg); color:#94a3b8; }',
      '.h-body { padding:0; background:#0f172a; }',
      '.h-delete { background:transparent; color:#475569; border:none; cursor:pointer; font-size:0.9rem; padding:0.1rem 0.3rem; border-radius:4px; flex-shrink:0; }',
      '.h-delete:hover { color:#f87171; background:#450a0a; }',
      'details.r-item { border-bottom:1px solid #0f172a; }',
      'details.r-item summary { display:flex; align-items:center; gap:0.65rem; padding:0.55rem 1rem; cursor:pointer; list-style:none; user-select:none; }',
      'details.r-item summary::-webkit-details-marker { display:none; }',
      'details.r-item summary:hover { background:#263348; }',
      '.r-name { flex:1; font-size:0.88rem; }',
      '.r-chevron { font-size:0.55rem; color:#475569; margin-left:auto; transition:transform 0.15s; }',
      'details.r-item[open] .r-chevron { transform:rotate(90deg); color:#94a3b8; }',
      '.r-body { padding:0.4rem 1rem 0.7rem 3.2rem; font-size:0.8rem; color:#64748b; line-height:1.6; border-bottom:1px solid #0f172a; }',
      '.r-item-flat { display:flex; align-items:center; gap:0.65rem; padding:0.55rem 1rem; border-bottom:1px solid #0f172a; font-size:0.88rem; }',
      '.r-item-flat[hidden],.r-item[hidden] { display:none; }',
      '.rf-bar { display:flex; align-items:center; gap:0.5rem; padding:0.6rem 1rem; background:#111827; border-bottom:1px solid #0f172a; flex-wrap:wrap; }',
      '.rf-group { display:flex; gap:0.3rem; }',
      '.rf-btn { background:#1e293b; color:#64748b; border:1px solid #334155; border-radius:6px; padding:0.2rem 0.6rem; font-size:0.72rem; font-weight:600; cursor:pointer; white-space:nowrap; }',
      '.rf-btn:hover { color:#94a3b8; border-color:#475569; }',
      '.rf-btn.active { border-color:#3b82f6; color:#93c5fd; background:#1e3a5f; }',
      '.rf-pass.active { border-color:#166534; color:#4ade80; background:#052e16; }',
      '.rf-fail.active { border-color:#7f1d1d; color:#fca5a5; background:#450a0a; }',
      '.terminal { background:#0a0f1a; border-radius:8px; padding:1rem 1.2rem; font-family:"SF Mono",Monaco,"Cascadia Code",monospace; font-size:0.78rem; line-height:1.7; color:#c0caf5; max-height:420px; overflow-y:auto; white-space:pre-wrap; word-break:break-all; }',
      '.terminal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }',
      '.clear-btn { background:transparent; color:#475569; border:1px solid #334155; border-radius:6px; padding:0.2rem 0.6rem; font-size:0.75rem; cursor:pointer; }',
      '.clear-btn:hover { color:#94a3b8; border-color:#475569; }',
      '.cmd { font-family:"SF Mono",Monaco,monospace; font-size:0.8rem; background:#0f172a; padding:0.3rem 0.6rem; border-radius:6px; display:inline-block; color:#7dd3fc; cursor:pointer; border:1px solid #1e293b; white-space:nowrap; }',
      '.cmd:hover { border-color:#3b82f6; }',
      '.cmd-row { display:flex; justify-content:space-between; align-items:center; padding:0.5rem 0; border-bottom:1px solid #0f172a; gap:1rem; }',
      '.cmd-label { color:#94a3b8; font-size:0.85rem; }',
      '.toast { position:fixed; bottom:2rem; right:2rem; background:#166534; color:#4ade80; padding:0.6rem 1.2rem; border-radius:8px; font-size:0.85rem; opacity:0; transition:opacity 0.3s; pointer-events:none; }',
      '.toast.show{opacity:1}',
      '.timestamp { color:#475569; font-size:0.75rem; text-align:center; margin-top:2rem; }',
      // Pause modal
      '.pause-overlay { position:fixed; inset:0; z-index:9999; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.65); backdrop-filter:blur(4px); }',
      '.pause-card { background:#1a2236; border:1px solid #334155; border-radius:14px; padding:2rem; width:480px; max-width:90vw; box-shadow:0 24px 60px rgba(0,0,0,0.5); }',
      '.pause-card h3 { font-size:1.1rem; color:#f1f5f9; margin-bottom:0.25rem; }',
      '.pause-stats { font-size:0.85rem; color:#64748b; margin-bottom:1rem; }',
      '.pause-results { max-height:220px; overflow-y:auto; background:#0f172a; border-radius:8px; padding:0.6rem 0.9rem; margin-bottom:1rem; font-size:0.8rem; line-height:1.8; }',
      '.pause-next { font-size:0.8rem; color:#3b82f6; margin-bottom:1.2rem; font-weight:600; }',
      '.pause-actions { display:flex; gap:0.75rem; }',
      '.continue-btn { flex:1; background:#3b82f6; color:#fff; border:none; border-radius:8px; padding:0.6rem 1rem; font-size:0.9rem; font-weight:700; cursor:pointer; }',
      '.continue-btn:hover { background:#2563eb; }',
      '.pause-stop-btn { background:transparent; color:#f87171; border:1px solid #450a0a; border-radius:8px; padding:0.6rem 1rem; font-size:0.9rem; cursor:pointer; }',
      '.pause-stop-btn:hover { background:#450a0a; }',
    ].join('\n');

    const JS = [
      'var es=null;',
      'function showTab(name){',
      '  document.querySelectorAll(".tab-panel").forEach(function(p){p.style.display=p.id==="tab-"+name?"block":"none";});',
      '  document.querySelectorAll(".tab-btn").forEach(function(b){b.classList.toggle("active",b.dataset.tab===name);});',
      '}',
      'function setBusy(busy){',
      '  document.querySelectorAll(".run-btn,.run-btn-primary").forEach(function(b){b.disabled=busy;});',
      '  document.querySelectorAll(".run-tag").forEach(function(t){t.classList.toggle("disabled",busy);});',
      '  var s=document.getElementById("stop-btn");if(s)s.style.display=busy?"inline-block":"none";',
      '}',
      'function stopTests(){',
      '  fetch("/api/stop",{method:"POST"});',
      '  document.getElementById("pause-overlay").style.display="none";',
      '  var badge=document.getElementById("run-badge");',
      '  if(badge){badge.textContent="Stopping\\u2026";badge.className="run-badge idle";}',
      '  setBusy(false);',
      '}',
      'function handlePause(data){',
      '  var badge=document.getElementById("run-badge");',
      '  if(badge){badge.textContent="\\u23f8 Paused";badge.className="run-badge paused";}',
      '  document.getElementById("pause-suite").textContent="Suite: "+data.suite;',
      '  document.getElementById("pause-stats").textContent=(data.passed||0)+" passed, "+(data.failed||0)+" failed";',
      '  var res=document.getElementById("pause-results");',
      '  res.innerHTML=(data.results||[]).map(function(r){',
      '    return\'<div style="color:\'+(r.status==="PASS"?"#94a3b8":"#fca5a5")+\'">\'+(r.status==="PASS"?"\\u2705":"\\u274c")+" "+r.name+(r.detail?" \\u2014 "+r.detail:"")+"</div>";',
      '  }).join("");',
      '  document.getElementById("pause-next").textContent=data.nextSuite?"Next: "+data.nextSuite:"";',
      '  document.getElementById("pause-overlay").style.display="flex";',
      '}',
      'function resumeRun(){',
      '  document.getElementById("pause-overlay").style.display="none";',
      '  var badge=document.getElementById("run-badge");',
      '  if(badge){badge.textContent="\\u27f3 Running\\u2026";badge.className="run-badge running";}',
      '  setBusy(true);',
      '  fetch("/api/continue",{method:"POST"});',
      '}',
      'function attachSSE(){',
      '  if(es)es.close();',
      '  es=new EventSource("/events");',
      '  es.onmessage=function(e){',
      '    var ev=JSON.parse(e.data),term=document.getElementById("terminal");',
      '    if(ev.type==="line"){if(term){term.textContent+=ev.text+"\\n";term.scrollTop=term.scrollHeight;}}',
      '    else if(ev.type==="pause"){handlePause(ev);}',
      '    else if(ev.type==="resumed"){',
      '      document.getElementById("pause-overlay").style.display="none";',
      '      var badge=document.getElementById("run-badge");',
      '      if(badge){badge.textContent="\\u27f3 Running\\u2026";badge.className="run-badge running";}',
      '      setBusy(true);',
      '    }',
      '    else if(ev.type==="done"){',
      '      es.close();es=null;',
      '      document.getElementById("pause-overlay").style.display="none";',
      '      var badge=document.getElementById("run-badge"),ok=ev.exitCode===0;',
      '      badge.textContent=ok?"\\u2714 Passed":"\\u2718 Failed";',
      '      badge.className="run-badge "+(ok?"passed":"failed");',
      '      setBusy(false);',
      '      sessionStorage.setItem("fct-tab","history");',
      '      setTimeout(function(){location.reload();},2500);',
      '    }',
      '  };',
      '  es.onerror=function(){if(es){es.close();es=null;}setBusy(false);};',
      '}',
      'function runTests(filter){',
      '  showTab("tests");',
      '  var section=document.getElementById("output-section"),term=document.getElementById("terminal"),',
      '      title=document.getElementById("output-title"),badge=document.getElementById("run-badge");',
      '  if(term)term.textContent="";',
      '  if(section)section.style.display="block";',
      '  if(title)title.textContent="Running: "+(filter||"all");',
      '  badge.textContent="\\u27f3 Running\\u2026";badge.className="run-badge running";',
      '  setBusy(true);attachSSE();',
      '  fetch("/api/run",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({filter:filter})})',
      '    .then(function(r){',
      '      if(!r.ok){r.text().then(function(t){var term=document.getElementById("terminal");if(term)term.textContent+="\\n[Error "+r.status+": "+t+"]\\n";});}',
      '    });',
      '}',
      'function saveSettings(){',
      '  var headedCb=document.getElementById("headed-cb"),pauseCb=document.getElementById("pause-cb");',
      '  var headed=!!(headedCb||{}).checked;',
      '  if(pauseCb)pauseCb.disabled=!headed;',
      '  fetch("/api/settings",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({',
      '    headed:headed,',
      '    pauseBetweenSuites:!!(pauseCb&&pauseCb.checked),',
      '    wpUrl:(document.getElementById("s-url")||{}).value||"",',
      '    wpUser:(document.getElementById("s-user")||{}).value||"",',
      '    wpPass:(document.getElementById("s-pass")||{}).value,',
      '  })});',
      '}',
      'var _rf={};',
      'function rfClick(btn){',
      '  var rid=btn.dataset.rid;',
      '  if(!_rf[rid])_rf[rid]={status:"ALL",suite:"ALL"};',
      '  if(btn.dataset.status){',
      '    _rf[rid].status=btn.dataset.status;',
      '    document.querySelectorAll(".rf-status[data-rid=\'"+rid+"\']").forEach(function(b){b.classList.toggle("active",b.dataset.status===btn.dataset.status);});',
      '  }',
      '  if(btn.dataset.suite){',
      '    _rf[rid].suite=btn.dataset.suite;',
      '    document.querySelectorAll(".rf-suite[data-rid=\'"+rid+"\']").forEach(function(b){b.classList.toggle("active",b.dataset.suite===btn.dataset.suite);});',
      '  }',
      '  var st=_rf[rid].status,su=_rf[rid].suite;',
      '  document.querySelectorAll("[data-rid=\'"+rid+"\'].r-item,[data-rid=\'"+rid+"\'].r-item-flat").forEach(function(el){',
      '    var ms=st==="ALL"||el.dataset.status===st;',
      '    var mu=su==="ALL"||el.dataset.suite===su;',
      '    el.hidden=!(ms&&mu);',
      '  });',
      '}',
      'function deleteRun(filename,ev){ev.stopPropagation();ev.preventDefault();',
      '  fetch("/api/runs/delete",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({filename:filename})})',
      '    .then(function(){var el=ev.target.closest("details.h-run");if(el)el.remove();});',
      '}',
      'function copyCmd(t){navigator.clipboard.writeText(t);var el=document.getElementById("toast");el.textContent="Copied!";el.classList.add("show");setTimeout(function(){el.classList.remove("show");},1500);}',
      'var _initTab=sessionStorage.getItem("fct-tab")||"tests";sessionStorage.removeItem("fct-tab");',
      'showTab(_initTab);',
      'fetch("/api/status").then(function(r){return r.json();}).then(function(s){',
      '  if(s.settings){',
      '    var headedCb=document.getElementById("headed-cb"),pauseCb=document.getElementById("pause-cb");',
      '    var urlEl=document.getElementById("s-url"),userEl=document.getElementById("s-user");',
      '    if(headedCb)headedCb.checked=!!s.settings.headed;',
      '    if(pauseCb){pauseCb.checked=!!s.settings.pauseBetweenSuites;pauseCb.disabled=!s.settings.headed;}',
      '    if(urlEl&&s.settings.wpUrl)urlEl.value=s.settings.wpUrl;',
      '    if(userEl&&s.settings.wpUser)userEl.value=s.settings.wpUser;',
      '  }',
      '  if(s.paused&&s.pauseData){handlePause(s.pauseData);}',
      '  else if(s.running){',
      '    showTab("tests");',
      '    var section=document.getElementById("output-section"),title=document.getElementById("output-title"),badge=document.getElementById("run-badge");',
      '    if(section)section.style.display="block";',
      '    if(title)title.textContent="Running: "+(s.filter||"all");',
      '    badge.textContent="\\u27f3 Running\\u2026";badge.className="run-badge running";',
      '    setBusy(true);attachSSE();',
      '  }',
      '});',
    ].join('\n');

    return '<!DOCTYPE html>\n<html lang="en">\n<head>\n' +
      '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">\n' +
      '<title>FluentCart — Browser Tests</title>\n' +
      '<style>\n' + CSS + '\n</style>\n</head>\n<body>\n' +

      '<div class="header"><div><h1>FluentCart — Browser Tests</h1></div>' +
      '<div class="header-right">' +
      '<span id="run-badge" class="run-badge idle">&#9679; Idle</span>' +
      '<button class="run-btn-primary" id="run-all-btn" onclick="runTests(\'\')">&#9654; Run All</button>' +
      '<button class="stop-btn" id="stop-btn" onclick="stopTests()">&#9632; Stop</button>' +
      '<span class="live-badge"><span class="live-dot"></span> :' + port + '</span>' +
      '</div></div>\n' +
      '<p class="subtitle" style="margin-bottom:1rem">Playwright browser tests &bull; ' + totalSuites + ' suites, ' + totalTests + ' tests</p>\n' +

      '<div class="settings-bar">' +
      '<span class="s-label">WP Site</span>' +
      '<input class="s-input s-url" id="s-url" type="text" value="' + escHtml(settings.wpUrl || '') + '" placeholder="https://wp.test">' +
      '<input class="s-input s-user" id="s-user" type="text" value="' + escHtml(settings.wpUser || '') + '" placeholder="Username">' +
      '<input class="s-input s-pass" id="s-pass" type="password" placeholder="Password">' +
      '<button class="s-save" onclick="saveSettings()">Apply</button>' +
      '<span class="s-divider">|</span>' +
      '<label class="s-toggle" title="Open a visible browser window (sequential mode)">' +
      '<input type="checkbox" id="headed-cb"' + (settings.headed ? ' checked' : '') + ' onchange="saveSettings()"> Headed</label>' +
      '<span class="s-divider">|</span>' +
      '<label class="s-toggle' + (settings.headed ? '' : ' muted') + '" title="Pause in dashboard between suites (headed mode only)">' +
      '<input type="checkbox" id="pause-cb"' + pauseChecked + pauseDisabled + ' onchange="saveSettings()"> Suite pause</label>' +
      '</div>\n\n' +

      '<div class="tabs-nav">' +
      '<button class="tab-btn" data-tab="tests" onclick="showTab(\'tests\')">Tests</button>' +
      '<button class="tab-btn" data-tab="history" onclick="showTab(\'history\')">History' +
      (allRuns.length ? ' <span style="font-size:0.7rem;color:#64748b">' + allRuns.length + ' run' + (allRuns.length !== 1 ? 's' : '') + '</span>' : '') +
      '</button>' +
      '</div>\n\n' +

      '<div class="tab-panel" id="tab-tests">' +
      '<div class="section" id="output-section" style="display:none">' +
      '<div class="terminal-header"><h2 id="output-title">Output</h2>' +
      '<button class="clear-btn" onclick="document.getElementById(\'terminal\').textContent=\'\'">Clear</button></div>' +
      '<div class="terminal" id="terminal"></div>' +
      '</div>\n\n' +
      '<div class="section"><h2>Test Registry</h2><table>' +
      '<thead><tr><th>Suite</th><th>Tests</th><th>Individual Tests (click to run)</th><th>Run</th></tr></thead>' +
      '<tbody>' + suiteRows + '</tbody></table></div>\n\n' +
      '<div class="section"><h2>CLI Commands <span style="font-size:0.75rem;color:#64748b;font-weight:400">(click to copy)</span></h2>' +
      cmdRows + '</div>\n\n' +
      '</div>\n\n' +

      '<div class="tab-panel" id="tab-history" style="display:none">' +
      '<div class="section" style="padding:0;overflow:hidden">' + historyContent + '</div>' +
      '</div>\n\n' +

      '<div class="pause-overlay" id="pause-overlay">' +
      '<div class="pause-card">' +
      '<h3 id="pause-suite">Suite complete</h3>' +
      '<div class="pause-stats" id="pause-stats"></div>' +
      '<div class="pause-results" id="pause-results"></div>' +
      '<div class="pause-next" id="pause-next"></div>' +
      '<div class="pause-actions">' +
      '<button class="continue-btn" onclick="resumeRun()">Continue &#8594;</button>' +
      '<button class="pause-stop-btn" onclick="stopTests()">&#9632; Stop</button>' +
      '</div></div></div>\n\n' +

      '<p class="timestamp">Generated ' + new Date().toLocaleString() + '</p>\n' +
      '<div class="toast" id="toast">Copied!</div>\n' +
      '<script>\n' + JS + '\n</script>\n</body>\n</html>';
  }

  // ── HTTP server ──
  const server = createServer((req, res) => {
    if (req.url === '/events') {
      res.writeHead(200, { 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache', 'Connection': 'keep-alive' });
      res.write('\n');
      sseClients.push(res);
      if (runState.running && runState.lines.length) {
        for (const line of runState.lines) {
          res.write('data: ' + JSON.stringify({ type: 'line', text: line }) + '\n\n');
        }
      }
      req.on('close', () => { sseClients = sseClients.filter(c => c !== res); });
      return;
    }

    if (req.url === '/api/run' && req.method === 'POST') {
      if (runState.running) {
        res.writeHead(409, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'already running' }));
        return;
      }
      let body = '';
      req.on('data', d => { body += d; });
      req.on('end', () => {
        let filter = '';
        try { filter = JSON.parse(body).filter || ''; } catch {}
        startRun(filter);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ started: true, filter }));
      });
      return;
    }

    if (req.url === '/api/status') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ running: runState.running, filter: runState.filter, lineCount: runState.lines.length, exitCode: runState.exitCode, paused: runState.paused, pauseData: runState.pauseData, settings }));
      return;
    }

    if (req.url === '/api/stop' && req.method === 'POST') {
      const stopped = stopRun();
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ stopped }));
      return;
    }

    if (req.url === '/api/runs/delete' && req.method === 'POST') {
      let body = '';
      req.on('data', d => { body += d; });
      req.on('end', () => {
        try {
          const { filename } = JSON.parse(body);
          const safe = (filename || '').replace(/[^a-zA-Z0-9_\-.]/g, '');
          if (safe && safe.startsWith('run-') && safe.endsWith('.json')) {
            const fp = join(OUTPUT_DIR, 'runs', safe);
            if (existsSync(fp)) unlinkSync(fp);
          }
        } catch {}
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true }));
      });
      return;
    }

    if (req.url === '/api/continue' && req.method === 'POST') {
      if (activeChild && runState.paused) {
        runState.paused = false;
        runState.pauseData = null;
        activeChild.stdin.write('continue\n');
        emit({ type: 'resumed' });
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true }));
      return;
    }

    if (req.url === '/api/settings' && req.method === 'POST') {
      let body = '';
      req.on('data', d => { body += d; });
      req.on('end', () => {
        try {
          const d = JSON.parse(body);
          if (typeof d.headed !== 'undefined') settings.headed = !!d.headed;
          if (typeof d.pauseBetweenSuites !== 'undefined') settings.pauseBetweenSuites = !!d.pauseBetweenSuites;
          if (d.wpUrl)  settings.wpUrl  = d.wpUrl;
          if (d.wpUser) settings.wpUser = d.wpUser;
          if (typeof d.wpPass !== 'undefined') settings.wpPass = d.wpPass;
          writeEnvFile();
        } catch {}
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true }));
      });
      return;
    }

    if (req.url === '/' || req.url === '/index.html') {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(generateHTML());
      return;
    }

    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Not found');
  });

  server.listen(port, () => {
    const url = 'http://localhost:' + port;
    console.log('\n\x1b[32m  Browser Test Dashboard: \x1b[1m' + url + '\x1b[0m');
    console.log('\x1b[90m  Click "▶ Run All" or individual suite/test buttons in the UI. Ctrl+C to stop.\x1b[0m\n');
    try { execSync('open "' + url + '"', { stdio: 'ignore' }); } catch {}
  });
}

// ─── Suite helpers ────────────────────────────────────────────────────────────

async function loadSuite(loader) {
  const mod = await loader();
  return Object.values(mod).filter(C => typeof C === 'function' && C.prototype instanceof BaseTest);
}

// Login is needed only when at least one selected test requires it.
// NO_LOGIN=true forces a skip (override). Self-contained tests set
// `static requiresLogin = false` and run without a WordPress session.
function shouldLogin(tests) {
  if (process.env.NO_LOGIN) return false;
  return (tests || []).some(T => T && T.requiresLogin !== false);
}

async function resolveSingleOrSuite(filter) {
  if (testMap[filter]) return [await testMap[filter]()];
  if (suites[filter]) return loadSuite(suites[filter]);
  const fuzzy = Object.keys(testMap).filter(k => k.includes(filter));
  if (fuzzy.length) return Promise.all(fuzzy.map(k => testMap[k]()));
  return null;
}

// ─── Browser-injected dialogs (headed mode only) ──────────────────────────────

/**
 * Inject a dialog overlay into the live browser page and wait for the user to click a button.
 * Returns the value of the clicked button's data-action attribute.
 */
async function showBrowserDialog(page, html) {
  await page.evaluate((dialogHtml) => {
    document.getElementById('__fct_dialog__')?.remove();
    const el = document.createElement('div');
    el.id = '__fct_dialog__';
    el.innerHTML = dialogHtml;
    document.body.appendChild(el);
    window.__fct_dialog_choice__ = null;
    el.querySelectorAll('button[data-action]').forEach(btn => {
      btn.addEventListener('click', () => {
        window.__fct_dialog_choice__ = btn.dataset.action;
        document.getElementById('__fct_dialog__')?.remove();
      });
    });
  }, html);

  // Wait indefinitely (timeout: 0) for the user to click
  await page.waitForFunction(() => window.__fct_dialog_choice__ !== null, { timeout: 0 });
  return page.evaluate(() => window.__fct_dialog_choice__);
}

/**
 * Module dialog — shown between suites.
 * Displays results for the completed suite and info about the next one.
 * Returns { quit, skip, featurePrompts }
 */
async function showModuleDialog(page, { suiteName, passed, failed, skipped, testResults, nextSuiteName, nextSuiteCount, featurePrompts }) {
  const total = passed + failed + skipped;
  const allPassed = failed === 0;
  const scoreColor = allPassed ? '#22c55e' : '#ef4444';
  const moduleIcon = allPassed ? '✅' : '❌';
  const pct = total > 0 ? Math.round((passed / total) * 100) : 100;

  const testListHtml = testResults.map(r => {
    const icon = r.status === 'PASS' ? '✅' : r.status === 'SKIP' ? '⏭️' : '❌';
    const color = r.status === 'PASS' ? '#94a3b8' : r.status === 'SKIP' ? '#fbbf24' : '#fca5a5';
    return `<div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #1e293b">
      <span style="font-size:13px">${icon}</span>
      <span style="font-size:12px;color:${color};flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.name}</span>
    </div>`;
  }).join('');

  const nextBlock = nextSuiteName ? `
    <div style="background:#0f1f35;border:1px solid #1e3a5f;border-radius:8px;padding:14px 16px;margin-bottom:20px">
      <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.12em;color:#3b82f6;margin-bottom:6px;font-weight:600">Next Module</div>
      <div style="font-size:15px;font-weight:700;color:#f1f5f9">${nextSuiteName}</div>
      <div style="font-size:12px;color:#64748b;margin-top:3px">${nextSuiteCount} test${nextSuiteCount !== 1 ? 's' : ''}</div>
    </div>
  ` : `<div style="background:#0f1f35;border:1px solid #166534;border-radius:8px;padding:14px 16px;margin-bottom:20px;color:#86efac;font-size:13px;font-weight:600">🎉 Last module — all done!</div>`;

  const featureLabel = featurePrompts ? 'Disable per-feature pauses' : '+ Pause between features';
  const featureBtnStyle = featurePrompts
    ? 'background:#1e293b;color:#94a3b8;border:1px solid #334155'
    : 'background:#0f2d4a;color:#93c5fd;border:1px solid #1e40af';

  const continueBtn = nextSuiteName
    ? `<button data-action="continue" style="flex:1;padding:10px 18px;background:#3b82f6;color:white;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer">Continue →</button>`
    : `<button data-action="quit" style="flex:1;padding:10px 18px;background:#22c55e;color:white;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer">Done ✓</button>`;

  const extraBtns = nextSuiteName ? `
    <button data-action="feature" style="padding:10px 14px;${featureBtnStyle};border-radius:8px;font-size:12px;cursor:pointer;white-space:nowrap">${featureLabel}</button>
    <button data-action="skip" style="padding:10px 14px;background:transparent;color:#64748b;border:1px solid #334155;border-radius:8px;font-size:12px;cursor:pointer">Skip next</button>
    <button data-action="quit" style="padding:10px 14px;background:transparent;color:#ef4444;border:1px solid #450a0a;border-radius:8px;font-size:12px;cursor:pointer">Quit</button>
  ` : '';

  const html = `
    <style>
      #__fct_dialog__ * { box-sizing:border-box; margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
      #__fct_dialog__ button:hover { filter:brightness(1.15); }
      #__fct_dialog__ button { transition:filter 0.15s; }
    </style>
    <div style="position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.65);backdrop-filter:blur(6px)">
      <div style="background:#1a2236;border-radius:14px;padding:28px 32px;width:480px;max-width:90vw;box-shadow:0 32px 80px rgba(0,0,0,0.5);border:1px solid #1e293b">

        <!-- Header -->
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px">
          <div style="font-size:32px;line-height:1">${moduleIcon}</div>
          <div>
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.12em;color:#64748b;margin-bottom:4px;font-weight:600">Module Complete</div>
            <div style="font-size:18px;font-weight:800;color:#f1f5f9">${suiteName}</div>
          </div>
          <div style="margin-left:auto;text-align:right">
            <div style="font-size:28px;font-weight:900;color:${scoreColor};line-height:1">${pct}%</div>
            <div style="font-size:11px;color:#64748b">${passed}/${total}</div>
          </div>
        </div>

        <!-- Score bar -->
        <div style="height:4px;background:#1e293b;border-radius:2px;margin-bottom:18px;overflow:hidden">
          <div style="height:100%;width:${pct}%;background:${scoreColor};border-radius:2px"></div>
        </div>

        <!-- Test list -->
        <div style="max-height:160px;overflow-y:auto;margin-bottom:20px;background:#0f172a;border-radius:8px;padding:10px 14px">
          ${testListHtml || '<div style="color:#64748b;font-size:12px;padding:4px 0">No test details</div>'}
        </div>

        <!-- Next module -->
        ${nextBlock}

        <!-- Actions -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          ${continueBtn}
          ${extraBtns}
        </div>
      </div>
    </div>
  `;

  const choice = await showBrowserDialog(page, html);

  if (choice === 'feature') {
    return { quit: false, skip: false, featurePrompts: !featurePrompts };
  }
  return { quit: choice === 'quit', skip: choice === 'skip', featurePrompts };
}

/**
 * Feature dialog — small bottom-right card shown between individual tests.
 * Returns { quit }
 */
async function showFeatureDialog(page, { title, passed, failed, nextTitle }) {
  const allPassed = failed === 0;
  const icon = allPassed ? '✅' : '❌';
  const color = allPassed ? '#22c55e' : '#ef4444';

  const html = `
    <style>
      #__fct_dialog__ * { box-sizing:border-box; margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
      #__fct_dialog__ button:hover { filter:brightness(1.15); }
      #__fct_dialog__ button { transition:filter 0.15s; }
      @keyframes __fct_slide_in__ { from { transform:translateY(20px);opacity:0 } to { transform:translateY(0);opacity:1 } }
    </style>
    <div style="position:fixed;bottom:24px;right:24px;z-index:2147483647;width:320px;background:#1a2236;border:1px solid #1e293b;border-radius:12px;padding:18px 20px;box-shadow:0 16px 40px rgba(0,0,0,0.5);animation:__fct_slide_in__ 0.2s ease">

      <!-- Result -->
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <span style="font-size:20px">${icon}</span>
        <div>
          <div style="font-size:11px;color:#64748b;margin-bottom:2px">Feature complete</div>
          <div style="font-size:13px;font-weight:700;color:#f1f5f9;line-height:1.3">${title}</div>
        </div>
        <div style="margin-left:auto;font-size:16px;font-weight:800;color:${color}">${passed + failed > 0 ? passed + '/' + (passed + failed) : '–'}</div>
      </div>

      ${nextTitle ? `
        <!-- Next -->
        <div style="background:#0f172a;border-radius:6px;padding:8px 12px;margin-bottom:14px">
          <div style="font-size:10px;color:#3b82f6;margin-bottom:3px;text-transform:uppercase;letter-spacing:0.1em;font-weight:600">Up next</div>
          <div style="font-size:12px;color:#cbd5e1">${nextTitle}</div>
        </div>

        <!-- Buttons -->
        <div style="display:flex;gap:8px">
          <button data-action="continue" style="flex:1;padding:8px;background:#3b82f6;color:white;border:none;border-radius:7px;font-weight:700;font-size:13px;cursor:pointer">Continue →</button>
          <button data-action="quit" style="padding:8px 14px;background:transparent;color:#ef4444;border:1px solid #450a0a;border-radius:7px;font-size:13px;cursor:pointer">Quit</button>
        </div>
      ` : `
        <div style="font-size:12px;color:#64748b">(Last feature in module)</div>
        <button data-action="continue" style="margin-top:10px;width:100%;padding:8px;background:#1e293b;color:#94a3b8;border:none;border-radius:7px;font-size:13px;cursor:pointer">OK</button>
      `}
    </div>
  `;

  const choice = await showBrowserDialog(page, html);
  return { quit: choice === 'quit' };
}

// ─── Run a single test, return delta results ──────────────────────────────────

async function runTest(TestClass, page, baseUrl) {
  console.log(`\n📋 ${TestClass.title}`);
  const beforeCount = BaseTest._results.length;
  const before = {
    passed: BaseTest.summary.passed,
    failed: BaseTest.summary.failed,
    skipped: BaseTest.summary.skipped,
  };
  const test = new TestClass(page, baseUrl);
  try {
    await test.run();
  } catch (err) {
    console.log(`  ❌ CRASH: ${err.message}`);
    BaseTest._failed++;
    BaseTest._results.push({ status: 'FAIL', name: `${TestClass.title} crashed`, detail: err.message });
  }
  const newResults = BaseTest._results.slice(beforeCount);
  return {
    passed: BaseTest.summary.passed - before.passed,
    failed: BaseTest.summary.failed - before.failed,
    skipped: BaseTest.summary.skipped - before.skipped,
    results: newResults,
  };
}

// ─── Sequential runner (headed mode) ─────────────────────────────────────────

async function runSequential(suiteEntries, config) {
  const { baseUrl, user, pass, headed } = config;
  let featurePrompts = false;

  const browser = await launchBrowser(headed);
  const page = await createPage(browser);
  const allForLogin = (await Promise.all(suiteEntries.map(([, l]) => loadSuite(l)))).flat();
  if (shouldLogin(allForLogin)) await login(page, baseUrl, user, pass);

  try {
    for (let si = 0; si < suiteEntries.length; si++) {
      const [suiteName, loader] = suiteEntries[si];
      const tests = await loadSuite(loader);
      const nextEntry = suiteEntries[si + 1];

      console.log(`\n${'═'.repeat(52)}`);
      console.log(`📦 Module: ${suiteName}  (${tests.length} tests)`);
      console.log('═'.repeat(52));
      BaseTest._currentSuite = suiteName;

      let suitePassed = 0;
      let suiteFailed = 0;
      let suiteSkipped = 0;
      const suiteTestResults = [];
      let abortSuite = false;

      for (let ti = 0; ti < tests.length; ti++) {
        const TestClass = tests[ti];
        const nextTest = tests[ti + 1];
        const delta = await runTest(TestClass, page, baseUrl);
        suitePassed += delta.passed;
        suiteFailed += delta.failed;
        suiteSkipped += delta.skipped;
        suiteTestResults.push(...delta.results);

        if (featurePrompts) {
          const { quit } = await showFeatureDialog(page, {
            title: TestClass.title,
            passed: delta.passed,
            failed: delta.failed,
            nextTitle: nextTest ? nextTest.title : null,
          });
          if (quit) { abortSuite = true; break; }
        }
      }
      if (abortSuite) break;

      if (process.env.CI && process.env.FCT_SUITE_PAUSE === '1' && nextEntry) {
        // Dashboard pause — signal the server and wait for continue via stdin
        process.stdout.write('__FCT_PAUSE__:' + JSON.stringify({
          suite: suiteName,
          passed: suitePassed,
          failed: suiteFailed,
          skipped: suiteSkipped,
          results: suiteTestResults,
          nextSuite: nextEntry[0],
        }) + '\n');
        await new Promise(resolve => {
          process.stdin.resume();
          process.stdin.once('data', chunk => {
            process.stdin.pause();
            resolve(chunk.toString().trim());
          });
        });
      } else if (!process.env.CI) {
        // Terminal headed mode — show browser dialog
        const nextSuiteCount = nextEntry ? (await loadSuite(nextEntry[1])).length : 0;
        const { quit, skip, featurePrompts: fp } = await showModuleDialog(page, {
          suiteName,
          passed: suitePassed,
          failed: suiteFailed,
          skipped: suiteSkipped,
          testResults: suiteTestResults,
          nextSuiteName: nextEntry ? nextEntry[0] : null,
          nextSuiteCount,
          featurePrompts,
        });
        featurePrompts = fp;
        if (quit) break;
        if (skip) si++;
      }
    }
  } finally {
    saveResults();
    BaseTest.printSummary();
    printMonitorSummary();

    // Show final results overlay in browser
    const { passed, failed, skipped, total } = BaseTest.summary;
    const allGreen = failed === 0;
    await page.evaluate(({ passed, failed, skipped, total, allGreen, results }) => {
      document.getElementById('__fct_test_overlay__')?.remove();
      const wrapper = document.createElement('div');
      wrapper.id = '__fct_test_final__';
      wrapper.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;background:rgba(0,0,0,0.7);display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,monospace;';
      const card = document.createElement('div');
      card.style.cssText = 'background:#0f172a;border:2px solid ' + (allGreen ? '#22c55e' : '#ef4444') + ';border-radius:16px;padding:30px 40px;min-width:400px;max-width:600px;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.6);';
      const title = document.createElement('div');
      title.style.cssText = 'font-size:20px;font-weight:800;color:' + (allGreen ? '#4ade80' : '#f87171') + ';margin-bottom:6px;';
      title.textContent = allGreen ? '✅ All Tests Passed' : '❌ Some Tests Failed';
      const score = document.createElement('div');
      score.style.cssText = 'font-size:14px;color:#94a3b8;margin-bottom:20px;';
      score.textContent = passed + ' passed, ' + failed + ' failed, ' + skipped + ' skipped, ' + total + ' total';
      const list = document.createElement('div');
      list.style.cssText = 'font-size:12px;line-height:1.8;';
      results.forEach(r => {
        const row = document.createElement('div');
        row.style.cssText = 'color:' + (r.status === 'PASS' ? '#94a3b8' : r.status === 'SKIP' ? '#fbbf24' : '#fca5a5') + ';border-bottom:1px solid #1e293b;padding:3px 0;';
        row.textContent = (r.status === 'PASS' ? '✅' : r.status === 'SKIP' ? '⏭️' : '❌') + ' ' + r.name + (r.detail ? ' — ' + r.detail : '');
        list.appendChild(row);
      });
      const hint = document.createElement('div');
      hint.style.cssText = 'margin-top:16px;font-size:11px;color:#475569;text-align:center;';
      hint.textContent = 'Press Ctrl+C in terminal to close';
      card.appendChild(title); card.appendChild(score); card.appendChild(list); card.appendChild(hint);
      wrapper.appendChild(card);
      document.body.appendChild(wrapper);
    }, { passed, failed, skipped, total, allGreen, results: BaseTest._results }).catch(() => {});

    if (process.env.CI) {
      await browser.close();
      process.exit(BaseTest.summary.failed > 0 ? 1 : 0);
    } else {
      console.log('\n🖥️  Browser left open. Press Ctrl+C to close.');
      await new Promise(() => {});
    }
  }
}

// ─── Parallel runner (headless) ───────────────────────────────────────────────

async function runParallel(suiteEntries, config, maxBrowsers) {
  const { baseUrl, user, pass } = config;
  console.log(`\n   Parallel mode: up to ${maxBrowsers} browser(s) at once\n`);

  let active = 0;
  const queue = [];
  const acquire = () => {
    if (active < maxBrowsers) { active++; return Promise.resolve(); }
    return new Promise(resolve => queue.push(resolve));
  };
  const release = () => {
    if (queue.length) queue.shift()(); else active--;
  };

  await Promise.all(suiteEntries.map(async ([suiteName, loader]) => {
    await acquire();
    const browser = await launchBrowser(false);
    const page = await createPage(browser);
    try {
      const tests = await loadSuite(loader);
      if (shouldLogin(tests)) await login(page, baseUrl, user, pass);
      console.log(`\n${'═'.repeat(52)}\n📦 ${suiteName}  (${tests.length} tests)\n${'═'.repeat(52)}`);
      BaseTest._currentSuite = suiteName;
      for (const TestClass of tests) {
        await runTest(TestClass, page, baseUrl);
      }
    } finally {
      await browser.close();
      release();
    }
  }));
}

// ─── Main ─────────────────────────────────────────────────────────────────────

async function main() {
  const positional = process.argv.slice(2).filter(a => !a.startsWith('-'));
  const filter = positional[0] || '';
  const browserName = getBrowserName();

  if (filter === 'serve')  { cmdServe(parseInt(positional[1]) || 9800); return; }
  if (filter === 'status') { cmdStatus(); return; }

  _runStartMs = Date.now();

  // Decide whether this run needs a login BEFORE prompting for credentials, so
  // runs that contain only self-contained (no-login) tests never ask for a URL.
  const loginProbeTests = filter
    ? (await resolveSingleOrSuite(filter)) || []
    : (await Promise.all(Object.values(suites).map(loadSuite))).flat();
  const needsLogin = shouldLogin(loginProbeTests);

  const config = await getConfig('FluentCart Browser Tests', {}, { skipLoginPrompt: !needsLogin });
  const { baseUrl, headed } = config;
  const maxBrowsers = parseInt(process.env.MAX_BROWSERS ?? '1', 10);

  BaseTest.headed = headed;
  const parsedPace = parseInt(process.env.PACE, 10);
  BaseTest.pace = !isNaN(parsedPace) ? parsedPace : (headed ? 1500 : 0);

  ensureScreenshotDir();
  BaseTest.resetResults();

  console.log('\n🧪 FluentCart Browser Tests');
  console.log(`   Site:    ${baseUrl}`);
  console.log(`   Browser: ${browserName}`);
  console.log(`   Headed:  ${headed}${headed ? ` (pace: ${BaseTest.pace}ms, sequential + dialogs)` : ` (parallel, max ${maxBrowsers} browsers)`}`);

  // ── Single test or suite filter ──
  if (filter) {
    const tests = await resolveSingleOrSuite(filter);
    if (!tests) {
      console.error(`\n  ❌ No tests found for "${filter}"`);
      console.log('  Suites:', Object.keys(suites).join(', '));
      console.log('  Tests:', Object.keys(testMap).join(', '));
      process.exit(1);
    }
    console.log(`   Filter:  ${filter}  (${tests.length} test(s))\n`);

    const { baseUrl: bu, user, pass } = config;
    const browser = await launchBrowser(headed);
    const page = await createPage(browser);
    try {
      if (shouldLogin(tests)) await login(page, bu, user, pass);

      // Inject overlay immediately so it's visible from the start
      const dummyTest = new BaseTest(page, baseUrl);
      await dummyTest._ensureOverlay();
      await dummyTest._updateOverlay('<span style="color:#3b82f6">🧪 Starting tests...</span>');

      for (const TestClass of tests) await runTest(TestClass, page, baseUrl);
    } catch (err) {
      console.error('\n💥 Runner error:', err.message);
    }
    saveResults();
    BaseTest.printSummary();
    printMonitorSummary();
    if (headed) {
      // Show final results in the browser overlay
      const { passed, failed, skipped, total } = BaseTest.summary;
      const allGreen = failed === 0;
      await page.evaluate(({ passed, failed, skipped, total, allGreen, results }) => {
        // Remove old overlay
        document.getElementById('__fct_test_overlay__')?.remove();

        const wrapper = document.createElement('div');
        wrapper.id = '__fct_test_final__';
        wrapper.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;background:rgba(0,0,0,0.7);display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,monospace;';

        const card = document.createElement('div');
        card.style.cssText = 'background:#0f172a;border:2px solid ' + (allGreen ? '#22c55e' : '#ef4444') + ';border-radius:16px;padding:30px 40px;min-width:400px;max-width:600px;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.6);';

        const title = document.createElement('div');
        title.style.cssText = 'font-size:20px;font-weight:800;color:' + (allGreen ? '#4ade80' : '#f87171') + ';margin-bottom:6px;';
        title.textContent = allGreen ? '✅ All Tests Passed' : '❌ Some Tests Failed';

        const score = document.createElement('div');
        score.style.cssText = 'font-size:14px;color:#94a3b8;margin-bottom:20px;';
        score.textContent = passed + ' passed, ' + failed + ' failed, ' + skipped + ' skipped, ' + total + ' total';

        const list = document.createElement('div');
        list.style.cssText = 'font-size:12px;line-height:1.8;';

        results.forEach(r => {
          const row = document.createElement('div');
          const icon = r.status === 'PASS' ? '✅' : r.status === 'SKIP' ? '⏭️' : '❌';
          const color = r.status === 'PASS' ? '#94a3b8' : r.status === 'SKIP' ? '#fbbf24' : '#fca5a5';
          row.style.cssText = 'color:' + color + ';border-bottom:1px solid #1e293b;padding:3px 0;';
          row.textContent = icon + ' ' + r.name + (r.detail ? ' — ' + r.detail : '');
          list.appendChild(row);
        });

        const hint = document.createElement('div');
        hint.style.cssText = 'margin-top:16px;font-size:11px;color:#475569;text-align:center;';
        hint.textContent = 'Press Ctrl+C in terminal to close';

        card.appendChild(title);
        card.appendChild(score);
        card.appendChild(list);
        card.appendChild(hint);
        wrapper.appendChild(card);
        document.body.appendChild(wrapper);
      }, { passed, failed, skipped, total, allGreen, results: BaseTest._results }).catch(() => {});

      if (process.env.CI) {
        await browser.close();
        process.exit(BaseTest.summary.failed > 0 ? 1 : 0);
      } else {
        console.log('\n🖥️  Browser left open. Press Ctrl+C to close.');
        await new Promise(() => {});
      }
    } else {
      await browser.close();
      process.exit(BaseTest.summary.failed > 0 ? 1 : 0);
    }
    return;
  }

  // ── Full run ──
  const suiteEntries = Object.entries(suites);
  const allTests = await Promise.all(suiteEntries.map(([, l]) => loadSuite(l)));
  const totalTests = allTests.reduce((n, t) => n + t.length, 0);
  console.log(`   Suites:  ${suiteEntries.length}  (${totalTests} total tests)\n`);

  if (headed) {
    await runSequential(suiteEntries, config);
  } else {
    await runParallel(suiteEntries, config, maxBrowsers);
    saveResults();
    BaseTest.printSummary();
    printMonitorSummary();
    process.exit(BaseTest.summary.failed > 0 ? 1 : 0);
  }
}

main();
