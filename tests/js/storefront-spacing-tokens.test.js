import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import spacingScale from '../../resources/styles/tailwind/extends/spacing.js';
import radiusScale from '../../resources/styles/tailwind/extends/borderRadius.js';

/**
 * Storefront radius, padding and spacing read `--fct-*` tokens, the same way
 * colours read the palette globals: `border-radius: var(--fct-btn-radius, 6px)`.
 *
 * Nothing prints these tokens yet. The Default look holds only because every
 * use carries the value that surface already had, and no stylesheet declares a
 * global token itself — a declaration would restyle every surface that reads it.
 *
 * Every token takes one length. Longhand properties (`padding-top`,
 * `border-top-left-radius`) read them too, and a longhand discards a two-value
 * shorthand like `24px 20px` — the whole declaration would be dropped.
 *
 * Source guard, not a render: the suite has no CSS engine for Tailwind @apply.
 * Spec: dev/wp-browser/tests/Cases/storefront-spacing.md
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/public');

// Vendored stylesheets keep their own values.
const VENDORED = ['lib/', 'single-product/xzoom/', 'single-product/lightbox/'];

const TOKENS = [
    '--fct-btn-radius',
    '--fct-input-radius',
    '--fct-card-radius',
    '--fct-container-radius',
    '--fct-popup-radius',
    '--fct-badge-radius',
    '--fct-variant-radius',
    '--fct-btn-padding-y',
    '--fct-btn-padding-x',
    '--fct-input-padding-y',
    '--fct-input-padding-x',
    '--fct-card-padding',
    '--fct-container-padding-y',
    '--fct-container-padding-x',
    '--fct-section-spacing',
    '--fct-field-spacing',
    '--fct-grid-gap',
];

const TOKEN_USE = /var\(\s*(--fct-(?:btn|input|card|container|popup|badge|variant)-(?:radius|padding-[xy]|padding)|--fct-(?:section-spacing|field-spacing|grid-gap))\s*([,)])/g;

function stylesheets(dir = ROOT) {
    return fs.readdirSync(dir, {withFileTypes: true}).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            return stylesheets(full);
        }
        const relative = path.relative(ROOT, full);
        const isStyle = /\.(scss|css)$/.test(entry.name);
        return isStyle && !VENDORED.some((prefix) => relative.startsWith(prefix)) ? [relative] : [];
    });
}

// Comment lines are dropped: a commented-out rule is not a rule.
function source(file) {
    return fs.readFileSync(path.join(ROOT, file), 'utf8')
        .split('\n')
        .filter((line) => !line.trim().startsWith('//'))
        .join('\n');
}

const FILES = stylesheets();
const SOURCES = Object.fromEntries(FILES.map((file) => [file, source(file)]));

// theme('spacing.3') only means 12px where the Tailwind config loads the shared
// scales; elsewhere it is Tailwind's default scale, so a literal stays literal.
// The portal's partials compile under their entry file's config.
function usesSharedScales(file) {
    if (/^customer-profile\/style\/(scss|element-plus)\//.test(file)) {
        return true;
    }
    const config = SOURCES[file].match(/@config\s+["']([^"']+)["']/);
    if (!config) {
        return false;
    }
    const configSource = fs.readFileSync(path.join(ROOT, path.dirname(file), config[1]), 'utf8');
    return configSource.includes('extends/spacing') && configSource.includes('extends/borderRadius');
}

// Every `property: value` whose value reads a token; custom properties are aliases, not consumers.
function declarationsReadingTokens(css) {
    return [...css.matchAll(/(?:^|[;{\s])([a-z-]+)\s*:\s*([^;{}]+);/g)]
        .map((match) => ({property: match[1], value: match[2].trim()}))
        .filter(({value}) => new RegExp(TOKEN_USE.source).test(value));
}

// Replace each token use (fallback and all) with the value a store would set.
function withTokensSetTo(value, length) {
    const start = new RegExp(TOKEN_USE.source.replace(/\\s\*\(\[,\)\]\)$/, ''));
    let result = value;
    let match;
    while ((match = start.exec(result))) {
        let end = match.index + 4;
        for (let depth = 1; end < result.length && depth; end++) {
            if (result[end] === '(') depth++;
            if (result[end] === ')') depth--;
        }
        result = result.slice(0, match.index) + length + result.slice(end);
    }
    return result;
}

// Top-level space-separated parts, ignoring `!important` and spaces inside calc()/var().
function partsOf(value) {
    const parts = [];
    let depth = 0;
    let current = '';
    for (const char of value.replace(/\s*!important$/, '').trim()) {
        if (char === '(') depth++;
        if (char === ')') depth--;
        if (char === ' ' && depth === 0) {
            if (current) parts.push(current);
            current = '';
            continue;
        }
        current += char;
    }
    if (current) parts.push(current);
    return parts;
}

const MAX_PARTS = {padding: 4, margin: 4, 'border-radius': 4, gap: 2};

function acceptsParts(property, parts) {
    return parts.length >= 1 && parts.length <= (MAX_PARTS[property] || 1);
}

function uses() {
    return FILES.flatMap((file) => [...SOURCES[file].matchAll(TOKEN_USE)].map((match) => ({
        file,
        token: match[1],
        hasFallback: match[2] === ',',
    })));
}

describe('storefront spacing tokens', () => {
    // case 1
    it('every token use carries the value the surface already had', () => {
        const bare = uses().filter((use) => !use.hasFallback).map((use) => `${use.file}: ${use.token}`);

        expect(bare).toEqual([]);
    });

    // case 2
    it('no stylesheet declares a global token', () => {
        const declared = FILES.flatMap((file) => TOKENS
            .filter((token) => new RegExp(`${token}\\s*:`).test(SOURCES[file]))
            .map((token) => `${file}: ${token}`));

        expect(declared).toEqual([]);
    });

    // case 3
    it('every token is read by at least one storefront surface', () => {
        const read = new Set(uses().map((use) => use.token));

        expect(TOKENS.filter((token) => !read.has(token))).toEqual([]);
    });

    // case 5
    it('takes a fallback on the shared scale from the scale, not a copied literal', () => {
        const onScale = {
            spacing: new Set(Object.values(spacingScale)),
            radius: new Set(Object.values(radiusScale)),
        };
        const literal = /var\(\s*(--fct-[a-z-]+)\s*,\s*([^()]+?)\s*\)/g;

        const copied = FILES.filter(usesSharedScales).flatMap((file) => [...SOURCES[file].matchAll(literal)]
            .filter((match) => TOKENS.includes(match[1]))
            .filter((match) => {
                const scale = match[1].endsWith('-radius') ? onScale.radius : onScale.spacing;
                return match[2].replace(/\s*!important$/, '').split(/\s+/).every((part) => scale.has(part));
            })
            .map((match) => `${file}: ${match[0]}`));

        expect(copied).toEqual([]);
    });

    // case 6
    it('stays a valid declaration when a token is set to one length', () => {
        const invalid = FILES.flatMap((file) => declarationsReadingTokens(SOURCES[file])
            .filter(({property, value}) => !acceptsParts(property, partsOf(withTokensSetTo(value, '13px'))))
            .map(({property, value}) => `${file}: ${property}: ${value}`));

        expect(invalid).toEqual([]);
    });

    // case 4
    it('reads no spacing token outside the known set', () => {
        const declaredAnywhere = new Set(FILES.flatMap((file) => [...SOURCES[file].matchAll(/(--fct-[a-z0-9-]+)\s*:/g)].map((match) => match[1])));
        const spacingShaped = /^--fct-[a-z0-9-]*(?:radius|padding|spacing|gap)[a-z0-9-]*$/;

        const unknown = FILES.flatMap((file) => [...SOURCES[file].matchAll(/var\(\s*(--fct-[a-z0-9-]+)/g)]
            .map((match) => match[1])
            .filter((name) => spacingShaped.test(name) && !TOKENS.includes(name) && !declaredAnywhere.has(name))
            .map((name) => `${file}: ${name}`));

        expect(unknown).toEqual([]);
    });
});
