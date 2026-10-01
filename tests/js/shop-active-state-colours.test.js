import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The shop's active states and paginator follow the button pair, not the
 * pale accent — found by browser QA.
 *
 *   paginator current page  → btn_bg / btn_text (was primary_bg + hard #fff:
 *                             1.08–2.75:1 on a pale or yellow accent)
 *   paginator box           → card_bg (was a hard #fff box on dark pages)
 *   paginator hover         → secondary_btn_hover_bg (was a hard #F5F6F7)
 *   reset-filter icon       → primary text (was fixed text-system-dark on the
 *                             palette's secondary bg: 1.4:1 on dark palettes)
 *   view switcher active    → btn_bg (was primary_bg: 1.07:1 on a pale accent)
 *   checkmark / sort radio  → btn_bg fill, btn_text tick
 *
 * Default must not move: every new binding falls back to the literal the
 * surface already resolved to with no palette printed (#253241 / #ffffff /
 * #F5F6F7 / #2F3448). Where both ends of a pair are literal fallbacks, the
 * resolved contrast is checked too.
 *
 * Source guard, not a render: the suite has no CSS engine for Tailwind @apply.
 */

const FILE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/public/product-page/style/shop-app.scss');
const SOURCE = fs.readFileSync(FILE, 'utf8');

function blockFrom(opener) {
    const start = SOURCE.indexOf(opener);

    expect(start, `${opener} exists`).toBeGreaterThan(-1);

    let depth = 0;
    for (let index = SOURCE.indexOf('{', start); index < SOURCE.length; index++) {
        if (SOURCE[index] === '{') depth++;
        if (SOURCE[index] === '}' && --depth === 0) {
            return SOURCE.slice(start, index + 1);
        }
    }

    throw new Error(`${opener} is never closed`);
}

const ROOT_BLOCK = blockFrom(':root {');
const GATE = blockFrom('body:not(.fluent-cart-no-colors) {');

// Every declaration of a property in a block (the gate declares some twice;
// the last one wins, so all of them must agree).
function declarations(block, property) {
    const pattern = new RegExp(`${property.replace(/[-]/g, '\\-')}:\\s*([^;]+);`, 'g');

    return [...block.matchAll(pattern)].map((match) => match[1].trim());
}

function expectEvery(block, property, value) {
    const found = declarations(block, property);

    expect(found.length, `${property} is declared`).toBeGreaterThan(0);
    found.forEach((declared) => expect(declared, property).toBe(value));
}

function ruleBody(block, selector) {
    const start = block.indexOf(selector + ' {');

    expect(start, `${selector} is styled`).toBeGreaterThan(-1);

    return block.slice(start, block.indexOf('}', start));
}

function luminance(hex) {
    const value = hex.replace('#', '');
    const full = value.length === 3 ? value.split('').map((c) => c + c).join('') : value;
    const channels = [0, 2, 4].map((offset) => parseInt(full.slice(offset, offset + 2), 16) / 255)
        .map((c) => (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)));

    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

function contrast(first, second) {
    const [light, dark] = [luminance(first), luminance(second)].sort((a, b) => b - a);

    return (light + 0.05) / (dark + 0.05);
}

// color-mix(in srgb, <color> N%, transparent) over a white page.
function tintOverWhite(hex, percent) {
    const full = hex.replace('#', '');
    const mixed = [0, 2, 4].map((offset) => {
        const channel = parseInt(full.slice(offset, offset + 2), 16);

        return Math.round(channel * percent / 100 + 255 * (1 - percent / 100)).toString(16).padStart(2, '0');
    });

    return '#' + mixed.join('');
}

describe('I-1 shop paginator follows the button pair and the card', () => {
    it('paints the current page with the button pair, falling back to today\'s navy and white', () => {
        expectEvery(GATE, '--fct-shop-paginator-items-active-bg', 'var(--fct-btn-bg-color, #253241)');
        expectEvery(GATE, '--fct-shop-paginator-items-active-color', 'var(--fct-btn-text-color, #ffffff)');
        expectEvery(ROOT_BLOCK, '--fct-shop-paginator-items-active-bg', 'var(--fct-btn-bg-color, #253241)');
        expect(contrast('#253241', '#ffffff')).toBeGreaterThanOrEqual(4.5);
    });

    it('sits the paginator box on the card colour and hovers on a palette surface', () => {
        expectEvery(GATE, '--fct-shop-paginator-bg', 'var(--fct-card-bg-color, #ffffff)');
        expectEvery(GATE, '--fct-shop-paginator-items-hover-bg', 'var(--fct-secondary-btn-hover-bg-color, #F5F6F7)');
        // Default: the inactive numbers (#565865) on the white box and its hover.
        expect(contrast('#565865', '#ffffff')).toBeGreaterThanOrEqual(4.5);
        expect(contrast('#565865', '#F5F6F7')).toBeGreaterThanOrEqual(4.5);
    });

    it('no longer hard-codes the white box, white number or grey hover', () => {
        expect(GATE).not.toMatch(/--fct-shop-paginator-bg:\s*#fff\s*;/);
        expect(GATE).not.toMatch(/--fct-shop-paginator-items-active-color:\s*#fff\s*;/);
        expect(GATE).not.toMatch(/--fct-shop-paginator-items-hover-bg:\s*#F5F6F7\s*;/);
        expect(GATE).not.toMatch(/--fct-shop-paginator-items-active-bg:\s*var\(--fct-shop-primary-bg-color/);
    });
});

describe('I-4 reset-filter button reads on the palette\'s secondary surface', () => {
    it('takes the shop\'s primary text instead of fixed text-system-dark', () => {
        const body = ruleBody(GATE, '.fct-shop-reset-filter-button');

        expect(body).not.toContain('text-system-dark');
        expect(body).toContain('text-[var(--fct-shop-primary-text-color)]');
        expect(body).toContain('hover:text-[var(--fct-shop-primary-text-color)]');
        // Its fallback is text-system-dark's own value, on the default #EAECF0.
        expectEvery(GATE, '--fct-shop-primary-text-color', 'var(--fct-primary-text-color, #2F3448)');
        expect(contrast('#2F3448', '#EAECF0')).toBeGreaterThanOrEqual(4.5);
    });
});

describe('I-5 view switcher, checkmark and sort radio follow the button colour', () => {
    // A filled button pair when a palette is printed; today's navy tint and
    // navy icon when none is. (A tint of the button colour alone vanished on
    // Twenty Twenty-Five's dark variation, whose button is the page colour.)
    const SWITCHER_BG = 'var(--fct-btn-bg-color, color-mix(in srgb, #253241 12%, transparent))';
    const SWITCHER_ICON = 'var(--fct-btn-text-color, #253241)';

    it('bases the active view on the button pair, in both blocks that declare it', () => {
        for (const block of [ROOT_BLOCK, GATE]) {
            expectEvery(block, '--fct-shop-view-switcher-active-bg', SWITCHER_BG);
            expectEvery(block, '--fct-shop-view-switcher-active-color', SWITCHER_ICON);
        }

        // Default: the navy icon on its own 12% tint over a white page.
        expect(contrast('#253241', tintOverWhite('#253241', 12))).toBeGreaterThanOrEqual(3);
    });

    it('fills a checked checkbox with the button colour and ticks it in the button text', () => {
        expectEvery(GATE, '--fct-shop-checkmark-active-bg', 'var(--fct-btn-bg-color, #253241)');
        expectEvery(GATE, '--fct-shop-checkmark-check-border-color', 'var(--fct-btn-text-color, #ffffff)');
        expect(contrast('#253241', '#ffffff')).toBeGreaterThanOrEqual(4.5);
    });

    it('fills the selected sort radio with the button colour and dots it in the button text', () => {
        const radio = SOURCE.slice(SOURCE.indexOf('.fct-sorting-radio {'));

        expect(radio.slice(0, radio.indexOf('transition: transform'))).toContain('background-color: var(--fct-btn-text-color, #fff);');

        expectEvery(GATE, '--fct-shop-radio-active-bg', 'var(--fct-btn-bg-color, #253241)');
        expectEvery(ROOT_BLOCK, '--fct-shop-radio-active-bg', 'var(--fct-btn-bg-color, #253241)');
        expectEvery(ROOT_BLOCK, '--fct-shop-checkmark-active-bg', 'var(--fct-btn-bg-color, #253241)');
    });

    it('keeps the chrome\'s use-site fallbacks at today\'s navy', () => {
        const chrome = SOURCE.slice(SOURCE.indexOf('.fct-shop-view-switcher button {'));

        expect(chrome).toContain('text-[var(--fct-shop-view-switcher-active-color,#253241)]');
        expect(chrome).toContain('bg-[var(--fct-shop-radio-active-bg,#253241)]');
    });

    it('no longer reads the pale accent for any active state', () => {
        for (const property of [
            '--fct-shop-view-switcher-active-bg',
            '--fct-shop-view-switcher-active-color',
            '--fct-shop-checkmark-active-bg',
            '--fct-shop-radio-active-bg',
            '--fct-shop-paginator-items-active-bg',
        ]) {
            [...declarations(ROOT_BLOCK, property), ...declarations(GATE, property)]
                .forEach((declared) => expect(declared, property).not.toContain('primary-bg-color'));
        }
    });
});
