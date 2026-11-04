/**
 * Destructive-DB opt-in guard — shared by baseline-check.mjs (before its
 * atomic suite runs) and run-suite.mjs (before handing off to Codeception).
 * The Integration/Functional suites install WordPress into TEST_DB_NAME and
 * truncate every fct_* table; nothing may launch them unless the operator
 * has named the EXACT host:database to sacrifice in FCT_TEST_DB_ALLOW.
 *
 * Fail-closed parsing (each rule exists because an adversarial review
 * demonstrated the bypass; semantics are pinned by baseline-check.mjs
 * --self-test):
 *   - every line assigning the key counts: bare, `export`-prefixed, and
 *     quoted-name forms (phpdotenv honors all three)
 *   - more than one assignment poisons the key (last-wins loaders would
 *     honor a second assignment the guard never validated)
 *   - values parse strictly: quoted, or a single bare token, optional
 *     inline comment; anything else poisons the key
 *   - unresolved substitutions (${VAR}/%VAR%) and whitespace poison the
 *     value — Codeception resolves them before WPLoader connects, so a
 *     literal match on a placeholder proves nothing
 *
 * This is defense-in-depth: the terminal guard is TC-047's in-process PHP
 * check, which validates the values Codeception ACTUALLY resolved.
 */

const parseValue = (raw) => {
    const t = raw.trim();
    let m;
    if ((m = t.match(/^"([^"]*)"\s*(?:#.*)?$/))) return m[1];
    if ((m = t.match(/^'([^']*)'\s*(?:#.*)?$/))) return m[1];
    if ((m = t.match(/^([^#'"\s]*)\s*(?:#.*)?$/))) return m[1];
    return null; // unparseable — poisoned
};

export function parseEnvDb(envText) {
    const get = (k) => {
        const assigns = [...envText.matchAll(new RegExp(`^\\s*(?:export\\s+)?["']?${k}["']?\\s*=(.*)$`, 'gm'))];
        if (assigns.length > 1) return null; // duplicate — poisoned
        if (!assigns.length) return '';
        return parseValue(assigns[0][1]);
    };
    const host = get('TEST_DB_HOST');
    const name = get('TEST_DB_NAME');
    return {
        host: host === null ? '' : host,
        name: name === null ? '' : name,
        duplicates: host === null || name === null,
    };
}

// The guard validates .env — that is only sound while WPLoader
// actually RESOLVES its destructive target from that file. A suite yml
// hardcoding dbName (or switching to dbUrl, or adding the UPPERCASE
// config keys WPLoader prefers) would aim WPLoader somewhere the guard
// never validated. Returns problem strings; YAML keys may be quoted.
export function checkDbBinding(codeceptionYml, suiteYmls) {
    const problems = [];
    if (!/^params:\s*\n\s*-\s*['"]?\.env['"]?\s*$/m.test(codeceptionYml)) {
        problems.push('codeception.yml: params must load .env — the guard validates that file, so WPLoader must resolve %TEST_DB_*% from it');
    }
    for (const { name, text } of suiteYmls) {
        if (/\bdbUrl\b/i.test(text)) {
            problems.push(`${name}: dbUrl bypasses the host/database guard — use dbName/dbHost with %TEST_DB_*% placeholders`);
        }
        for (const m of text.matchAll(/^\s*["']?(DB_NAME|DB_HOST|DB_USER|DB_PASSWORD)["']?\s*:/gm)) {
            problems.push(`${name}: uppercase ${m[1]} config key takes priority over dbName/dbHost inside WPLoader and bypasses the guard — remove it and use the %TEST_DB_*% placeholders`);
        }
        for (const m of text.matchAll(/["']?\b(dbName|dbHost)\b["']?\s*:\s*(.+)$/gm)) {
            const want = m[1] === 'dbName' ? '%TEST_DB_NAME%' : '%TEST_DB_HOST%';
            const val = m[2].trim().replace(/^['"]|['"]$/g, '');
            if (val !== want) {
                problems.push(`${name}: ${m[1]} is "${val}" — must be exactly ${want} so the validated .env stays the single source of the destructive target`);
            }
        }
        if (/WPLoader/.test(text)) {
            for (const key of ['dbName', 'dbHost']) {
                if (!new RegExp(`["']?\\b${key}\\b["']?\\s*:`).test(text)) {
                    problems.push(`${name}: WPLoader configured without ${key} — the %TEST_DB_*% placeholder must be present so the guard-validated .env is the source of the destructive target`);
                }
            }
        }
    }
    return problems;
}

export function guardOk(target, allow) {
    const unresolved = (v) => /[${}%]/.test(v) || /\s/.test(v);
    if (target.duplicates) return false;
    if (!target.host || !target.name || unresolved(target.host) || unresolved(target.name)) return false;
    return Boolean(allow && !unresolved(allow) && allow === `${target.host}:${target.name}`);
}
