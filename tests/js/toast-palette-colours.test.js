import fs from 'fs';
import os from 'os';
import path from 'path';
import {afterEach, beforeEach, describe, expect, it} from 'vitest';

import {minifyStaticLibDir} from '../../build/vite/plugins/minifyStaticLibPlugin.mjs';

/**
 * Toast notifications follow the storefront palette.
 *
 * The toast (our fork of toastify's stylesheet) painted a white card with a
 * grey border and grey text whatever the store's colours, so on a dark store
 * every "added to cart" or checkout error arrived as a bright white slab.
 * It now reads the palette, with the colour it always showed as the fallback:
 *
 *   toast background → card background
 *   toast border     → border colour
 *   toast text       → primary text
 *
 * Status icons keep their fixed green / red / amber / grey: the palette has no
 * status colours, and an error must stay red on every store.
 *
 * Toasts are FluentCart's own chrome, so — like the carousels and the shop's
 * view switcher — they style themselves and sit outside the no-colors gate.
 */

const LIB_SRC = path.resolve(__dirname, '../../resources/public/lib');
const FILE = 'toastify/toastify.min-1.12.0.css';

function toastBlock(css) {
    // The base rule follows either a closing brace or the licence comment.
    const match = css.match(/(?:^|[}/])\s*\.toastify\s*\{([^}]*)\}/m);

    expect(match, 'the base .toastify rule').not.toBeNull();

    return match[1];
}

describe('toast colours', () => {
    const css = fs.readFileSync(path.join(LIB_SRC, FILE), 'utf8');

    it('reads the palette with the old colours as fallbacks', () => {
        const block = toastBlock(css);

        expect(block).toMatch(/background:\s*var\(--fct-card-bg-color, white\)/);
        expect(block).toMatch(/border:\s*1px solid var\(--fct-border-color, #ebeef5\)/);
        expect(block).toMatch(/color:\s*var\(--fct-primary-text-color, #606266\)/);
    });

    it('keeps the status icons in their fixed colours', () => {
        expect(css).toMatch(/\.success svg path\s*\{\s*fill:\s*#67c23a/);
        expect(css).toMatch(/\.error svg path\s*\{\s*fill:\s*#f56c6c/);
        expect(css).toMatch(/\.warning svg path\s*\{\s*fill:\s*#e6a23c/);
        expect(css).toMatch(/\.info svg path\s*\{\s*fill:\s*#909399/);
    });
});

describe('toast colours after the build minifies the lib', () => {
    let dir;

    beforeEach(() => {
        dir = fs.mkdtempSync(path.join(os.tmpdir(), 'fct-toast-colours-'));
    });

    afterEach(() => {
        fs.rmSync(dir, {recursive: true, force: true});
    });

    it('ships the palette references intact', async () => {
        const dest = path.join(dir, FILE);
        fs.mkdirSync(path.dirname(dest), {recursive: true});
        fs.copyFileSync(path.join(LIB_SRC, FILE), dest);

        await minifyStaticLibDir(dir);

        const block = toastBlock(fs.readFileSync(dest, 'utf8'));

        expect(block).toContain('var(--fct-card-bg-color,');
        expect(block).toContain('var(--fct-border-color,');
        expect(block).toContain('var(--fct-primary-text-color,');
    });
});
