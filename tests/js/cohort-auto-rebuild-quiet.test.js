import {describe, expect, it} from 'vitest';
import fs from 'node:fs';

/**
 * The Cohorts page rebuilds by itself after an update. A second visitor who opens it while that rebuild runs must not
 * get an "already running" error: only a rebuild the user asked for reports it.
 */
const source = fs.readFileSync(new URL('../../resources/admin/Modules/Reports/Subscription/Cohort.vue', import.meta.url), 'utf8');

describe('Cohorts automatic rebuild', () => {
    it('stays quiet when another rebuild is already running', () => {
        expect(source).toMatch(/response\.rebuild_needed && !generating\.value\)\s*\{\s*runBuild\(\{auto: true\}\);/);
        expect(source).toMatch(/if \(options && options\.auto && started\.code === "already_running"\) \{\s*return;\s*\}/);
    });
});
