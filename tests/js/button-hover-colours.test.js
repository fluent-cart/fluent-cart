import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * Every primary storefront button hovers in the palette's hover pair.
 *
 * Each stylesheet scopes the button pair (`--fct-<scope>-btn-bg-color`,
 * `--fct-<scope>-btn-text-color`) off the palette globals. They used to pin
 * the hover to that same resting pair, because the palette had no hover to
 * offer. Now each scope declares a hover pair beside its resting pair —
 * `var(--fct-btn-hover-*, <the resting colour>)` — so a store that sets no
 * hover still hovers exactly as it always did, and every hover rule reads it.
 *
 * Source guard, not a render: the suite has no CSS engine for Tailwind @apply.
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/public');

const SCOPES = [
    {file: 'checkout/style/checkout.scss', scope: 'checkout'},
    {file: 'checkout/style/checkout-iframe.scss', scope: 'modal-checkout'},
    {file: 'cart-drawer/cart-drawer.scss', scope: 'cart-drawer'},
    {file: 'product-card/style/product-card.scss', scope: 'card'},
    {file: 'buttons/direct-checkout/style/style.scss', scope: 'direct'},
    {file: 'product-page/style/shop-app.scss', scope: 'shop'},
];

// Comment lines are dropped: a commented-out rule is not a rule.
function source(file) {
    return fs.readFileSync(path.join(ROOT, file), 'utf8')
        .split('\n')
        .filter((line) => !line.trim().startsWith('//'))
        .join('\n');
}

function count(haystack, needle) {
    return haystack.split(needle).length - 1;
}

describe('Buy Now content follows the button text colour', () => {
    // The label and icon sit inside the button, and used to be forced white.
    // A hover text measured dark (for 4.5:1 on a light hover) then never
    // reached them: the button turned dark while its label stayed white.
    const css = source('buttons/direct-checkout/style/style.scss');

    it('never forces its content white', () => {
        expect(css).not.toContain('text-white');
    });

    it('lets its content inherit the button colour, resting and hovered', () => {
        const descendant = css.match(/\.fluent-cart-direct-checkout-button\s*\{[\s\S]*?\n\s*\*\s*\{([^}]*)\}/);

        expect(descendant, 'a descendant rule inside the button').not.toBeNull();
        expect(descendant[1]).toContain('color: inherit');
    });
});

describe.each(SCOPES)('$file primary button hover', ({file, scope}) => {
    const css = source(file);
    const restBg = `--fct-${scope}-btn-bg-color`;
    const restText = `--fct-${scope}-btn-text-color`;
    const hoverBg = `--fct-${scope}-btn-hover-bg-color`;
    const hoverText = `--fct-${scope}-btn-hover-text-color`;

    it('declares a hover pair beside every resting pair, falling back to the resting colour', () => {
        const restDeclarations = count(css, `${restBg}:`);

        expect(restDeclarations).toBeGreaterThan(0);
        expect(count(css, `${hoverBg}: var(--fct-btn-hover-bg-color, var(${restBg}));`)).toBe(restDeclarations);
        expect(count(css, `${hoverText}: var(--fct-btn-hover-text-color, var(${restText}));`)).toBe(restDeclarations);
    });

    it('hovers in the hover pair, never back in the resting background', () => {
        expect(css).toContain(`var(${hoverBg})`);
        expect(css).not.toContain(`hover:bg-[var(${restBg})]`);
        expect(css).not.toContain(`hover:text-[var(${restText})]`);

        // Plain-CSS hover blocks: nothing inside `:hover { ... }` reads the
        // resting background.
        for (const block of css.match(/:hover\s*\{[^}]*\}/g) || []) {
            expect(block).not.toContain(`var(${restBg})`);
        }
    });
});
