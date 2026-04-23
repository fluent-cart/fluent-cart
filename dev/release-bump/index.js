'use strict';
const http    = require('http');
const fs      = require('fs');
const path    = require('path');
const { spawn, exec } = require('child_process');
const updater = require('./updater');

const PORT    = 3333;
const UI_FILE = path.join(__dirname, 'ui.html');
const WP_ROOT = path.resolve(updater.PLUGIN_ROOT, '../../..');

// ─── helpers ────────────────────────────────────────────────────────────────

function parseBody(req) {
    return new Promise((resolve, reject) => {
        let buf = '';
        req.on('data', c => buf += c);
        req.on('end', () => {
            try { resolve(JSON.parse(buf)); }
            catch (e) { reject(e); }
        });
    });
}

function json(res, data, status = 200) {
    const body = JSON.stringify(data);
    res.writeHead(status, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
    res.end(body);
}

function listBuilds() {
    const dirs = {
        free: path.join(updater.PLUGIN_ROOT, 'builds'),
        pro:  path.join(updater.PRO_ROOT,    'builds'),
    };
    const result = {};
    for (const [key, dir] of Object.entries(dirs)) {
        try {
            const files = fs.readdirSync(dir)
                .filter(f => f.endsWith('.zip'))
                .map(f => {
                    const full = path.join(dir, f);
                    return { name: f, path: full, mtime: fs.statSync(full).mtime.getTime() };
                })
                .sort((a, b) => b.mtime - a.mtime);
            result[key] = files[0] || null;
        } catch {
            result[key] = null;
        }
    }
    return result;
}

// ─── SSE runner ─────────────────────────────────────────────────────────────

const FREE_SYNC_POT = path.join(updater.PLUGIN_ROOT, 'dev/release-bump/sync-pot.php');

const POT_CMD = (phpScript, lang = 'fluent-cart') => ({
    cmd:       'sh',
    args:      ['-c', [
        `echo "PHP: $(which php 2>/dev/null || echo 'not found')"`,
        `echo "WP_ROOT: ${WP_ROOT}"`,
        `php "${phpScript}"`,
        `find language -name "*.pot~" -delete`,
        `echo "Cleaned up .pot~ files"`,
    ].join(' && ')],
    extraEnv:  { WP_ROOT, TEXT_DOMAIN: lang, PATH: (process.env.PATH || '') + ':/usr/bin:/opt/homebrew/bin:/usr/local/bin' },
    precheck:  () => !fs.existsSync(phpScript) && `Script not found: ${phpScript}`,
    timeoutMs: 5 * 60 * 1000,
});

const STEPS = {
    translate: {
        cmd:  'npm',
        args: ['run', 'translate:all'],
        cwd:  updater.PLUGIN_ROOT,
    },
    'pot-free': { ...POT_CMD(FREE_SYNC_POT, 'fluent-cart'),     cwd: updater.PLUGIN_ROOT },
    'pot-pro':  { ...POT_CMD(FREE_SYNC_POT, 'fluent-cart-pro'), cwd: updater.PRO_ROOT    },
    'build-free': {
        cmd:  'sh',
        args: ['-c', 'find language -name "*.pot~" -delete 2>/dev/null; npm run build:zip'],
        cwd:  updater.PLUGIN_ROOT,
    },
    'build-pro': {
        cmd:  'sh',
        args: ['-c', 'find language -name "*.pot~" -delete 2>/dev/null; npm run build:zip'],
        cwd:  updater.PRO_ROOT,
    },
};

function runSSE(res, step, inlineCfg) {
    const cfg = inlineCfg || STEPS[step];
    if (!cfg) { res.writeHead(400); res.end('Unknown step'); return; }

    res.writeHead(200, {
        'Content-Type':                'text/event-stream',
        'Cache-Control':               'no-cache',
        'Connection':                  'keep-alive',
        'Access-Control-Allow-Origin': '*',
    });

    const send = (type, text) =>
        res.write(`data: ${JSON.stringify({ type, text })}\n\n`);

    if (cfg.precheck) {
        const err = cfg.precheck();
        if (err) { send('error', err); send('done', '1'); res.end(); return; }
    }

    const child = spawn(cfg.cmd, cfg.args, {
        cwd:   cfg.cwd,
        shell: false,
        env:   { ...process.env, ...(cfg.extraEnv || {}) },
    });

    let finished = false;
    const finish = (code) => {
        if (finished) return;
        finished = true;
        send('done', String(code));
        res.end();
    };

    // Optional timeout (e.g. for POT steps where WP bootstrap can hang)
    const timer = cfg.timeoutMs ? setTimeout(() => {
        if (!child.killed) child.kill();
        send('error', `Timed out after ${cfg.timeoutMs / 60000} min — check WP DB connection`);
        finish('1');
    }, cfg.timeoutMs) : null;

    child.stdout.on('data', d => send('line', d.toString()));
    child.stderr.on('data', d => send('line', d.toString()));
    child.on('error', e  => send('error', e.message));
    child.on('close', code => { if (timer) clearTimeout(timer); finish(code); });

    res.on('close', () => { if (!child.killed) child.kill(); });
}

// ─── server ─────────────────────────────────────────────────────────────────

const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, `http://localhost:${PORT}`);

    // Serve UI
    if (req.method === 'GET' && url.pathname === '/') {
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end(fs.readFileSync(UI_FILE));
        return;
    }

    // Current git branches
    if (req.method === 'GET' && url.pathname === '/api/branches') {
        const getbranch = (dir) => new Promise(resolve => {
            exec(`git -C "${dir}" rev-parse --abbrev-ref HEAD 2>/dev/null`, (err, stdout) =>
                resolve(err ? null : stdout.trim() || null));
        });
        const [free, pro] = await Promise.all([
            getbranch(updater.PLUGIN_ROOT),
            getbranch(updater.PRO_ROOT),
        ]);
        json(res, { free, pro });
        return;
    }

    // Current versions
    if (req.method === 'GET' && url.pathname === '/api/versions') {
        try   { json(res, updater.getVersions()); }
        catch (e) { json(res, { error: e.message }, 500); }
        return;
    }

    // Apply file updates
    if (req.method === 'POST' && url.pathname === '/api/update') {
        try {
            const body    = await parseBody(req);
            const changes = updater.applyUpdates(body);
            json(res, { ok: true, changes });
        } catch (e) {
            json(res, { ok: false, error: e.message }, 500);
        }
        return;
    }

    // Run step (SSE)
    if (req.method === 'GET' && url.pathname === '/api/run') {
        runSSE(res, url.searchParams.get('step'));
        return;
    }

    // List build zips
    if (req.method === 'GET' && url.pathname === '/api/builds') {
        json(res, listBuilds());
        return;
    }

    // Open a build zip in Finder (macOS)
    if (req.method === 'GET' && url.pathname === '/api/open-finder') {
        const filePath = url.searchParams.get('path') || '';
        const allowed  = [path.join(updater.PLUGIN_ROOT, 'builds'), path.join(updater.PRO_ROOT, 'builds')];
        if (!allowed.some(d => filePath.startsWith(d)) || !filePath.endsWith('.zip')) {
            res.writeHead(403); res.end('Forbidden'); return;
        }
        exec(`open -R "${filePath.replace(/"/g, '\\"')}"`);
        json(res, { ok: true });
        return;
    }


    // Check Loco Translate installed + activated
    if (req.method === 'GET' && url.pathname === '/api/check-loco') {
        const locoFile = path.join(WP_ROOT, 'wp-content/plugins/loco-translate/loco.php');
        const installed = fs.existsSync(locoFile);
        if (!installed) { json(res, { installed: false, activated: false }); return; }

        // Parse DB creds from wp-config.php then check active_plugins via mysql CLI
        try {
            const wpCfg   = fs.readFileSync(path.join(WP_ROOT, 'wp-config.php'), 'utf8');
            const def     = n => (wpCfg.match(new RegExp(`define\\s*\\(\\s*['"]${n}['"]\\s*,\\s*['"]([^'"]*?)['"]`)) || [])[1] || '';
            const prefix  = (wpCfg.match(/\$table_prefix\s*=\s*['"]([^'"]+)['"]/) || [])[1] || 'wp_';
            const host    = def('DB_HOST') || 'localhost';
            const user    = def('DB_USER');
            const pass    = def('DB_PASSWORD');
            const dbName  = def('DB_NAME');
            const passArg = pass ? `-p${pass}` : '';
            const query   = `SELECT option_value FROM ${prefix}options WHERE option_name='active_plugins'`;
            exec(`mysql -h"${host}" -u"${user}" ${passArg} "${dbName}" -N -e "${query}"`, (err, stdout) => {
                if (err) { json(res, { installed: true, activated: null }); return; }
                json(res, { installed: true, activated: stdout.includes('loco-translate/loco.php') });
            });
        } catch {
            json(res, { installed: true, activated: null });
        }
        return;
    }

    // Verify release — Node.js built-in check
    if (req.method === 'GET' && url.pathname === '/api/verify') {
        const version = url.searchParams.get('version');
        const scope   = url.searchParams.get('scope') || 'both';
        if (!version) { json(res, { error: 'version param required' }, 400); return; }
        try   { json(res, updater.verifyRelease(version, scope)); }
        catch (e) { json(res, { error: e.message }, 500); }
        return;
    }

    // Check if Claude CLI is installed
    if (req.method === 'GET' && url.pathname === '/api/check-claude') {
        exec('which claude', (err, stdout) => {
            json(res, { installed: !err && !!stdout.trim(), path: stdout.trim() || null });
        });
        return;
    }

    // Verify with Claude CLI (SSE)
    if (req.method === 'GET' && url.pathname === '/api/verify-claude') {
        const version = url.searchParams.get('version') || '(unknown)';
        const scope   = url.searchParams.get('scope')   || 'both';
        const prompt  = `Run /release-verify to verify that version ${version} (scope: ${scope}) was correctly bumped in fluent-cart${scope !== 'free' ? ' and fluent-cart-pro' : ''}. Check all version strings, stable tags, and changelog entries. Give a clear pass/fail report.`;
        runSSE(res, '__claude__', { cmd: 'claude', args: ['-p', prompt], cwd: updater.PLUGIN_ROOT });
        return;
    }

    res.writeHead(404);
    res.end('Not found');
});

server.listen(PORT, () => {
    const addr = `http://localhost:${PORT}`;
    console.log('\n');
    console.log('  🚀 \x1b[1mFluentCart Release Bump\x1b[0m');
    console.log(`  ➜  Local: \x1b[36m${addr}\x1b[0m`);
    console.log('\n  Press Ctrl+C to stop.\n');
    exec(`open ${addr}`);
});
