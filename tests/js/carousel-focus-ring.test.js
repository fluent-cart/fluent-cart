import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * Block themes print `:where(.wp-site-blocks :focus) { outline: 2px solid }`,
 * and Swiper makes the dots and arrows focusable. Safari drew that outline as
 * a square box around the round dot after a click.
 *
 * Each carousel stylesheet drops the outline on :focus and gives keyboard
 * focus a round box-shadow ring instead. Source guard, not a render.
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

const STYLESHEETS = [
    'resources/public/carousel/products/style/product-carousel.scss',
    'resources/public/carousel/media/style/media-carousel.scss',
];

const CONTROLS = [
    '.fct-product-carousel .swiper-pagination-bullet',
    '.fct-product-carousel .swiper-button-prev',
    '.fct-product-carousel .swiper-button-next',
];

// The declarations of the rule whose selector list includes `selector`.
const ruleBodyFor = (css, selector) => {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = css.match(new RegExp(`(?:^|[},])[^{}]*${escaped}(?=\\s*[,{])[^{}]*\\{([^}]*)\\}`, 'm'));

    return match ? match[1] : null;
};

describe.each(STYLESHEETS)('%s', (file) => {
    const css = fs.readFileSync(path.join(ROOT, file), 'utf8');

    it.each(CONTROLS)('%s drops the square outline on :focus', (control) => {
        expect(ruleBodyFor(css, `${control}:focus`)).toMatch(/outline:\s*none/);
    });

    it.each(CONTROLS)('%s draws a round ring on :focus-visible', (control) => {
        expect(ruleBodyFor(css, `${control}:focus-visible`)).toMatch(/box-shadow:/);
    });
});
