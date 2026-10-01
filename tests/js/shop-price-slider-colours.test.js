import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The shop's price-range slider (noUiSlider) follows the storefront palette.
 *
 * It used to fill the selected range with primary_bg_color — a pale surface
 * behind active states — and lay the track on secondary_bg_color, which is the
 * page white on most palettes. On a customised store both vanished: a pale
 * line on a white track. The handle kept noUiSlider's own white.
 *
 * Colour map:
 *   track        → divider_color
 *   filled line  → btn_bg_color
 *   handle fill  → btn_bg_color, ring → card_bg_color
 *   tooltip      → card_bg_color / primary_text_color / border_color
 *
 * Every rule sits inside the body:not(.fluent-cart-no-colors) gate, and every
 * raw palette variable falls back to the colour the default store already
 * shows, so the default look does not move.
 *
 * Source guard, not a render: the suite has no CSS engine for Tailwind @apply.
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources');

function gateBlock() {
    const source = fs.readFileSync(path.join(ROOT, 'public/product-page/style/shop-app.scss'), 'utf8');
    const start = source.indexOf('body:not(.fluent-cart-no-colors) {');

    expect(start, 'the no-colors gate exists').toBeGreaterThan(-1);

    // Walk braces to the gate's own closing brace.
    let depth = 0;
    for (let index = source.indexOf('{', start); index < source.length; index++) {
        if (source[index] === '{') depth++;
        if (source[index] === '}' && --depth === 0) {
            return source.slice(start, index + 1);
        }
    }

    throw new Error('the no-colors gate is never closed');
}

function ruleBody(block, selector) {
    const start = block.indexOf(selector + ' {');

    expect(start, `${selector} is styled inside the gate`).toBeGreaterThan(-1);

    return block.slice(start, block.indexOf('}', start));
}

describe('shop price slider colours', () => {
    const gate = gateBlock();

    it('fills the selected range with the button colour, not the pale active surface', () => {
        const body = ruleBody(gate, '.fct-shop-range-slider .noUi-connect');

        expect(body).toContain('var(--fct-shop-btn-bg-color)');
        expect(body).not.toContain('--fct-shop-primary-bg-color');
    });

    it('lays the track on the divider colour, not the page-white secondary surface', () => {
        expect(gate).toMatch(/--fct-shop-no-ui-target-bg:\s*var\(--fct-shop-divider-color\)/);
        expect(gate).not.toMatch(/--fct-shop-no-ui-target-bg:\s*var\(--fct-shop-secondary-bg-color/);
    });

    it('gives the handle the button fill and a card-coloured ring, keeping noUiSlider\'s look when unset', () => {
        const body = ruleBody(gate, '.fct-shop-range-slider .noUi-handle');

        expect(body).toContain('var(--fct-btn-bg-color, #ffffff)');
        expect(body).toContain('var(--fct-card-bg-color, #d9d9d9)');
    });

    it('dresses the tooltip in the card, text and border colours, keeping noUiSlider\'s look when unset', () => {
        const body = ruleBody(gate, '.fct-shop-range-slider .noUi-tooltip');

        expect(body).toContain('var(--fct-card-bg-color, #ffffff)');
        expect(body).toContain('var(--fct-primary-text-color, #000000)');
        expect(body).toContain('var(--fct-border-color, #d9d9d9)');
    });
});

describe('price slider chrome', () => {
    const source = fs.readFileSync(path.join(ROOT, 'public/product-page/style/shop-app.scss'), 'utf8');

    it('draws the handle flat, with no glow', () => {
        const handle = source.match(/\.fct-shop-range-slider\s*\{[\s\S]*?\.noUi-handle\s*\{([^}]*)\}/);

        expect(handle, 'the handle rule').not.toBeNull();
        expect(handle[1]).not.toMatch(/shadow-\[/);
        expect(handle[1]).toContain('shadow-none');
    });

    it('keeps the read-only price fields free of any field background', () => {
        // They are display-only (pointer-events-none): a theme's input
        // background must never paint them as editable boxes.
        const input = source.match(/\n\.fc_price_range_input\s*\{([^}]*)\}/);

        expect(input, 'the price input rule').not.toBeNull();
        expect(input[1]).toContain('bg-transparent');
        expect(input[1]).toContain('shadow-none');
        expect(input[1]).toContain('!important');
    });
});
