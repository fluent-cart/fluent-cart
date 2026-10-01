import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * Product reviews follow the storefront palette through their own token layer
 * (`--fct-review-*`), the same shape as the shop's `--fct-shop-*`:
 *
 *   :root                            token → palette global (no fallback)
 *   body:not(.fluent-cart-no-colors) token → palette global, today's literal
 *   rules                            var(--fct-review-*, today's literal)
 *
 * Browser QA found the reviewer name and title printed in the palette's light
 * text on a hard-coded white card (1.1:1 under a dark inherited palette), and
 * the filter chips and empty rating bars hard-coded white on a dark page.
 *
 * Default must not move: every token falls back to the exact literal its rules
 * had, so with no palette printed the section looks as it did. Stars are not
 * palette colours — they stay gold by convention, and the block colour
 * attribute (--fct-star-color) still overrides them.
 *
 * Source guard, not a render: the suite has no CSS engine for Tailwind @apply.
 */

const ROOT_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (file) => fs.readFileSync(path.join(ROOT_DIR, file), 'utf8');

const REVIEWS = read('resources/public/single-product/reviews.scss');
const CARD = read('resources/public/product-card/style/product-card.scss');
const RENDERER = read('app/Services/Renderer/ProductReviewRenderer.php');
const EDITOR = read('resources/admin/BlockEditor/ProductReviewList/style/product-review-list-block-editor.css');

const STAR_DEFAULT = '#f59e0b';

// token → [palette global the token reads, the literal it falls back to]
// A global given as a full var() chain is written out as it is declared.
const PALETTE_TOKENS = {
    // text
    '--fct-review-heading-color': ['--fct-single-product-primary-text-color, var(--fct-primary-text-color', '#1f2937'],
    '--fct-review-text-color': ['--fct-primary-text-color', '#1f2937'],
    '--fct-review-strong-text-color': ['--fct-primary-text-color', '#111827'],
    '--fct-review-body-text-color': ['--fct-primary-text-color', '#374151'],
    '--fct-review-label-color': ['--fct-primary-text-color', '#2F3448'],
    '--fct-review-secondary-text-color': ['--fct-secondary-text-color', '#565865'],
    '--fct-review-muted-text-color': ['--fct-secondary-text-color', '#6b7280'],
    '--fct-review-meta-text-color': ['--fct-secondary-text-color', '#696778'],
    '--fct-review-subtle-text-color': ['--fct-secondary-text-color', '#4b5563'],
    '--fct-review-note-text-color': ['--fct-secondary-text-color', '#64748b'],
    '--fct-review-faint-text-color': ['--fct-secondary-text-color', '#9D9FAC'],
    '--fct-review-caption-color': ['--fct-secondary-text-color', '#98A2B3'],
    '--fct-review-accent-color': ['--fct-accent-color, var(--fct-primary-active-text-color', '#3b82f6'],

    // surfaces, borders, dividers
    '--fct-review-card-bg': ['--fct-card-bg-color', '#ffffff'],
    '--fct-review-card-border-color': ['--fct-border-color', '#D6DAE1'],
    '--fct-review-panel-border-color': ['--fct-border-color', '#e5e7eb'],
    '--fct-review-divider-color': ['--fct-divider-color', '#EAECF0'],
    '--fct-review-separator-color': ['--fct-divider-color', '#e5e7eb'],
    '--fct-review-soft-divider-color': ['--fct-divider-color', '#f3f4f6'],
    '--fct-review-surface-alt': ['--fct-secondary-bg-color', '#F5F6F7'],
    '--fct-review-cta-bar-bg': ['--fct-secondary-bg-color', '#f8f8f7'],
    '--fct-review-tile-bg': ['--fct-secondary-bg-color', '#f3f4f6'],
    '--fct-review-tile-hover-bg': ['--fct-secondary-btn-hover-bg-color', '#e5e7eb'],
    '--fct-review-bar-track': ['--fct-border-color', '#F5F6F7'],
    '--fct-review-pill-bg': ['--fct-card-bg-color', 'rgb(255 255 255 / 95%)'],
    '--fct-review-pill-border-color': ['--fct-border-color', 'rgb(229 231 235 / 50%)'],

    // controls: chips, close buttons, star picker, slider arrows
    '--fct-review-control-bg': ['--fct-card-bg-color', '#ffffff'],
    '--fct-review-control-border-color': ['--fct-border-color', '#D6DAE1'],
    '--fct-review-control-hover-bg': ['--fct-secondary-btn-hover-bg-color', '#F5F6F7'],
    '--fct-review-chip-bg': ['--fct-card-bg-color', '#ffffff'],
    '--fct-review-chip-text': ['--fct-secondary-text-color', '#565865'],
    '--fct-review-chip-border': ['--fct-border-color', '#D6DAE1'],
    '--fct-review-chip-hover-bg': ['--fct-secondary-btn-hover-bg-color', '#EAECF0'],
    '--fct-review-chip-active-bg': ['--fct-secondary-bg-color', '#EAECF0'],
    '--fct-review-chip-active-text': ['--fct-primary-text-color', '#565865'],
    '--fct-review-chip-active-border': ['--fct-active-border-color', '#D6DAE1'],

    // sort select and the review form
    '--fct-review-select-text': ['--fct-input-text-color', '#565865'],
    '--fct-review-select-focus-border': ['--fct-active-border-color', '#EAECF0'],
    '--fct-review-input-bg': ['--fct-input-bg-color', '#ffffff'],
    '--fct-review-input-text': ['--fct-input-text-color', '#2f3448'],
    '--fct-review-input-border': ['--fct-border-color', '#d6dae1'],
    '--fct-review-input-focus-border': ['--fct-active-border-color', '#1f2937'],
    '--fct-review-input-placeholder': ['--fct-input-placeholder-text-color', '#6b7280'],

    // primary button pair (next / submit, current page, active step)
    '--fct-review-btn-bg': ['--fct-btn-bg-color', '#1f2937'],
    '--fct-review-btn-text': ['--fct-btn-text-color', '#ffffff'],
    '--fct-review-btn-hover-bg': ['--fct-btn-hover-bg-color, var(--fct-btn-bg-color', '#374151'],
    '--fct-review-btn-hover-text': ['--fct-btn-hover-text-color, var(--fct-btn-text-color', '#ffffff'],

    // Write a Review: an outline button that fills with the button pair
    '--fct-review-cta-bg': ['--fct-secondary-btn-bg-color', '#ffffff'],
    '--fct-review-cta-text': ['--fct-secondary-btn-text-color', '#2F3448'],
    '--fct-review-cta-border': ['--fct-secondary-btn-border-color', '#D6DAE1'],
    '--fct-review-cta-hover-bg': ['--fct-btn-hover-bg-color, var(--fct-btn-bg-color', '#253241'],
    '--fct-review-cta-hover-text': ['--fct-btn-hover-text-color, var(--fct-btn-text-color', '#ffffff'],

    // secondary buttons (Back, page numbers, helpful votes)
    '--fct-review-secondary-btn-bg': ['--fct-secondary-btn-bg-color', '#ffffff'],
    '--fct-review-secondary-btn-text': ['--fct-secondary-btn-text-color', '#374151'],
    '--fct-review-secondary-btn-border': ['--fct-secondary-btn-border-color', '#d1d5db'],
    '--fct-review-secondary-btn-hover-bg': ['--fct-secondary-btn-hover-bg-color', '#f3f4f6'],
    '--fct-review-secondary-btn-hover-border': ['--fct-active-border-color', '#6b7280'],
    '--fct-review-vote-text': ['--fct-secondary-btn-text-color', '#1f2937'],
    '--fct-review-vote-hover-bg': ['--fct-secondary-btn-hover-bg-color', '#f9fafb'],
    '--fct-review-vote-hover-border': ['--fct-active-border-color', '#9ca3af'],

    // slider pagination and the dot pager
    '--fct-review-dot-color': ['--fct-border-color', '#d1d5db'],
    '--fct-review-dot-hover-color': ['--fct-secondary-text-color', '#9ca3af'],
    '--fct-review-indicator-color': ['--fct-btn-bg-color', '#111827'],
    '--fct-review-indicator-mid-color': ['--fct-btn-bg-color', '#1f2937'],

    // avatar and variation badge
    '--fct-review-avatar-bg': ['--fct-secondary-bg-color', '#EAECF0'],
    '--fct-review-avatar-icon': ['--fct-secondary-text-color', '#98A2B3'],
    '--fct-review-badge-bg': ['--fct-secondary-bg-color', '#EAECF0'],
    '--fct-review-badge-text': ['--fct-secondary-text-color', '#8E99AA'],
};

// Tokens that never read a palette global.
const FIXED_TOKENS = {
    '--fct-review-star-color': STAR_DEFAULT,
    '--fct-review-star-empty-color': '#d1d5db',
    '--fct-review-star-picker-color': '#98A2B3',
    '--fct-review-focus-ring': 'var(--fct-accent-color, #3b82f6)',
};

// Layout tokens that pre-date the colour layer.
const LAYOUT_TOKENS = ['--fct-review-columns', '--fct-review-thumb-w', '--fct-review-thumb-h'];

/*
 * Colours that stay literal on purpose, by the selector that paints them.
 * Semantic/status colours, surfaces paired with a fixed partner, and the
 * photo overlays whose contrast is set against a photograph, not the page.
 */
const LITERAL_ALLOWED = [
    /\.fct-reviews-error/,                    // error state red
    /\.fct-review-form-message-(success|error)/, // form status
    /\.required/,                             // required asterisk red
    /\.fct-review-verified/,                  // verified badge green
    /\.fct-review-reply/,                     // store reply: green surface + its own text
    /\.fct-star-tooltip.*&\.fct-(danger|warning|info|success)/, // rating words by meaning
    /completed/,                              // stepper: completed step green
    /\.fct-review-modal-overlay|\.fct-review-drawer-overlay|\.fct-review-form-modal-overlay/, // backdrops
    /box-shadow|rgba?\(0,? 0,? 0/,            // shadows
    /\.fct-review-media-gallery--backdrop|:has\(\.fct-review-media-gallery--backdrop\)/, // text over a photo
    /\.fct-review-media-(play|video|more\.is-overlay)/, // video / count badge over a photo
    /\.fct-review-modal-loading/,             // translucent veil over the loading thread
    /\.swiper-pagination-bullet-active|is-completed/, // indicator glow (decorative shadow)
    /fct-lightbox/,
];

function blockFrom(source, opener) {
    const start = source.indexOf(opener);

    expect(start, `${opener} exists`).toBeGreaterThan(-1);

    let depth = 0;
    for (let index = source.indexOf('{', start); index < source.length; index++) {
        if (source[index] === '{') depth++;
        if (source[index] === '}' && --depth === 0) {
            return source.slice(start, index + 1);
        }
    }

    throw new Error(`${opener} is never closed`);
}

function stripComments(source) {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '')
        .replace(/([;{}])\s*\/\/.*$/gm, '$1');
}

function declarations(block, property) {
    const pattern = new RegExp(`${property.replace(/[-]/g, '\\-')}:\\s*([^;]+);`, 'g');

    return [...block.matchAll(pattern)].map((match) => match[1].trim());
}

// Every declaration in the file with the selector path it sits under.
function walk(source) {
    const clean = stripComments(source);
    const stack = [];
    const found = [];
    let buffer = '';
    let parens = 0;

    for (const char of clean) {
        if (char === '(') parens++;
        if (char === ')') parens--;

        if (char === '{' && parens === 0) {
            stack.push(buffer.trim());
            buffer = '';
        } else if (char === '}' && parens === 0) {
            if (buffer.trim()) found.push({path: stack.join(' » '), text: buffer.trim()});
            stack.pop();
            buffer = '';
        } else if (char === ';' && parens === 0) {
            found.push({path: stack.join(' » '), text: buffer.trim()});
            buffer = '';
        } else {
            buffer += char;
        }
    }

    return found;
}

// A var() at a use site, with its fallback: var(--fct-review-x, <fallback>)
function useSites(source) {
    const sites = [];
    const pattern = /var\((--fct-review-[a-z0-9-]+)\s*(?:,\s*)?/g;
    let match;

    while ((match = pattern.exec(source))) {
        let depth = 1;
        let index = match.index + 4;
        while (depth > 0 && index < source.length) {
            index++;
            if (source[index] === '(') depth++;
            if (source[index] === ')') depth--;
        }
        const inner = source.slice(match.index + 4, index);
        const comma = inner.indexOf(',');
        sites.push({token: match[1], fallback: comma > -1 ? inner.slice(comma + 1).trim() : null});
    }

    return sites;
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

const fallbackOf = (token) => PALETTE_TOKENS[token][1];

const ROOT_BLOCK = blockFrom(REVIEWS, ':root {');
const GATE = blockFrom(REVIEWS, 'body:not(.fluent-cart-no-colors) {');
const RULES = REVIEWS.replace(ROOT_BLOCK, '').replace(GATE, '');

describe('reviews declare a --fct-review-* token layer off the palette globals', () => {
    it.each(Object.entries(PALETTE_TOKENS))('%s aliases its global on :root', (token, [global]) => {
        const declared = declarations(ROOT_BLOCK, token);
        const closers = ')'.repeat((global.match(/var\(/g) || []).length + 1);

        expect(declared, `${token} on :root`).toEqual([`var(${global}${closers}`]);
    });

    it.each(Object.entries(PALETTE_TOKENS))('%s re-declares under the gate with today\'s literal', (token, [global, fallback]) => {
        const declared = declarations(GATE, token);
        const closers = ')'.repeat((global.match(/var\(/g) || []).length + 1);

        expect(declared, `${token} under the gate`).toEqual([`var(${global}, ${fallback}${closers}`]);
    });

    it('declares the fixed tokens on :root only', () => {
        for (const [token, value] of Object.entries(FIXED_TOKENS)) {
            expect(declarations(ROOT_BLOCK, token), token).toEqual([value]);
        }
    });
});

describe('review rules only read their tokens', () => {
    const sites = useSites(RULES);

    it('reads every palette token somewhere', () => {
        const used = new Set(sites.map((site) => site.token));

        Object.keys(PALETTE_TOKENS).forEach((token) => expect(used.has(token), `${token} is used`).toBe(true));
    });

    it('reads only declared tokens', () => {
        const known = new Set([...Object.keys(PALETTE_TOKENS), ...Object.keys(FIXED_TOKENS), ...LAYOUT_TOKENS]);

        sites.forEach((site) => expect(known.has(site.token), `${site.token} is declared`).toBe(true));
    });

    it('gives every use a fallback equal to the gated literal (rules sit outside the gate)', () => {
        sites.filter((site) => PALETTE_TOKENS[site.token]).forEach((site) => {
            expect(site.fallback, `${site.token} carries a fallback`).not.toBeNull();
            expect(site.fallback.toLowerCase(), `${site.token} falls back to its gated literal`)
                .toBe(fallbackOf(site.token).toLowerCase());
        });
    });

    it('hard-codes no chrome colour outside the token layer', () => {
        const offenders = walk(RULES)
            .filter(({text}) => !/^--fct-review-/.test(text))
            .map(({path: selector, text}) => {
                // Only what a rule states itself: fallbacks inside var() are the tokens' own.
                const own = text.replace(/var\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/g, '');
                const literal = /#[0-9a-f]{3,8}\b|rgba?\(|\b(?:bg|text|border|from|to)-(?:white|black|gray|system|primary|success|red|warning|blue)\b/i.test(own);

                return literal ? `${selector} { ${text} }` : null;
            })
            .filter(Boolean)
            .filter((line) => !LITERAL_ALLOWED.some((allowed) => allowed.test(line)));

        expect(offenders).toEqual([]);
    });
});

describe('stars stay gold, and the block colour still wins', () => {
    it('uses one star default everywhere', () => {
        expect(REVIEWS.toLowerCase()).not.toContain('#f7a539');
        expect(CARD.toLowerCase()).not.toContain('#f7a539');
        expect(EDITOR.toLowerCase()).not.toContain('#f7a539');
        expect(RENDERER).toContain(`DEFAULT_STAR_COLOR = '${STAR_DEFAULT}'`);
        // The "no override" checks compare against the constant, not a literal.
        expect(RENDERER).not.toMatch(/\$starColor\s*!==\s*'#/);
    });

    it('lets --fct-star-color override the token on every filled star and bar', () => {
        const filled = useSites(RULES).filter((site) => site.token === '--fct-review-star-color');

        expect(filled.length).toBeGreaterThanOrEqual(5);
        expect(RULES).not.toMatch(/var\(--fct-star-color,\s*#/);
        expect(RULES.match(/var\(--fct-star-color, var\(--fct-review-star-color, #f59e0b\)\)/g).length).toBe(filled.length);
    });

    it('never ties the stars to the palette accent', () => {
        expect(declarations(ROOT_BLOCK, '--fct-review-star-color')[0]).not.toContain('var(');
        expect(declarations(GATE, '--fct-review-star-color')).toEqual([]);
    });
});

describe('inputs take the palette surface only while FluentCart prints colours', () => {
    it('paints the form fields and sort select under fluent-cart-custom, never in Default', () => {
        const custom = blockFrom(REVIEWS, 'body.fluent-cart-custom:not(.fluent-cart-no-colors) {');

        expect(custom).toContain('background-color: var(--fct-review-input-bg, #ffffff)');
        expect(custom).toContain('.fct-reviews-sort select');
        expect(custom).toContain('.fct-review-form-field');
        // Nowhere else sets an input background: Default keeps the theme's.
        expect(RULES.replace(custom, '').match(/--fct-review-input-bg/g)).toBeNull();
    });

    it('owns the field text wherever it paints the field (a theme dark mode forces it with !important)', () => {
        const custom = blockFrom(REVIEWS, 'body.fluent-cart-custom:not(.fluent-cart-no-colors) {');

        expect(custom).toContain('color: var(--fct-review-input-text, #2f3448) !important;');
        expect(custom).toMatch(/\.fct-review-step-question \{\s*color: var\(--fct-review-label-color, #2F3448\);/);
        // The !important exists only in palette mode; the base field rule has none.
        expect(RULES.replace(custom, '')).not.toContain('--fct-review-input-text, #2f3448) !important');
    });

    it('paints the summary card with the card surface under fluent-cart-custom only', () => {
        const custom = blockFrom(REVIEWS, 'body.fluent-cart-custom:not(.fluent-cart-no-colors) {');

        expect(custom).toMatch(/\.fct-reviews-summary \{\s*background-color: var\(--fct-card-bg-color, transparent\);/);
    });
});

describe('Default fallbacks read on each other', () => {
    const pairs = [
        ['--fct-review-heading-color', '--fct-review-card-bg', 4.5],
        ['--fct-review-text-color', '--fct-review-card-bg', 4.5],
        ['--fct-review-body-text-color', '--fct-review-card-bg', 4.5],
        ['--fct-review-label-color', '--fct-review-card-bg', 4.5],
        ['--fct-review-secondary-text-color', '--fct-review-card-bg', 4.5],
        ['--fct-review-muted-text-color', '--fct-review-card-bg', 4.5],
        ['--fct-review-meta-text-color', '--fct-review-card-bg', 4.5],
        ['--fct-review-subtle-text-color', '--fct-review-card-bg', 4.5],
        ['--fct-review-note-text-color', '--fct-review-card-bg', 4.5],
        ['--fct-review-muted-text-color', '--fct-review-cta-bar-bg', 4.5],
        ['--fct-review-secondary-text-color', '--fct-review-surface-alt', 4.5],
        ['--fct-review-chip-text', '--fct-review-chip-bg', 4.5],
        ['--fct-review-chip-text', '--fct-review-chip-hover-bg', 4.5],
        ['--fct-review-chip-active-text', '--fct-review-chip-active-bg', 4.5],
        ['--fct-review-select-text', '--fct-review-input-bg', 4.5],
        ['--fct-review-input-text', '--fct-review-input-bg', 4.5],
        ['--fct-review-btn-text', '--fct-review-btn-bg', 4.5],
        ['--fct-review-btn-hover-text', '--fct-review-btn-hover-bg', 4.5],
        ['--fct-review-cta-text', '--fct-review-cta-bg', 4.5],
        ['--fct-review-cta-hover-text', '--fct-review-cta-hover-bg', 4.5],
        ['--fct-review-secondary-btn-text', '--fct-review-secondary-btn-bg', 4.5],
        ['--fct-review-secondary-btn-text', '--fct-review-secondary-btn-hover-bg', 4.5],
        ['--fct-review-vote-text', '--fct-review-vote-hover-bg', 4.5],
        ['--fct-review-subtle-text-color', '--fct-review-tile-bg', 4.5],
        ['--fct-review-strong-text-color', '--fct-review-control-bg', 4.5],
    ];

    it.each(pairs)('%s on %s', (text, surface, minimum) => {
        expect(contrast(fallbackOf(text), fallbackOf(surface))).toBeGreaterThanOrEqual(minimum);
    });
});

describe('product card rating', () => {
    const rating = blockFrom(CARD, '.fct-product-card-rating {');
    const cardGate = blockFrom(CARD, 'body:not(.fluent-cart-no-colors) {');

    it('colours its stars from the star tokens', () => {
        expect(rating).toContain('color: var(--fct-review-star-empty-color, #d1d5db);');
        expect(rating).toContain('color: var(--fct-review-star-color, #f59e0b);');
        expect(rating).not.toMatch(/color:\s*#/);
        expect(declarations(blockFrom(CARD, ':root {'), '--fct-review-star-color')).toEqual([STAR_DEFAULT]);
        expect(declarations(blockFrom(CARD, ':root {'), '--fct-review-star-empty-color')).toEqual(['#d1d5db']);
    });

    it('moves the count colour under the gate, on the palette\'s secondary text', () => {
        expect(rating).not.toContain('text-gray-400');
        expect(declarations(cardGate, '--fct-card-rating-count-color'))
            .toEqual(['var(--fct-secondary-text-color, #ADB5C2)']);
        expect(cardGate).toMatch(/\.fct-product-card-review-count\s*\{\s*color: var\(--fct-card-rating-count-color\);/);
    });
});
