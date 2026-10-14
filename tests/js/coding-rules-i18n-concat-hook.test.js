import {describe, expect, it} from 'vitest';
import {spawnSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The i18n concat rule runs on every Edit/Write. Its "translate(...) +" check
 * backtracked exponentially on long template text, so the hook hung and
 * blocked every edit to a large Vue file. Each check runs in a child process
 * with a timeout so a regression fails here instead of hanging the suite.
 */

const RULE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../.claude/hooks/coding-rules/no-bare-i18n-concat.js');

function runRule(content, timeout = 3000) {
    const script = `
        const rule = require(${JSON.stringify(RULE)});
        const content = require('fs').readFileSync(0, 'utf8');
        process.stdout.write(JSON.stringify(rule({filePath: '/x/Settings.vue', content})));
    `;
    const result = spawnSync(process.execPath, ['-e', script], {input: content, timeout, encoding: 'utf8'});
    return {timedOut: result.error?.code === 'ETIMEDOUT' || result.signal === 'SIGTERM', violations: result.stdout ? JSON.parse(result.stdout) : null};
}

describe('no-bare-i18n-concat hook rule', () => {
    it('finishes quickly on a long translate() call with no concatenation', () => {
        const content = `<p>{{ translate('${'Prices are kept in the base currency and converted at the rate you set'.repeat(2)}') }}</p>`;
        const {timedOut, violations} = runRule(content);

        expect(timedOut).toBe(false);
        expect(violations).toEqual([]);
    });

    it('still blocks a translate() call followed by +', () => {
        expect(runRule(`{{ translate('Rate history') + ' — ' + history.currency }}`).violations).toHaveLength(1);
        expect(runRule(`translate('a', fn(b)) + c`).violations).toHaveLength(1);
    });

    it('allows a translate() call that is not concatenated', () => {
        expect(runRule(`translate('%1$s VAT', code)`).violations).toEqual([]);
        expect(runRule(`translate('a', b) - c`).violations).toEqual([]);
    });
});
