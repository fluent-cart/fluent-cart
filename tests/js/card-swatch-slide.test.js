import {beforeAll, describe, expect, it} from 'vitest';

let slideSelection;
let observed;

// Stand-ins for a swatch row: layout boxes at fixed places, as the browser lays them out. The row is the
// swatches' offsetParent; a transform such as the press squeeze would change only getBoundingClientRect.
const element = (extra = {}) => ({
    style: {},
    attributes: {},
    getBoundingClientRect: () => ({}),
    setAttribute(name, value) { this.attributes[name] = value; },
    ...extra,
});
let currentRow;
const swatch = (offsetLeft, offsetTop, offsetWidth, offsetHeight) => element({offsetLeft, offsetTop, offsetWidth, offsetHeight, offsetParent: currentRow});
const row = () => {
    const classes = new Set();
    const children = [];
    return currentRow = element({
        children,
        classList: {add: (name) => classes.add(name), remove: (name) => classes.delete(name), contains: (name) => classes.has(name)},
        prepend: (child) => children.unshift(child),
        querySelector: () => null,
        querySelectorAll: () => currentRow.swatches || [],
    });
};
const look = {backgroundColor: 'rgb(37, 50, 65)', boxShadow: 'none', borderRadius: '6px'};

beforeAll(async () => {
    globalThis.document = {createElement: () => element()};
    globalThis.ResizeObserver = class {
        constructor(callback) { this.callback = callback; }
        observe(target) { observed.push([target, this.callback]); }
    };
    ({slideSelection} = await import('../../resources/public/product-card/product-attribute-swatches.js'));
});

describe('The chosen swatch look slides to the new pick', () => {
    it('starts on the old swatch with its look and moves to the new one', () => {
        observed = [];
        const swatches = row();
        const from = swatch(4, 4, 40, 26);
        const to = swatch(50, 4, 60, 26);
        swatches.swatches = [from, to];

        slideSelection(swatches, from, to, look);

        const [indicator] = swatches.children;
        expect(indicator.className).toBe('fct-card-swatch-indicator');
        expect(indicator.attributes['aria-hidden']).toBe('true');
        expect(indicator.style).toMatchObject(look);
        expect(swatches.classList.contains('has-swatch-indicator')).toBe(true);
        expect(swatches.classList.contains('is-swatch-sliding')).toBe(true);
        // Measured from the row: the new swatch is 50px in and 4px down.
        expect(indicator.style.transform).toBe('translate(50px, 4px)');
        expect(indicator.style.width).toBe('60px');
        // The row and each swatch: a swatch can change size while the row keeps its own (a late web font).
        expect(observed.map(([target]) => target)).toEqual([swatches, from, to]);

        // The next pick reuses the highlight, and the old pick takes its resting look at once again,
        // as on the first pick: its transition is off while a box read commits it.
        const transitions = [];
        to.getBoundingClientRect = () => { transitions.push(to.style.transition); return {}; };
        slideSelection(swatches, to, from);
        expect(swatches.children).toHaveLength(1);
        expect(indicator.style.transform).toBe('translate(4px, 4px)');
        expect(transitions).toContain('none');
        expect(to.style.transition).toBe('');
    });

    it('leaves a row alone until its pick changes, and hides behind a hidden swatch', () => {
        observed = [];
        const swatches = row();
        const only = swatch(4, 4, 40, 26);
        slideSelection(swatches, only, only, look);
        slideSelection(swatches, null, only, look);
        expect(swatches.children).toHaveLength(0);

        // A pick behind "+N" (not drawn) hides the highlight instead of placing it on nothing.
        slideSelection(swatches, only, swatch(0, 0, 0, 0), look);
        expect(swatches.children[0].style.opacity).toBe('0');
    });

    it('moves without a slide when the row wraps differently, and keeps sliding when only the row resizes', () => {
        observed = [];
        const swatches = row();
        const to = swatch(50, 4, 60, 26);
        swatches.querySelector = () => to;
        slideSelection(swatches, swatch(4, 4, 40, 26), to, look);
        const [, callback] = observed[0];

        // The row grows (a longer name in its heading), the swatch stays: the slide goes on.
        callback();
        expect(swatches.classList.contains('is-swatch-sliding')).toBe(true);
        to.offsetLeft = 4;
        to.offsetTop = 40;
        callback();
        expect(swatches.classList.contains('is-swatch-sliding')).toBe(false);
        expect(swatches.children[0].style.transform).toBe('translate(4px, 40px)');

        // Same place and width, taller (a label that wraps once a web font loads): the highlight follows.
        to.offsetHeight = 40;
        callback();
        expect(swatches.children[0].style.height).toBe('40px');
    });
});
