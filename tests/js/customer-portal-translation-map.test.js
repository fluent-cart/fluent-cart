import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import {createRequire} from 'node:module';
import {importGraph} from './support/import-graph.js';

const require = createRequire(import.meta.url);
const Helper = require('../../resources/dev/TranslationHelper');

const PORTAL = 'resources/public/customer-profile/';
const MAP = fs.readFileSync('app/Services/Translations/customer-profile-translation.php', 'utf8');
const T_CALL = /(?<![\w$.])\$t\(\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"|`((?:[^`\\$]|\\.)*)`)/g;

const bundle = importGraph(PORTAL + 'Start.js');
const shared = bundle.filter(file => !file.startsWith(PORTAL));

describe('customer portal translation map', () => {
    it('follows the portal bundle into the admin components it shares', () => {
        expect(bundle).toContain(PORTAL + 'Vue/SingleOrder.vue');
        expect(shared).toContain('resources/admin/Bits/Components/BundleProducts.vue');
    });

    it('has every $t() string of a shared admin component, since $t is the portal translator here', () => {
        const missing = shared.flatMap(file => [...fs.readFileSync(file, 'utf8').matchAll(T_CALL)]
            .map(match => Helper.decodeJsStringLiteral(match[1] ?? match[2] ?? match[3]))
            .filter(text => !MAP.includes(Helper.phpLiteral(text) + ' =>'))
            .map(text => `${file}: ${text}`));

        expect(missing).toEqual([]);
    });

    it('does not show notices through the admin Notify, whose titles are not translated here', () => {
        expect(bundle).not.toContain('resources/admin/utils/Notify.js');
    });
});
