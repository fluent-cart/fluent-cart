import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The product placeholder illustration replaces the generic image icon
 * everywhere a product has no image, and product images wear no backdrop of
 * their own now that the storefront palette paints the page.
 *
 * Source guards, not renders. The PHP side is covered by
 * dev/wp-browser/tests/Integration/Services/Renderer/ProductPlaceholderEverywhereTest.php.
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');
const stripComments = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

function walk(dir, out = []) {
    for (const entry of fs.readdirSync(path.join(ROOT, dir), {withFileTypes: true})) {
        const rel = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            walk(rel, out);
        } else if (/\.(php|js|jsx|vue)$/.test(entry.name)) {
            out.push(rel);
        }
    }
    return out;
}

/*
 * The only places the generic icon may remain, each on purpose:
 * - the FluentPlayer video poster: a stand-in for a video frame, not a product photo;
 * - the review-list block editor: its placeholder_image previews a customer's review photo;
 * - the admin media picker: its fallback is for a broken attachment URL.
 */
const ALLOWED = [
    'app/Modules/FluentPlayer/ProductVideoRenderer.php',
    'app/Hooks/Handlers/BlockEditors/ProductReviewList/ProductReviewListBlockEditor.php',
    'resources/admin/Bits/Components/Attachment/BulkMediaPicker.vue',
];

describe('product placeholder illustration', () => {
    it('no product surface still references the generic placeholder icon', () => {
        const offenders = [...walk('app'), ...walk('resources/admin'), ...walk('resources/public')]
            .filter((file) => !ALLOWED.includes(file))
            .filter((file) => /images\/placeholder(-small)?\.svg/.test(stripComments(read(file))));

        expect(offenders).toEqual([]);
    });

    it('the admin resolves the illustration through one helper', () => {
        expect(read('resources/admin/utils/support/Asset.js')).toMatch(/productPlaceholder\s*=.*images\/product-placeholder\.webp/s);
    });
});

describe('product images wear no background', () => {
    it('the order review page item image has no backdrop', () => {
        const scss = stripComments(read('resources/public/order-review/order-review.scss'));
        const block = scss.slice(scss.indexOf('.fct-order-review-item-media {'));
        const img = block.slice(block.indexOf('img {'), block.indexOf('}', block.indexOf('img {')));

        expect(img).not.toMatch(/background/);
    });

    it('the image-only variation swatch thumbnail has no backdrop of its own', () => {
        const scss = stripComments(read('resources/public/single-product/advanced-variations.scss'));
        const start = scss.indexOf('.fct-swatch-thumb {', scss.indexOf('.fct-swatch-image-only'));
        const thumb = scss.slice(start, scss.indexOf('}', start));

        expect(start).toBeGreaterThan(-1);
        expect(thumb).not.toMatch(/\bbg-|background/);
    });
});

/*
 * The admin's own product thumbnails used a separate grey icon, empty-image.svg
 * (plus an empty-image-dark.svg twin swapped in by dark mode). Every one of those
 * uses meant "this product or variant has no image", so they show the
 * illustration too. Image.emptyImage() stays defined for back-compat only.
 */
describe('admin product thumbnails use the illustration', () => {
    const EMPTY_IMAGE_ALLOWED = ['resources/admin/utils/support/Image.js'];

    it('no admin surface still references the empty-image icon', () => {
        const offenders = [...walk('resources/admin'), ...walk('resources/licensing')]
            .filter((file) => !EMPTY_IMAGE_ALLOWED.includes(file))
            .filter((file) => /images\/empty-image(-dark)?\.svg|Image\.emptyImage\(/.test(stripComments(read(file))));

        expect(offenders).toEqual([]);
    });

    it.each([
        'resources/admin/Bits/Components/Card/ProductInfo.vue',
        'resources/admin/Modules/Reviews/SingleReview.vue',
    ])('%s draws one thumbnail, with no dark-mode twin', (file) => {
        const src = read(file);

        expect(src).toMatch(/Asset\.productPlaceholder\(\)/);
        expect(src).not.toMatch(/hidden dark:block/);
    });
});
