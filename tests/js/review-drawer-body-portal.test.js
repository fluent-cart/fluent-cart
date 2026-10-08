import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The review drawer escapes its own row/column, not just its own section —
 * and every lookup for something inside the form follows it, or the form
 * stops working the moment the drawer has moved once.
 *
 * Its server HTML prints wherever the review section is placed on the
 * page. A page builder gives most rows/columns position:relative plus a
 * small z-index for its own stacking purposes (Divi: every column gets
 * z-index:2). position:fixed escapes normal document FLOW — inset:0 still
 * spans the real viewport — but it does NOT escape an ancestor's STACKING
 * CONTEXT: the overlay's own z-index:99999 only wins against siblings
 * within whichever column it happens to be nested in. A sibling column
 * later in the page markup (e.g. Related Products below the reviews)
 * shares that same default z-index, ties break on DOM order, and the
 * later column painted over the whole open drawer, 99999 and all.
 *
 * The cart drawer never has this: CartLoader prints it on wp_footer, so
 * it is never nested inside a row/column to begin with. The review
 * drawer is part of the review section's own markup, so it has to get
 * itself out — the same fix ImageGallery.js's lightbox overlay already
 * uses (document.body.appendChild).
 *
 * The first version of this fix only moved the drawer wrapper itself and
 * missed that every form field — rating, title, content, the stepper,
 * the submit button — is a descendant of that same wrapper, not of
 * #container. Once the move happens, #container.querySelector(...) for
 * any of them silently finds nothing: the submit button's own click
 * handler was still bound correctly (#initStepper() ran once, before any
 * move), but #submitReview() itself looked up the rating and content
 * fields through #container and got null back every time, so clicking
 * Submit appeared to do nothing. #root() is the fix: every field lookup
 * goes through it, and #initDrawer() now moves the drawer eagerly at
 * init rather than waiting for the first open, so #root() never has to
 * reason about whether the move has happened yet.
 */

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const JS = fs.readFileSync(path.join(root, 'resources/public/single-product/ReviewForm.js'), 'utf8');

/**
 * A fixed-size window of lines starting at a method's own signature line —
 * simpler and more robust than parsing method boundaries, and every one of
 * these methods is well under the window given.
 */
function methodBody(source, signature, lines = 20) {
    const idx = source.indexOf(signature);
    expect(idx, `${signature} not found`).toBeGreaterThan(-1);
    const startLine = source.slice(0, idx).split('\n').length - 1;
    return source.split('\n').slice(startLine, startLine + lines).join('\n');
}

describe('the review drawer portal', () => {
    it('moves the drawer to a direct child of body, once, idempotently', () => {
        const body = methodBody(JS, '#getDrawer() {');
        expect(body).toMatch(/document\.body\.appendChild\(drawer\)/);
        // A drawer already moved (and still connected) is returned as-is,
        // not re-appended on every call.
        expect(body).toMatch(/this\.#drawerEl\s*&&\s*this\.#drawerEl\.isConnected/);
    });

    it('moves the drawer at init, not on first open', () => {
        // Eagerly, so every lookup made before the first open (the
        // stepper, the star selector, the char counters, all wired
        // during init()) can rely on the move having already happened —
        // #root() does not have to special-case "not moved yet".
        expect(methodBody(JS, '#initDrawer() {')).toMatch(/this\.#getDrawer\(\)/);
    });

    it('is looked up by container position in exactly one place: inside the getter itself', () => {
        // Every other read must go through #getDrawer() (directly, or via
        // #root()), or it stops finding the drawer the moment it has
        // moved — which is exactly the bug: #initDrawer() used to make
        // this same raw query, succeeding at init only because the move
        // had not happened yet, which stopped being true once #initDrawer
        // started moving the drawer itself.
        const rawLookups = [...JS.matchAll(/this\.#container\.querySelector\('\[data-review-drawer\]'\)/g)];
        expect(rawLookups.length).toBe(1);
    });

    it('open, close and the edit-mode title all resolve through the portal-aware getter', () => {
        expect(methodBody(JS, '#openDrawer() {')).toMatch(/this\.#getDrawer\(\)/);
        expect(methodBody(JS, '#closeDrawer({ replaced = false } = {}) {')).toMatch(/this\.#getDrawer\(\)/);
        const titleLookups = [...JS.matchAll(/this\.#getDrawer\(\)\?\.querySelector\('\[data-review-drawer-title\]'\)/g)];
        expect(titleLookups.length).toBe(2);
    });

    it('removes the moved node on destroy, so a torn-down controller leaves nothing behind in body', () => {
        const body = methodBody(JS, 'destroy() {', 36);
        expect(body).toMatch(/this\.#getDrawer\(\)/);
        expect(body).toMatch(/drawer\.parentNode\.removeChild\(drawer\)/);
        expect(body).toMatch(/this\.#drawerEl\s*=\s*null/);
    });
});

describe('#root() — where the form\'s fields actually live', () => {
    it('falls back to #container only for the standalone embed, which never moves', () => {
        const body = methodBody(JS, '#root() {', 10);
        expect(body).toMatch(/if\s*\(\s*!this\.#hasOverlay\s*\)\s*\{\s*return this\.#container;/);
        expect(body).toMatch(/return this\.#getDrawer\(\)\s*\|\|\s*this\.#container;/);
    });

    it('every form-field lookup goes through it, not #container directly', () => {
        // The one real exception is #getDrawer() finding the drawer for
        // itself before the first move — the test above already counts
        // that one. The second match here is this file's own comment
        // mentioning the broken call by name, not code.
        const rawFieldLookups = [...JS.matchAll(/this\.#container\.querySelector/g)];
        expect(rawFieldLookups.length).toBe(2);

        const rootLookups = [...JS.matchAll(/this\.#root\(\)\.querySelector/g)];
        // Rating, title, content, stepper nav/steps, char counters, the
        // submit button, the message area, and the standalone footer/form
        // — this file reads all of them in more than one place each.
        expect(rootLookups.length).toBeGreaterThanOrEqual(25);
    });

    it('the submit flow specifically reads the form and message through #root(), not #container', () => {
        // The rating and every other field travel in through FormData(form)
        // rather than a separate query, so the form element itself — and
        // the message area the validation errors print into — are the
        // two lookups that actually matter here. Before #root() existed,
        // both were this.#container.querySelector(...), and #container
        // had nothing left in it once the drawer had moved: Submit's
        // click handler fired (bound correctly, at init), found the form
        // via #container, got null, and returned — no request, no error,
        // nothing visible at all.
        const body = methodBody(JS, '#submitReview() {', 40);
        expect(body).not.toMatch(/this\.#container\.querySelector/);
        expect(body).toMatch(/this\.#root\(\)\.querySelector\('\[data-review-form\]'\)/);
        expect(body).toMatch(/this\.#root\(\)\.querySelector\('\[data-review-form-message\]'\)/);
    });
});
