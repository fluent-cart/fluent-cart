import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * Product cards wear the card surface whenever FluentCart prints colours.
 *
 * The card was deliberately transparent so it sat on whatever the page
 * painted. But its text is palette text: a Customize dark palette on a light
 * Astra page put #f3f4f6 card text on the theme's white (1.1:1). While
 * FluentCart supplies colours (body.fluent-cart-custom without the no-colors
 * marker) the card is painted with card_bg_color, the surface that palette
 * text was chosen for. Under Default (body.fluent-cart-theme) no such rule
 * exists, so the card stays transparent exactly as before; with card_bg_color
 * unset the fallback is transparent as well.
 *
 * Source guard, not a render.
 */

const FILE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/public/product-card/style/product-card.scss');
// Comments stripped: they describe the rules, they are not rules.
const SOURCE = fs.readFileSync(FILE, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

const SELECTOR = 'body.fluent-cart-custom:not(.fluent-cart-no-colors) .fct-product-card';

function ruleBody(selector) {
    const start = SOURCE.indexOf(selector + ' {');

    expect(start, `${selector} is styled`).toBeGreaterThan(-1);

    return SOURCE.slice(start, SOURCE.indexOf('}', start));
}

describe('product card surface', () => {
    it('paints the card with the card colour while FluentCart prints colours', () => {
        expect(ruleBody(SELECTOR)).toMatch(/background-color:\s*var\(--fct-card-bg-color, transparent\);/);
    });

    it('never paints the card under Default or when the theme styles it', () => {
        // The only background given to .fct-product-card is the scoped one.
        const unscoped = [...SOURCE.matchAll(/([^{}]*\.fct-product-card[^{}]*)\{([^{}]*)\}/g)]
            .filter(([, header, body]) => /background(-color)?\s*:/.test(body) && !header.includes('fluent-cart-custom'));

        const onCardItself = unscoped.filter(([, header]) => /\.fct-product-card\s*$/.test(header.trim()));

        expect(onCardItself, 'no unscoped background on the card').toHaveLength(0);
        expect(SOURCE).not.toMatch(/fluent-cart-theme[^{]*\.fct-product-card[^{]*\{[^}]*background/);
    });
});

describe('placeholder image divider', () => {
    it('draws a thin divider under the placeholder image in the card border colour', () => {
        const css = require('node:fs').readFileSync(
            require('node:path').resolve(__dirname, '../../resources/public/product-card/style/product-card.scss'),
            'utf8'
        );
        const rule = css.match(/\.fct-product-card-image-wrap\.is-placeholder\s*\{([^}]*)\}/);

        expect(rule, 'the placeholder divider rule').not.toBeNull();
        expect(rule[1]).toContain('border-bottom: 1px solid var(--fct-card-border-color, #d6dae1)');
    });
});

/**
 * Outside the no-colors gate nothing hands the element to the theme, so every
 * colour must style itself: under fluent-cart-no-colors no palette global is
 * printed, a var() with no fallback is invalid, and the property computes to
 * its initial value (a border falls to currentColor). Aliases on :root may
 * forward a global bare; the rules that consume them may not. Spec case 144.
 */
describe('outside the no-colors gate', () => {
    function withoutGatedBlocks(source) {
        let result = source;
        let start;
        while ((start = result.search(/[^{};]*:not\(\.fluent-cart-no-colors\)[^{]*\{/)) > -1) {
            let depth = 0;
            let end = result.indexOf('{', start);
            for (; end < result.length; end++) {
                if (result[end] === '{') depth++;
                if (result[end] === '}' && --depth === 0) break;
            }
            result = result.slice(0, start) + result.slice(end + 1);
        }
        return result;
    }

    it('gives every colour a rule consumes a fallback', () => {
        const outside = withoutGatedBlocks(SOURCE)
            .split('\n')
            .filter((line) => !/^\s*--[a-z0-9-]+\s*:/.test(line));

        const bare = outside.filter((line) => /var\(\s*--[a-z0-9-]*(color|bg)\s*\)/.test(line)).map((line) => line.trim());

        expect(bare).toEqual([]);
    });
});
