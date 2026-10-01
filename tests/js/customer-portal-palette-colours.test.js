import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The customer portal follows the storefront palette through its own token
 * layer (`--fct-customer-dashboard-*`), the shape reviews use:
 *
 *   :root                            token → palette global (no fallback)
 *   body:not(.fluent-cart-no-colors) token → palette global, today's literal
 *   rules                            var(--fct-customer-dashboard-*, today's literal)
 *
 * Browser QA under a dark inherited palette found the customer name, the
 * "Your Recent Purchases" heading, "View all" and "Withdraw from contract here"
 * printed in the portal's fixed dark greys on the dark page (1.1–1.2:1), the
 * purchases table a hard-coded white card, and the active menu item a white
 * pill. The portal's variables read no palette global at all.
 *
 * Default must not move: every token falls back to the exact literal its rules
 * had, and Element Plus' own variables fall back to Element Plus' defaults.
 * Status and semantic colours (badges, tags, alerts, errors, logout) stay fixed.
 *
 * Source guard, not a render: the suite has no CSS engine for Tailwind @apply.
 */

const ROOT_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (file) => fs.readFileSync(path.join(ROOT_DIR, file), 'utf8');
const STYLE_DIR = 'resources/public/customer-profile/style';

const GLOBAL = read(`${STYLE_DIR}/customer-profile-global.scss`);
const PROFILE = read(`${STYLE_DIR}/customer-profile.scss`);
const PARTIALS = [
    ...fs.readdirSync(path.join(ROOT_DIR, STYLE_DIR, 'scss')).map((file) => `${STYLE_DIR}/scss/${file}`),
    ...fs.readdirSync(path.join(ROOT_DIR, STYLE_DIR, 'element-plus'))
        .filter((file) => file !== 'base.scss')
        .map((file) => `${STYLE_DIR}/element-plus/${file}`),
];
const EL_BASE = read('node_modules/element-plus/theme-chalk/base.css');
const HANDLER = read('app/Hooks/Handlers/ShortCodes/CustomerProfileHandler.php');
const APP_VIEW = read('app/Views/frontend/customer_app.php');

const P = '--fct-customer-dashboard-';

// token → [palette global the token reads, the literal it falls back to]
const PALETTE_TOKENS = {
    // text
    [`${P}primary-text-color`]: ['--fct-primary-text-color', '#2F3448'],
    [`${P}title-color`]: ['--fct-primary-text-color', '#2F3448'],
    [`${P}secondary-text-color`]: ['--fct-secondary-text-color', '#565865'],
    [`${P}sub-title-color`]: ['--fct-secondary-text-color', '#565865'],
    [`${P}text-color`]: ['--fct-secondary-text-color', '#565865'],
    [`${P}meta-text-color`]: ['--fct-secondary-text-color', '#696778'],
    [`${P}faint-text-color`]: ['--fct-secondary-text-color', '#9D9FAC'],
    [`${P}label-text-color`]: ['--fct-secondary-text-color', '#98A2B3'],
    [`${P}caption-text-color`]: ['--fct-secondary-text-color', '#8E99AA'],
    [`${P}hint-text-color`]: ['--fct-secondary-text-color', '#808B9C'],
    [`${P}note-text-color`]: ['--fct-secondary-text-color', '#758195'],
    [`${P}link-color`]: ['--fct-primary-text-color', '#253241'],
    [`${P}link-hover-color`]: ['--fct-secondary-text-color', '#5A5F66'],
    [`${P}info-link-color`]: ['--fct-primary-text-color', '#0171DB'],
    [`${P}blue-link-color`]: ['--fct-primary-text-color', '#017EF3'],
    [`${P}nav-text-color`]: ['--fct-secondary-text-color', '#565865'],
    [`${P}nav-active-text-color`]: ['--fct-primary-text-color', '#253241'],
    [`${P}paginator-text-color`]: ['--fct-secondary-text-color', '#565865'],

    // surfaces
    [`${P}card-bg`]: ['--fct-card-bg-color', '#ffffff'],
    [`${P}surface-alt`]: ['--fct-secondary-bg-color', '#F9FAFB'],
    [`${P}surface-muted`]: ['--fct-secondary-bg-color', '#F5F6F7'],
    [`${P}surface-neutral`]: ['--fct-secondary-bg-color', '#FAFAFA'],
    [`${P}surface-light`]: ['--fct-secondary-bg-color', '#f5f7fa'],
    [`${P}code-bg`]: ['--fct-secondary-bg-color', '#f1f2f3'],
    [`${P}chip-bg`]: ['--fct-secondary-bg-color', '#EAECF0'],
    [`${P}info-panel-bg`]: ['--fct-secondary-bg-color', '#E6F2FE'],
    [`${P}nav-active-bg-color`]: ['--fct-secondary-bg-color', '#F5F6F7'],
    [`${P}hover-bg`]: ['--fct-secondary-btn-hover-bg-color', '#F5F6F7'],
    [`${P}hover-strong-bg`]: ['--fct-secondary-btn-hover-bg-color', '#EAECF0'],
    [`${P}soft-hover-bg`]: ['--fct-secondary-btn-hover-bg-color', '#F0F0F1'],

    // borders and dividers
    [`${P}border-color`]: ['--fct-border-color', '#D6DAE1'],
    [`${P}divider-color`]: ['--fct-divider-color', '#D6DAE1'],
    [`${P}row-divider-color`]: ['--fct-divider-color', '#EAECF0'],
    [`${P}rule-color`]: ['--fct-border-color', '#C1C7D1'],
    [`${P}scrollbar-thumb`]: ['--fct-border-color', '#e5e7eb'],

    // primary button pair and its hover; the nav bar and checked controls
    [`${P}btn-bg-color`]: ['--fct-btn-bg-color', '#253241'],
    [`${P}btn-border-color`]: ['--fct-btn-bg-color', '#253241'],
    [`${P}btn-text-color`]: ['--fct-btn-text-color', '#ffffff'],
    [`${P}btn-hover-bg-color`]: ['--fct-btn-hover-bg-color, var(--fct-btn-bg-color', '#253241'],
    [`${P}btn-hover-text-color`]: ['--fct-btn-hover-text-color, var(--fct-btn-text-color', '#ffffff'],
    [`${P}nav-active-bar-color`]: ['--fct-btn-bg-color', '#253241'],
    [`${P}check-bg`]: ['--fct-btn-bg-color', '#253241'],
    [`${P}check-icon`]: ['--fct-btn-text-color', '#ffffff'],
    [`${P}focus-border-color`]: ['--fct-active-border-color', '#253241'],

    // secondary (outline) buttons
    [`${P}secondary-btn-bg`]: ['--fct-secondary-btn-bg-color', '#ffffff'],
    [`${P}secondary-btn-text`]: ['--fct-secondary-btn-text-color', '#2F3448'],
    [`${P}secondary-btn-border`]: ['--fct-secondary-btn-border-color', '#D6DAE1'],

    // fields
    [`${P}input-bg`]: ['--fct-input-bg-color', '#ffffff'],
    [`${P}input-text`]: ['--fct-input-text-color', '#2F3448'],
    [`${P}input-border`]: ['--fct-border-color', '#D6DAE1'],
    [`${P}input-placeholder`]: ['--fct-input-placeholder-text-color', '#9D9FAC'],
    [`${P}el-input-text`]: ['--fct-input-text-color', '#606266'],
};

// Palette only: the paginator's current page never had a colour (its variables
// were never declared, so it computed to unset). It wears the button pair while
// a palette prints (04, rule 7) and stays exactly as it was otherwise.
const PALETTE_ONLY_TOKENS = {
    [`${P}paginator-items-active-color`]: '--fct-btn-text-color',
    [`${P}paginator-items-active-bg`]: '--fct-btn-bg-color',
    [`${P}paginator-items-active-border`]: '--fct-btn-bg-color',
};

// Semantic: logout is the portal's one destructive action.
const FIXED_TOKENS = {
    [`${P}logout-btn-bg-color`]: "theme('colors.red.50')",
    [`${P}logout-btn-text-color`]: "theme('colors.red.500')",
    [`${P}logout-btn-hover-bg-color`]: "theme('colors.red.100')",
};

// Element Plus globals → [palette global, Element Plus' own default]
const EL_TOKENS = {
    '--el-bg-color': ['--fct-card-bg-color', '#ffffff'],
    '--el-bg-color-overlay': ['--fct-card-bg-color', '#ffffff'],
    '--el-fill-color-blank': ['--fct-card-bg-color', '#ffffff'],
    '--el-fill-color': ['--fct-secondary-btn-hover-bg-color', '#f0f2f5'],
    '--el-fill-color-light': ['--fct-secondary-bg-color', '#f5f7fa'],
    '--el-fill-color-lighter': ['--fct-secondary-bg-color', '#fafafa'],
    // The skeleton shimmer's far stop (--el-skeleton-to-color). Unbound, a
    // dark palette ran its bars from the palette fill into Element Plus' light
    // grey — a pale streak across the loading portal.
    '--el-fill-color-darker': ['--fct-border-color', '#e6e8eb'],
    '--el-text-color-primary': ['--fct-primary-text-color', '#303133'],
    '--el-text-color-regular': ['--fct-primary-text-color', '#606266'],
    '--el-text-color-secondary': ['--fct-secondary-text-color', '#909399'],
    '--el-text-color-placeholder': ['--fct-input-placeholder-text-color', '#a8abb2'],
    '--el-border-color': ['--fct-border-color', '#dcdfe6'],
    '--el-border-color-light': ['--fct-border-color', '#e4e7ed'],
    '--el-border-color-lighter': ['--fct-divider-color', '#ebeef5'],
    '--el-border-color-extra-light': ['--fct-divider-color', '#f2f6fc'],
};

/*
 * Colours that stay literal on purpose, by the selector that paints them:
 * status and semantic colours, fixed pairs over their own fixed surface,
 * overlays and shadows.
 */
const LITERAL_ALLOWED = [
    /\.fct-customer-logout-btn/,                     // destructive action
    /\.fct-badge/,                                   // status badges
    /\.el-tag/,                                      // status tags
    /\.el-alert/,                                    // alert surfaces + their text
    /validation-error|is-error|\.error-text|\.required|\.expired-message/, // errors
    /\.fct-email-verification.*(\.btn|\.icon)/,      // verify (success) + close (danger hover)
    /\.title » &-status|\.title » &-canceled/,        // subscription status words
    /\.form-error-wrap/,                             // form errors
    /\.is-inclusive|\.is-exclusive/,                 // tax badges
    /\.fct-customer-tax-row--divider|fct-customer-tax-box.*--divider/, // translucent rule
    /\.fct-my-reviews-(status|star)/,                // review status, stars
    /copy-tooltip|\.fct-copy-tooltip|tooltip-text/,  // copied! toast pairs
    /--(info|success|danger|warning)\b|is-danger|item-destructive|el-message-box-icon--/, // coloured variants
    /\.icon-button » &\.hover » &-(danger|info)/,     // danger hover; info hover border
    /\.icon-button.*&\.(success|warning|primary|blue|secondary|danger|dark|disabled)|&\.is-(success|warning|primary|blue|purple|danger|dark|disabled)/,
    /\.el-overlay \{ background/,                     // modal backdrop
    /box-shadow|rgba?\((?:10, 13, 20|14, 18, 27|2, 13, 23)/, // shadows
    /focus-visible.*outline/,                        // fixed focus ring
    /\.dark /,                                       // .dark skeleton mode
    /--thumb-bg: #4b5563|--track-bg: #374151/,       // dark-mode scrollbar pair
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
    const pattern = new RegExp(`(?:^|[\\s;{])${property.replace(/[-]/g, '\\-')}:\\s*([^;]+);`, 'g');

    return [...block.matchAll(pattern)].map((match) => match[1].trim());
}

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

// A var() of a portal token at a use site, with its fallback. Tailwind
// arbitrary values write it without spaces: text-[var(--token,#hex)].
function useSites(source) {
    const sites = [];
    const pattern = /var\((--fct-customer-dashboard-[a-z0-9-]+)\s*(?:,\s*)?/g;
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

const closers = (global) => ')'.repeat((global.match(/var\(/g) || []).length + 1);
const fallbackOf = (token) => PALETTE_TOKENS[token][1];

const ROOT_BLOCK = blockFrom(GLOBAL, ':root {');
const GATE = blockFrom(GLOBAL, 'body:not(.fluent-cart-no-colors) {');
const EL_GATE = blockFrom(PROFILE, 'body:not(.fluent-cart-no-colors) {');
const CUSTOM = blockFrom(PROFILE, 'body.fluent-cart-custom:not(.fluent-cart-no-colors) {');

const RULE_SOURCES = {
    [`${STYLE_DIR}/customer-profile-global.scss`]: GLOBAL.replace(ROOT_BLOCK, '').replace(GATE, ''),
    [`${STYLE_DIR}/customer-profile.scss`]: PROFILE.replace(EL_GATE, ''),
    ...Object.fromEntries(PARTIALS.map((file) => [file, read(file)])),
};
const ALL_RULES = Object.values(RULE_SOURCES).join('\n');

const VUE_DIR = path.join(ROOT_DIR, 'resources/public/customer-profile/Vue');
const VUE_FILES = fs.readdirSync(VUE_DIR, {recursive: true})
    .filter((file) => file.endsWith('.vue'))
    .map((file) => ({file, source: fs.readFileSync(path.join(VUE_DIR, file), 'utf8')}));

describe('the portal declares a --fct-customer-dashboard-* token layer off the palette globals', () => {
    it.each(Object.entries(PALETTE_TOKENS))('%s aliases its global on :root', (token, [global]) => {
        expect(declarations(ROOT_BLOCK, token), `${token} on :root`).toEqual([`var(${global}${closers(global)}`]);
    });

    it.each(Object.entries(PALETTE_TOKENS))('%s re-declares under the gate with today\'s literal', (token, [global, fallback]) => {
        expect(declarations(GATE, token), `${token} under the gate`).toEqual([`var(${global}, ${fallback}${closers(global)}`]);
    });

    it('declares the palette-only tokens on :root and nowhere under the gate', () => {
        for (const [token, global] of Object.entries(PALETTE_ONLY_TOKENS)) {
            expect(declarations(ROOT_BLOCK, token), token).toEqual([`var(${global})`]);
            expect(declarations(GATE, token), token).toEqual([]);
        }
    });

    it('keeps logout on its fixed danger colours', () => {
        for (const [token, value] of Object.entries(FIXED_TOKENS)) {
            expect(declarations(ROOT_BLOCK, token), token).toEqual([value]);
            expect(declarations(GATE, token), token).toEqual([]);
        }
    });

    it('declares no portal token that reads nothing', () => {
        const declared = [...ROOT_BLOCK.matchAll(/(--fct-customer-dashboard-[a-z0-9-]+):/g)].map((match) => match[1]);
        const known = new Set([...Object.keys(PALETTE_TOKENS), ...Object.keys(PALETTE_ONLY_TOKENS), ...Object.keys(FIXED_TOKENS)]);

        declared.forEach((token) => expect(known.has(token), `${token} is in the table`).toBe(true));
    });
});

describe('Element Plus follows the palette inside the portal, and keeps its own defaults otherwise', () => {
    it.each(Object.entries(EL_TOKENS))('%s reads its global and falls back to Element Plus\' default', (token, [global, fallback]) => {
        expect(declarations(EL_GATE, token), `${token} under the gate`).toEqual([`var(${global}, ${fallback})`]);
        expect(EL_BASE.toLowerCase(), `${token} default is ${fallback}`).toContain(`${token}:${fallback}`.toLowerCase());
    });
});

describe('portal rules only read their tokens', () => {
    const sites = useSites(ALL_RULES);

    it('reads every palette token somewhere', () => {
        const templates = useSites(VUE_FILES.map(({source}) => source).join('\n'));
        const used = new Set([...sites, ...templates].map((site) => site.token));

        Object.keys(PALETTE_TOKENS).forEach((token) => expect(used.has(token), `${token} is used`).toBe(true));
    });

    it('reads only declared tokens', () => {
        const known = new Set([...Object.keys(PALETTE_TOKENS), ...Object.keys(PALETTE_ONLY_TOKENS), ...Object.keys(FIXED_TOKENS)]);

        sites.forEach((site) => expect(known.has(site.token), `${site.token} is declared`).toBe(true));
    });

    it('gives every palette-token use a fallback equal to the gated literal', () => {
        sites.filter((site) => PALETTE_TOKENS[site.token]).forEach((site) => {
            expect(site.fallback, `${site.token} carries a fallback`).not.toBeNull();
            expect(site.fallback.toLowerCase(), `${site.token} falls back to its gated literal`)
                .toBe(fallbackOf(site.token).toLowerCase());
        });
    });

    it.each(Object.keys(RULE_SOURCES))('%s hard-codes no chrome colour outside the token layer', (file) => {
        const offenders = walk(RULE_SOURCES[file])
            .filter(({text}) => !/^--(fct-customer-dashboard|el)-/.test(text))
            .map(({path: selector, text}) => {
                const own = text
                    .replace(/var\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/g, '')
                    .replace(/(?:^|\s)dark:\S+/g, ' ')
                    .replace(/url\([^)]*\)/g, '');
                const literal = /#[0-9a-f]{3,8}\b|rgba?\(|(?:^|[\s:])(?:bg|text|border(?:-[xytblr])?|fill|stroke|divide|ring|outline)-(?:white|black|gray|system|primary|success|red|warning|blue|dark|amber|green|neutral|secondary)\b/i.test(own);

                return literal ? `${selector} { ${text} }` : null;
            })
            .filter(Boolean)
            .filter((line) => !LITERAL_ALLOWED.some((allowed) => allowed.test(line)));

        expect(offenders).toEqual([]);
    });

    it('uses no neutral palette-class colour in the portal templates', () => {
        const offenders = VUE_FILES.flatMap(({file, source}) => [...source.matchAll(/class="([^"]*)"/g)]
            .map((match) => match[1])
            .filter((classes) => /(?:^|\s)(?:text|bg|border)-(?:system|gray|primary|white)\b/.test(classes))
            .map((classes) => `${file}: ${classes}`));

        expect(offenders).toEqual([]);
    });

    it('gives every template token use its gated literal', () => {
        VUE_FILES.forEach(({file, source}) => useSites(source).forEach((site) => {
            expect(PALETTE_TOKENS[site.token], `${file}: ${site.token}`).toBeDefined();
            expect((site.fallback || '').toLowerCase(), `${file}: ${site.token}`).toBe(fallbackOf(site.token).toLowerCase());
        }));
    });
});

describe('while colours print, the portal wears the palette surface and owns its field text', () => {
    it('paints the app container with the card surface, transparent without one', () => {
        expect(CUSTOM).toMatch(/\.fluent-cart-customer-profile-app:not\(\.el-dialog\):not\(\.el-popper\):not\(\.el-popover\) \{\s*background-color: var\(--fct-card-bg-color, transparent\);/);
        // Nowhere else paints the container: Default keeps the theme's page.
        expect(ALL_RULES.replace(CUSTOM, '')).not.toContain('--fct-card-bg-color, transparent');
    });

    it('matches field text with !important only here (theme dark modes force it)', () => {
        expect(CUSTOM).toContain(`color: var(${P}el-input-text, #606266) !important;`);
        expect(CUSTOM).toContain(`color: var(${P}input-text, #2F3448) !important;`);
        expect(ALL_RULES.replace(CUSTOM, '')).not.toMatch(/input-text, #[0-9a-f]+\) !important/i);
    });
});

/*
 * Until DOMContentLoaded — which waits for the portal's module script, ~2s
 * under the Vite dev server — the server-rendered skeleton covers the portal.
 * It was painted a hard-coded #ffffff inline, so under a dark palette every
 * reload opened on a white panel that then snapped to the palette card.
 */
describe('the loading skeleton paints the portal card, not a fixed white', () => {
    const loader = (APP_VIEW.match(/<div id="fct-customer-loader"[\s\S]*?>/) || [''])[0];
    const background = (loader.match(/background(?:-color)?:\s*([^;"]+)/) || [])[1];

    it('reads the card token, falling back to the white it always had', () => {
        expect(loader, 'the loader is rendered').not.toBe('');
        expect(background).toBe(`var(${P}card-bg, #ffffff)`);
    });

    it('states no literal colour of its own', () => {
        expect(loader.replace(/var\([^)]*\)/g, '')).not.toMatch(/#[0-9a-f]{3,8}\b|rgba?\(|\bwhite\b/i);
    });

    // It looked frameless beside the mounted portal: no border, radius,
    // column divider or header rule. It now sits inside the app container
    // (whose own border and radius frame it) and draws the rest on the tokens
    // the mounted menu and customer-info header use.
    it('sits inside the app container, which frames it', () => {
        const container = APP_VIEW.indexOf('fct-customer-dashboard-app-container fluent-cart-customer-profile-app');
        const at = APP_VIEW.indexOf('id="fct-customer-loader"');

        expect(container).toBeGreaterThan(-1);
        expect(at).toBeGreaterThan(container);
        expect(at).toBeLessThan(APP_VIEW.indexOf("do_action('fluent_cart/customer_menu')"));
        expect(GLOBAL).toMatch(/\.fct-customer-root-container > \.fct-customer-dashboard-app-container \{\s*position: relative;/);
        expect(blockFrom(GLOBAL, '#fct-customer-loader {')).toMatch(/^#fct-customer-loader \{\s*border-radius: inherit;/);
    });

    it('draws the menu divider and header rule on the mounted portal\'s tokens', () => {
        const rules = blockFrom(GLOBAL, '#fct-customer-loader {');
        const nav = read(`${STYLE_DIR}/scss/nav.scss`);
        const divider = `border-[color:var(${P}border-color,#D6DAE1)]`;
        const headerRule = `border-[color:var(${P}row-divider-color,#EAECF0)]`;

        expect(blockFrom(rules, '.fct-customer-loader-left {')).toContain('w-[272px]');
        expect(blockFrom(rules, '.fct-customer-loader-left {')).toContain(`border-r border-solid ${divider}`);
        expect(blockFrom(nav, '.fct-customer-dashboard-navs-wrap {')).toContain(divider);
        expect(blockFrom(nav, '.fct-customer-dashboard-navs-wrap {')).toContain('w-[272px]');

        expect(blockFrom(rules, '.fct-customer-loader-head {')).toContain(`border-b border-solid ${headerRule}`);
        expect(blockFrom(GLOBAL, '.fct-customer-dashboard-customer-info {')).toContain(headerRule);
        expect(loader).not.toBe('');
        expect(APP_VIEW).toContain('class="fct-customer-loader-head');
    });
});

describe('the logged-out Login link is the portal\'s own button', () => {
    it('carries its own class and loads the portal stylesheet', () => {
        const loggedOut = HANDLER.slice(HANDLER.indexOf('if (!is_user_logged_in())'), HANDLER.indexOf('$this->renderCustomerAppContainer();'));

        expect(loggedOut).toMatch(/class="button fct-customer-login-btn"/);
        expect(loggedOut).toContain("'fluent-cart-customer-profile-global'");
    });

    it('paints the button pair outside the gate, so a theme\'s transparent button text never wins', () => {
        const rule = blockFrom(GLOBAL, '.fct_auth_wrap a.fct-customer-login-btn {');

        expect(rule).toContain(`background-color: var(${P}btn-bg-color, #253241);`);
        expect(rule).toContain(`color: var(${P}btn-text-color, #ffffff);`);
        expect(rule).toContain(`background-color: var(${P}btn-hover-bg-color, #253241);`);
        expect(rule).toContain(`color: var(${P}btn-hover-text-color, #ffffff);`);
        expect(GATE).not.toContain('fct-customer-login-btn');
    });
});

describe('Default fallbacks read on each other', () => {
    const pairs = [
        ['title-color', 'card-bg'],
        ['primary-text-color', 'card-bg'],
        ['text-color', 'card-bg'],
        ['sub-title-color', 'card-bg'],
        ['meta-text-color', 'card-bg'],
        ['nav-text-color', 'card-bg'],
        ['link-color', 'card-bg'],
        ['link-hover-color', 'card-bg'],
        ['info-link-color', 'surface-alt'],
        ['primary-text-color', 'surface-alt'],
        ['text-color', 'surface-muted'],
        ['primary-text-color', 'code-bg'],
        ['primary-text-color', 'chip-bg'],
        ['primary-text-color', 'info-panel-bg'],
        ['nav-active-text-color', 'nav-active-bg-color'],
        ['btn-text-color', 'btn-bg-color'],
        ['btn-hover-text-color', 'btn-hover-bg-color'],
        ['check-icon', 'check-bg'],
        ['secondary-btn-text', 'secondary-btn-bg'],
        ['secondary-btn-text', 'soft-hover-bg'],
        ['secondary-btn-text', 'hover-bg'],
        ['input-text', 'input-bg'],
        ['el-input-text', 'input-bg'],
        ['paginator-text-color', 'card-bg'],
    ];

    it.each(pairs)('%s on %s', (text, surface) => {
        expect(contrast(fallbackOf(P + text), fallbackOf(P + surface))).toBeGreaterThanOrEqual(4.5);
    });
});
