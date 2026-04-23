'use strict';
const fs = require('fs');
const path = require('path');

const PLUGIN_ROOT = path.resolve(__dirname, '../..');
const PRO_ROOT    = path.resolve(PLUGIN_ROOT, '../fluent-cart-pro');

const FILES = {
    freePHP:    path.join(PLUGIN_ROOT, 'fluent-cart.php'),
    freeReadme: path.join(PLUGIN_ROOT, 'readme.txt'),
    proPHP:     path.join(PRO_ROOT,    'fluent-cart-pro.php'),
    proReadme:  path.join(PRO_ROOT,    'readme.txt'),
};

function extract(content, regex) {
    const m = content.match(regex);
    return m ? m[1] : null;
}

function getVersions() {
    const free = fs.readFileSync(FILES.freePHP, 'utf8');
    const pro  = fs.readFileSync(FILES.proPHP,  'utf8');
    return {
        free:       extract(free, /define\('FLUENTCART_VERSION',\s*'([\d.]+)'\)/),
        freeDb:     extract(free, /define\('FLUENTCART_DB_VERSION',\s*'([\d.]+)'\)/),
        freeMinPro: extract(free, /define\('FLUENTCART_MIN_PRO_VERSION',\s*'([\d.]+)'\)/),
        pro:        extract(pro,  /define\('FLUENTCART_PRO_PLUGIN_VERSION',\s*'([\d.]+)'\)/),
        proMinCore: extract(pro,  /define\('FLUENTCART_MIN_CORE_VERSION',\s*'([\d.]+)'\)/),
    };
}

function bumpPatch(version) {
    const parts = version.split('.');
    parts[parts.length - 1] = String(parseInt(parts[parts.length - 1], 10) + 1);
    return parts.join('.');
}

function formatDate(d = new Date()) {
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${months[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`;
}

function formatChangelog(raw) {
    if (!raw || !raw.trim()) return '';
    return raw.split('\n')
        .map(line => line.trim().replace(/^[-•*]\s*/, ''))
        .filter(Boolean)
        .map(line => `- ${line.charAt(0).toUpperCase()}${line.slice(1)}`)
        .join('\n');
}

function insertChangelog(content, version, date, entries) {
    const block = `\n= ${version} (${date}) =\n${entries}\n`;
    if (content.includes('== Changelog ==\n\n')) {
        return content.replace('== Changelog ==\n\n', `== Changelog ==\n${block}\n`);
    }
    return content.replace('== Changelog ==\n', `== Changelog ==\n${block}\n`);
}

function cleanBuilds(scope) {
    const dirs = [];
    if (scope !== 'pro')  dirs.push(path.join(PLUGIN_ROOT, 'builds'));
    if (scope !== 'free') dirs.push(path.join(PRO_ROOT,    'builds'));
    const deleted = [];
    for (const dir of dirs) {
        try {
            const files = fs.readdirSync(dir).filter(f => f.endsWith('.zip'));
            for (const f of files) {
                fs.unlinkSync(path.join(dir, f));
                deleted.push(f);
            }
        } catch { /* dir may not exist yet */ }
    }
    return deleted;
}

function applyUpdates({ newVersion, scope, bumpDb, changelog }) {
    const changes  = [];
    const versions = getVersions();

    // Clean old build zips before starting
    const deleted = cleanBuilds(scope);
    for (const f of deleted) {
        changes.push({ file: 'builds/', field: `Deleted old zip — ${f}` });
    }
    const date     = formatDate();
    const log      = changelog ? formatChangelog(changelog) : '';
    const doFree   = scope !== 'pro';
    const doPro    = scope !== 'free';

    if (doFree) {
        let c = fs.readFileSync(FILES.freePHP, 'utf8');
        c = c.replace(/Version: [\d.]+/, `Version: ${newVersion}`);
        changes.push({ file: 'fluent-cart.php', field: 'Version header' });
        c = c.replace(/(define\('FLUENTCART_VERSION',\s*')[\d.]+'/, `$1${newVersion}'`);
        changes.push({ file: 'fluent-cart.php', field: 'FLUENTCART_VERSION' });
        if (bumpDb) {
            const newDb = bumpPatch(versions.freeDb);
            c = c.replace(/(define\('FLUENTCART_DB_VERSION',\s*')[\d.]+'/, `$1${newDb}'`);
            changes.push({ file: 'fluent-cart.php', field: `FLUENTCART_DB_VERSION → ${newDb}` });
        }
        if (doPro) {
            c = c.replace(/(define\('FLUENTCART_MIN_PRO_VERSION',\s*')[\d.]+'/, `$1${newVersion}'`);
            changes.push({ file: 'fluent-cart.php', field: 'FLUENTCART_MIN_PRO_VERSION' });
        }
        fs.writeFileSync(FILES.freePHP, c);

        let readme = fs.readFileSync(FILES.freeReadme, 'utf8');
        readme = readme.replace(/Stable tag: [\d.]+/, `Stable tag: ${newVersion}`);
        changes.push({ file: 'readme.txt', field: 'Stable tag (free)' });
        if (log) {
            readme = insertChangelog(readme, newVersion, date, log);
            changes.push({ file: 'readme.txt', field: `Changelog → ${newVersion}` });
        }
        fs.writeFileSync(FILES.freeReadme, readme);
    }

    if (doPro) {
        let c = fs.readFileSync(FILES.proPHP, 'utf8');
        c = c.replace(/Version: [\d.]+/, `Version: ${newVersion}`);
        changes.push({ file: 'fluent-cart-pro.php', field: 'Version header' });
        c = c.replace(/(define\('FLUENTCART_PRO_PLUGIN_VERSION',\s*')[\d.]+'/, `$1${newVersion}'`);
        changes.push({ file: 'fluent-cart-pro.php', field: 'FLUENTCART_PRO_PLUGIN_VERSION' });
        if (doFree) {
            c = c.replace(/(define\('FLUENTCART_MIN_CORE_VERSION',\s*')[\d.]+'/, `$1${newVersion}'`);
            changes.push({ file: 'fluent-cart-pro.php', field: 'FLUENTCART_MIN_CORE_VERSION' });
        }
        fs.writeFileSync(FILES.proPHP, c);

        let readme = fs.readFileSync(FILES.proReadme, 'utf8');
        readme = readme.replace(/Stable tag: [\d.]+/, `Stable tag: ${newVersion}`);
        changes.push({ file: 'readme.txt', field: 'Stable tag (pro)' });
        if (log) {
            readme = insertChangelog(readme, newVersion, date, log);
            changes.push({ file: 'readme.txt', field: `Changelog → ${newVersion} (pro)` });
        }
        fs.writeFileSync(FILES.proReadme, readme);
    }

    return changes;
}

function verifyRelease(expectedVersion, scope) {
    const check = (label, actual) => ({ label, actual, ok: actual === expectedVersion });
    const checks = [];
    const doFree = scope !== 'pro';
    const doPro  = scope !== 'free';

    if (doFree) {
        const c = fs.readFileSync(FILES.freePHP, 'utf8');
        checks.push(check('fluent-cart.php › Version header',         extract(c, /Version: ([\d.]+)/)));
        checks.push(check('fluent-cart.php › FLUENTCART_VERSION',     extract(c, /define\('FLUENTCART_VERSION',\s*'([\d.]+)'\)/)));
        if (doPro) {
            checks.push(check('fluent-cart.php › FLUENTCART_MIN_PRO_VERSION', extract(c, /define\('FLUENTCART_MIN_PRO_VERSION',\s*'([\d.]+)'\)/)));
        }
        const r = fs.readFileSync(FILES.freeReadme, 'utf8');
        checks.push(check('readme.txt (free) › Stable tag',          extract(r, /Stable tag: ([\d.]+)/)));
        checks.push(check('readme.txt (free) › Latest changelog',    extract(r, /^= ([\d.]+) \(/m)));
    }

    if (doPro) {
        const c = fs.readFileSync(FILES.proPHP, 'utf8');
        checks.push(check('fluent-cart-pro.php › Version header',              extract(c, /Version: ([\d.]+)/)));
        checks.push(check('fluent-cart-pro.php › FLUENTCART_PRO_PLUGIN_VERSION', extract(c, /define\('FLUENTCART_PRO_PLUGIN_VERSION',\s*'([\d.]+)'\)/)));
        if (doFree) {
            checks.push(check('fluent-cart-pro.php › FLUENTCART_MIN_CORE_VERSION', extract(c, /define\('FLUENTCART_MIN_CORE_VERSION',\s*'([\d.]+)'\)/)));
        }
        const r = fs.readFileSync(FILES.proReadme, 'utf8');
        checks.push(check('readme.txt (pro) › Stable tag',           extract(r, /Stable tag: ([\d.]+)/)));
        checks.push(check('readme.txt (pro) › Latest changelog',     extract(r, /^= ([\d.]+) \(/m)));
    }

    // Build zips — filenames don't include version, so just confirm a zip exists
    const checkBuild = (label, dir) => {
        try {
            const files = fs.readdirSync(dir).filter(f => f.endsWith('.zip'));
            const match = files.length > 0 ? files[0] : null;
            return { label, actual: match || null, ok: !!match };
        } catch { return { label, actual: null, ok: false }; }
    };
    if (doFree) checks.push(checkBuild('Free build zip', path.join(PLUGIN_ROOT, 'builds')));
    if (doPro)  checks.push(checkBuild('Pro build zip',  path.join(PRO_ROOT,    'builds')));

    const passed = checks.every(c => c.ok);
    return { version: expectedVersion, scope, checks, passed, passCount: checks.filter(c => c.ok).length, failCount: checks.filter(c => !c.ok).length };
}

module.exports = { getVersions, applyUpdates, verifyRelease, PLUGIN_ROOT, PRO_ROOT };
