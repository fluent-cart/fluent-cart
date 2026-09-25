import fs from 'fs';
import os from 'os';
import path from 'path';
import vm from 'vm';
import {afterEach, beforeEach, describe, expect, it} from 'vitest';

import {minifyStaticLibDir} from '../../build/vite/plugins/minifyStaticLibPlugin.mjs';

/**
 * resources/public/lib is copied verbatim into assets/public/lib by
 * vite-plugin-static-copy, so nothing in the build minified it — the
 * shipped toastify JS and CSS were readable source, the CSS despite its
 * ".min" name. The build now minifies the copied files in place; names are
 * unchanged because PHP enqueues them by path.
 */

const LIB_SRC = path.resolve(__dirname, '../../resources/public/lib');

let dir;

beforeEach(() => {
    dir = fs.mkdtempSync(path.join(os.tmpdir(), 'fct-static-lib-'));
});

afterEach(() => {
    fs.rmSync(dir, {recursive: true, force: true});
});

function copyLib(relative) {
    const dest = path.join(dir, relative);
    fs.mkdirSync(path.dirname(dest), {recursive: true});
    fs.copyFileSync(path.join(LIB_SRC, relative), dest);
    return dest;
}

describe('minifyStaticLibDir', () => {
    it('minifies the toastify JS and keeps it working as a classic script', async () => {
        const file = copyLib('toastify/toastify-js-1.12.0.js');
        const before = fs.statSync(file).size;

        await minifyStaticLibDir(dir);

        const code = fs.readFileSync(file, 'utf8');
        expect(code.length).toBeLessThan(before * 0.6);
        expect(code).toContain('@license MIT');

        const sandbox = {};
        vm.runInNewContext(code, sandbox);
        expect(typeof sandbox.Toastify).toBe('function');
    });

    it('minifies the toastify CSS despite the ".min" in its name', async () => {
        const file = copyLib('toastify/toastify.min-1.12.0.css');
        const before = fs.statSync(file).size;

        await minifyStaticLibDir(dir);

        const css = fs.readFileSync(file, 'utf8');
        expect(css.length).toBeLessThan(before);
        expect(css).toContain('@license MIT');
        // Only the preserved licence banner may span lines.
        expect(css.replace(/\/\*[\s\S]*?\*\//g, '').trim()).not.toContain('\n');
        expect(css).toContain('.toastify.has-toastify-icon');
    });

    it('leaves *.min.js / *.min.css vendor builds byte-identical', async () => {
        const files = [
            copyLib('swiper/swiper-bundle.min.js'),
            copyLib('swiper/swiper-bundle.min.css'),
            copyLib('nouislider/nouislider-15.7.1.min.js'),
            copyLib('printThis-2.0.0.min.js'),
        ];
        const before = files.map((file) => fs.readFileSync(file));

        await minifyStaticLibDir(dir);

        files.forEach((file, index) => {
            expect(fs.readFileSync(file).equals(before[index])).toBe(true);
        });
    });

    it('never rewrites a file with a larger result', async () => {
        const file = copyLib('nouislider/nouislider-15.7.1.css');
        const before = fs.statSync(file).size;

        await minifyStaticLibDir(dir);

        expect(fs.statSync(file).size).toBeLessThanOrEqual(before);
    });

    it('is a no-op when the directory does not exist', async () => {
        await expect(minifyStaticLibDir(path.join(dir, 'missing'))).resolves.toBeUndefined();
    });
});
