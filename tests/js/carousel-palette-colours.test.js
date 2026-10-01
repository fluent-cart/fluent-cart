import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The Swiper carousels (Product Carousel, Media Carousel) follow the
 * storefront palette.
 *
 * Both stylesheets painted fixed greys and a near-black accent, so a store
 * with its own colours got a carousel in someone else's. They now read the
 * palette globals, each with the colour the carousel already showed as its
 * fallback — so a default store's carousel does not move.
 *
 * Colour map:
 *   arrow         → card background, secondary text icon, border colour
 *   arrow hover   → the button hover pair (falling back to the button pair)
 *   dots          → border colour / secondary text on hover / button when active
 *   dot pill      → card background, border colour
 *   progress      → divider track, button fill
 *   segments      → divider track, button when active or done
 *   fraction      → primary text; segment counter → secondary text
 *   card in slide → card background, border colour; active slide → active border
 *
 * The carousels are FluentCart's own chrome (like the shop's view switcher),
 * so they sit outside the no-colors gate: they always style themselves, and
 * with no palette printed every fallback is the old literal.
 *
 * Source guard, not a render.
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/public/carousel');

function blocks(file) {
    const css = fs.readFileSync(path.join(ROOT, file), 'utf8');

    return (css.match(/[^{}]+\{[^{}]*\}/g) || []).map((block) => {
        const open = block.indexOf('{');

        return {header: block.slice(0, open), body: block.slice(open + 1)};
    });
}

function expectRule(file, selector, snippet) {
    const found = blocks(file).some(({header, body}) => header.includes(selector) && body.includes(snippet));

    expect(found, `${file}: ${selector} carries ${snippet}`).toBe(true);
}

const HOVER_BG = (fallback) => `var(--fct-btn-hover-bg-color, var(--fct-btn-bg-color, ${fallback}))`;
const HOVER_TEXT = (fallback) => `var(--fct-btn-hover-text-color, var(--fct-btn-text-color, ${fallback}))`;

describe('product carousel follows the palette', () => {
    const file = 'products/style/product-carousel.scss';

    it('dresses the card in the slide', () => {
        expectRule(file, '.fct-product-carousel .fct-product-card', 'background: var(--fct-card-bg-color, #ffffff)');
        expectRule(file, '.fct-product-carousel .fct-product-card', 'border: 1px solid var(--fct-border-color, #e5e7eb)');
        expectRule(file, '.swiper-slide-active .fct-product-card', 'border-color: var(--fct-active-border-color, rgba(0, 115, 170, 0.45))');
    });

    it('rests the arrows on the card like the view switcher, and hovers them in the button hover pair', () => {
        expectRule(file, '.fct-product-carousel .swiper-button-next', 'background: var(--fct-card-bg-color, #ffffff)');
        expectRule(file, '.fct-product-carousel .swiper-button-next', 'color: var(--fct-secondary-text-color, #111827)');
        expectRule(file, '.fct-product-carousel .swiper-button-next', 'border: 1px solid var(--fct-border-color, #e5e7eb)');
        // The hover is a button: dark fallback background, white fallback
        // icon — the pattern Media Carousel and every primary button use.
        expectRule(file, '.fct-product-carousel .swiper-button-next:hover', `background: ${HOVER_BG('#111827')}`);
        expectRule(file, '.fct-product-carousel .swiper-button-next:hover', `color: ${HOVER_TEXT('#ffffff')}`);
        expectRule(file, '.fct-product-carousel .swiper-button-next:hover', `border-color: ${HOVER_BG('#111827')}`);
    });

    it('colours the dots and their pill', () => {
        expectRule(file, '.fct-product-carousel .swiper-pagination ', 'background: var(--fct-card-bg-color, rgba(255, 255, 255, 0.95))');
        expectRule(file, '.fct-product-carousel .swiper-pagination ', 'border: 1px solid var(--fct-border-color, rgba(229, 231, 235, 0.5))');
        expectRule(file, '.swiper-pagination-bullet ', 'background: var(--fct-border-color, #d1d5db)');
        expectRule(file, '.swiper-pagination-bullet:hover', 'background: var(--fct-secondary-text-color, #9ca3af)');
        expectRule(file, '.swiper-pagination-bullet-active', 'background: var(--fct-btn-bg-color, #111827)');
        expectRule(file, '.swiper-pagination-bullet-active', 'color-mix(in srgb, var(--fct-btn-bg-color, #111827) 20%, transparent)');
    });

    it('colours the progress bar, fraction and segments', () => {
        expectRule(file, '.swiper-pagination-progressbar ', 'background: var(--fct-divider-color, #d1d5db)');
        expectRule(file, '.swiper-pagination-progressbar-fill ', 'var(--fct-btn-bg-color, #111827) 0%');
        expectRule(file, '.swiper-pagination-progressbar-fill ', `${HOVER_BG('#374151')} 100%`);
        expectRule(file, '.fct-pagination-fraction .swiper-pagination ', 'color: var(--fct-primary-text-color, #111827)');
        expectRule(file, '.fct-pagination-fraction .swiper-pagination-total', 'color: var(--fct-primary-text-color, #111827)');
        expectRule(file, '.swiper-pagination-segment ', 'background: var(--fct-divider-color, #d1d5db)');
        expectRule(file, '.swiper-pagination-segment.is-active ', 'var(--fct-btn-bg-color, #111827) 0%');
        expectRule(file, '.swiper-pagination-segment.is-completed', 'background: var(--fct-btn-bg-color, #111827)');
        expectRule(file, '.segment-counter', 'color: var(--fct-secondary-text-color, #374151)');
    });

    it('hands Swiper its theme colour', () => {
        expectRule(file, '.fct-product-carousel ', '--swiper-theme-color: var(--fct-btn-bg-color, #007aff)');
    });
});

describe('media carousel binds its tokens to the palette', () => {
    const file = 'media/style/media-carousel.scss';
    const tokens = {
        '--fct-carousel-primary': 'var(--fct-btn-bg-color, #111827)',
        '--fct-carousel-primary-light': HOVER_BG('#1f2937'),
        '--fct-carousel-text': 'var(--fct-primary-text-color, #111827)',
        '--fct-carousel-secondary': 'var(--fct-secondary-text-color, #374151)',
        '--fct-carousel-border': 'var(--fct-border-color, #e5e7eb)',
        '--fct-carousel-border-emphasis': 'var(--fct-divider-color, #d1d5db)',
        '--fct-carousel-bg': 'var(--fct-card-bg-color, #ffffff)',
        '--fct-carousel-active-color': 'var(--fct-active-border-color, #111827)',
        '--fct-carousel-hover-border': 'var(--fct-active-border-color, #111827)',
        '--fct-carousel-dot-inactive': 'var(--fct-border-color, #d1d5db)',
        '--fct-carousel-dot-hover': 'var(--fct-secondary-text-color, #9ca3af)',
        '--fct-carousel-dot-active': 'var(--fct-btn-bg-color, #111827)',
        '--fct-carousel-pagination-bg': 'var(--fct-card-bg-color, rgba(255, 255, 255, 0.95))',
        '--fct-carousel-pagination-border': 'var(--fct-border-color, rgba(229, 231, 235, 0.5))',
        '--fct-carousel-arrow-bg-start': 'var(--fct-card-bg-color, #ffffff)',
        '--fct-carousel-arrow-bg-end': 'var(--fct-card-bg-color, #f9fafb)',
        '--fct-carousel-arrow-border': 'var(--fct-border-color, #e5e7eb)',
        '--fct-carousel-arrow-color': 'var(--fct-secondary-text-color, #111827)',
        '--fct-carousel-arrow-hover-bg-start': HOVER_BG('#111827'),
        '--fct-carousel-arrow-hover-bg-end': HOVER_BG('#1f2937'),
        '--fct-carousel-arrow-hover-color': HOVER_TEXT('#ffffff'),
    };

    it.each(Object.entries(tokens))('%s → %s', (token, value) => {
        expectRule(file, ':root', `${token}: ${value};`);
    });

    it('prints the fraction in the text token, not the accent', () => {
        expectRule(file, '.fct-pagination-fraction .swiper-pagination', 'color: var(--fct-carousel-text)');
    });

    it('tints the accent glows from the accent itself', () => {
        expectRule(file, '.swiper-pagination-bullet-active', 'color-mix(in srgb, var(--fct-carousel-dot-active) 20%, transparent)');
        expectRule(file, '.swiper-pagination-progressbar-fill', 'color-mix(in srgb, var(--fct-carousel-primary) 50%, transparent)');
        expectRule(file, '.swiper-pagination-segment.is-completed', 'color-mix(in srgb, var(--fct-carousel-primary) 20%, transparent)');
    });

    it('hands Swiper its theme colour', () => {
        expectRule(file, '.fct-product-carousel ', '--swiper-theme-color: var(--fct-btn-bg-color, #007aff);');
    });
});

/**
 * Resolves the hovered arrow the way the browser does, from the stylesheet's
 * own fallback chains, and measures the icon against its background.
 *
 * Two cases the CSS must survive on its own, whatever PHP prints:
 *  - no palette at all (a default store)
 *  - a dark button background with no text colour (customize allows it)
 * The icon falls back independently of the background, so the only safe
 * pairing is the one every primary button uses: dark background, white text.
 */
describe('hovered arrow keeps a readable icon', () => {
    function luminance(hex) {
        const channels = hex.replace('#', '').match(/../g).map((pair) => parseInt(pair, 16) / 255)
            .map((value) => (value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4)));

        return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
    }

    function contrast(first, second) {
        const [lighter, darker] = [luminance(first), luminance(second)].sort((a, b) => b - a);

        return (lighter + 0.05) / (darker + 0.05);
    }

    // var(--a, var(--b, #hex)) against a set of printed globals.
    function resolve(value, printed) {
        const match = value.match(/^var\((--[\w-]+),\s*(.+)\)$/);

        if (!match) {
            return value.trim();
        }

        return printed[match[1]] || resolve(match[2].trim(), printed);
    }

    // Media Carousel reads its hover through :root tokens.
    const CASES = [
        {
            file: 'products/style/product-carousel.scss',
            background: (css) => css.match(/\.swiper-button-next:hover\s*\{[^}]*?background:\s*([^;]+);/)[1],
            icon: (css) => css.match(/\.swiper-button-next:hover\s*\{[^}]*?\bcolor:\s*([^;]+);/)[1],
        },
        {
            file: 'media/style/media-carousel.scss',
            background: (css) => css.match(/--fct-carousel-arrow-hover-bg-start:\s*([^;]+);/)[1],
            icon: (css) => css.match(/--fct-carousel-arrow-hover-color:\s*([^;]+);/)[1],
        },
    ];

    const PALETTES = {
        'no palette': {},
        'a dark button with no text colour': {'--fct-btn-bg-color': '#1e1b4b'},
    };

    for (const {file, background, icon} of CASES) {
        const css = fs.readFileSync(path.join(ROOT, file), 'utf8');

        for (const [label, printed] of Object.entries(PALETTES)) {
            it(`${file}: ${label}`, () => {
                const bg = resolve(background(css), printed);
                const fg = resolve(icon(css), printed);

                expect(contrast(bg, fg), `${fg} on ${bg}`).toBeGreaterThanOrEqual(4.5);
            });
        }
    }
});
