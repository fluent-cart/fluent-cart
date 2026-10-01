import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The review list's loader moves nothing.
 *
 * It used to be an in-flow "Loading reviews..." line above the rows, shown
 * with an inline display toggle, so every sort, filter and page change
 * pushed the list and the pager down by its height and snapped them back.
 * It is now a zero-height anchor whose spinner floats over the rows, the
 * rows dimmed and marked busy until the new ones land.
 *
 * Source guards, not renders; the measurement lives in the PR.
 */

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const PHP = read('app/Services/Renderer/ProductReviewRenderer.php');
const JS = read('resources/public/single-product/Reviews.js');
const SCSS = read('resources/public/single-product/reviews.scss');

describe('the review list loader', () => {
    it('is not hidden inline by the renderer, so the stylesheet owns its state', () => {
        expect(PHP).toMatch(/data-reviews-loading role="status"/);
        expect(PHP).not.toMatch(/data-reviews-loading[^>]*style=/);
    });

    it('is toggled by class from the script, never by inline display', () => {
        expect(JS).toMatch(/loadingEl\.classList\.toggle\('is-visible', isLoading\)/);
        expect(JS).not.toMatch(/loadingEl\.style\.display/);
    });

    it('marks the list busy while loading', () => {
        expect(JS).toMatch(/listEl\.setAttribute\('aria-busy', 'true'\)/);
        expect(JS).toMatch(/listEl\.classList\.toggle\('is-loading', isLoading\)/);
    });

    it('takes no room of its own', () => {
        const rule = SCSS.slice(SCSS.indexOf('.fct-reviews-loading {'), SCSS.indexOf('[data-reviews-list].is-loading'));
        expect(rule).toMatch(/height:\s*0;/);
        expect(rule).toMatch(/display:\s*none;/);
        expect(rule).toMatch(/\.fct-loader-spinner\s*\{[^}]*position:\s*absolute/);
    });

    it('centres the spinner on the rows, wherever they are relative to the loader', () => {
        // Composed blocks put the count, chips and sort between the loader
        // and the rows, so the rows' offset is measured, not assumed.
        // Shown before it is measured: a display:none anchor has no box.
        expect(JS.indexOf("loadingEl.classList.toggle('is-visible', isLoading)")).toBeLessThan(JS.indexOf('--fct-reviews-rows-top'));
        expect(JS).toMatch(/--fct-reviews-rows-top/);
        expect(JS).toMatch(/--fct-reviews-rows-center-x/);
        expect(JS).toMatch(/--fct-reviews-rows-height/);
        expect(SCSS).toMatch(/top:\s*calc\(var\(--fct-reviews-rows-top, 0px\)/);
        expect(SCSS).toMatch(/left:\s*var\(--fct-reviews-rows-center-x, 50%\)/);
    });

    it('dims the rows rather than removing them', () => {
        expect(SCSS).toMatch(/\[data-reviews-list\]\.is-loading\s*\{[^}]*opacity/);
    });
});
