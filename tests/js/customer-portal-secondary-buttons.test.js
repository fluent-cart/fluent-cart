import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The portal's secondary action is the plain Element Plus button: card-side
 * surface, secondary text and border, a soft hover — the palette's secondary
 * pair. Download (a per-row action) and the dialogs' Cancel used Element
 * Plus' info/plain and info/soft variants instead, painted in fixed greys, so
 * on a dark palette they were light chips; Download, an <a>, also took the
 * theme's link underline (Astra: `.ast-single-post .entry-content a`).
 *
 * Source guard, not a render. Spec: dev/wp-browser/tests/Cases/storefront-colours.md [271]
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/public/customer-profile');
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');

const DOWNLOADS = read('Vue/parts/DownloadsTable.vue');
const CANCELS = {
    'Vue/UserProfile/templates/AddressModal.vue': read('Vue/UserProfile/templates/AddressModal.vue'),
    'Vue/parts/TransactionTable.vue': read('Vue/parts/TransactionTable.vue'),
};
const BUTTONS = read('style/element-plus/button.scss');

describe('secondary actions in the portal are the plain secondary button', () => {
    it('Download is a plain button, not the info/plain chip', () => {
        const link = DOWNLOADS.match(/<a[\s\S]*?class="([^"]*)"[\s\S]*?>/);

        expect(link, 'the download link').not.toBeNull();
        expect(link[1]).toContain('el-button');
        expect(link[1]).not.toMatch(/el-button--info|is-plain/);
    });

    it.each(Object.entries(CANCELS))('%s: Cancel is a plain button, not the info/soft chip', (file, source) => {
        const cancel = source.match(/<el-button([^>]*)>\s*\{\{\s*translate\('Cancel'\)\s*\}\}/);

        expect(cancel, 'the Cancel button').not.toBeNull();
        expect(cancel[1]).not.toMatch(/type="info"|\bsoft\b/);
    });

    it('the plain button owns its pair: background from the secondary token, like its text and border', () => {
        const base = BUTTONS.match(/\.el-button\{\s*@apply([^;]*);/);

        expect(base, 'the base .el-button rule').not.toBeNull();
        expect(base[1]).toContain('bg-[var(--fct-customer-dashboard-secondary-btn-bg,#ffffff)]');
        expect(base[1]).toContain('text-[var(--fct-customer-dashboard-secondary-btn-text,#2F3448)]');
    });

    it('a link that is a button states every state, so the theme link underline and colour never reach it', () => {
        const rule = BUTTONS.match(/a\.el-button:is\(:link, :visited, :hover, :focus, :active\)\s*\{([^}]*)\}/);

        expect(rule, 'the link-button state rule').not.toBeNull();
        expect(rule[1]).toMatch(/text-decoration:\s*none/);
    });

    it('a primary link-button keeps the primary text pair in every state', () => {
        // The generic link-button rule sets the secondary text colour; without
        // these, renew/reactivate would wear dark text on the primary fill.
        expect(BUTTONS).toMatch(/a\.el-button--primary:is\(:link, :visited\)\s*\{\s*color:\s*var\(--fct-customer-dashboard-btn-text-color, #ffffff\);/);
        expect(BUTTONS).toMatch(/a\.el-button--primary:is\(:hover, :focus, :active\)\s*\{\s*color:\s*var\(--fct-customer-dashboard-btn-hover-text-color, #ffffff\);/);
        expect(BUTTONS.indexOf('a.el-button--primary:is(')).toBeGreaterThan(BUTTONS.indexOf('a.el-button:is('));
    });

    it('the x-small size reads the button radius token', () => {
        const xSmall = BUTTONS.match(/&--x-small\{([^}]*)\}/);

        expect(xSmall, 'the x-small rule').not.toBeNull();
        expect(xSmall[1]).not.toMatch(/\brounded-md\b/);
        expect(xSmall[1]).toContain("border-radius: var(--fct-btn-radius, theme('borderRadius.md'))");
    });
});
