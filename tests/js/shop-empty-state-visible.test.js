import {afterEach, beforeEach, describe, expect, it} from 'vitest';

// A shop with no products rendered its "No Product Found!" message inside the product list.
// The scroll paginator then observed that message as the "last card" and kept loading pages,
// and the first response hid the whole list (message included) via `product-list-empty`.

const NO_RESULT = 'data-fluent-cart-shop-no-result-found';

let observers;

class FakeIntersectionObserver {
    constructor() {
        this.targets = [];
        observers.push(this);
    }

    observe(target) {
        this.targets.push(target);
    }

    disconnect() {
        this.targets = [];
    }
}

const node = (extra = {}) => ({
    getAttribute: () => null,
    removeAttribute: () => {},
    querySelector: () => null,
    querySelectorAll: () => [],
    ...extra,
});

const noResultNode = () => node({
    getAttribute: (name) => (name === NO_RESULT ? '' : null),
    classList: {add: () => {}, remove: () => {}},
});

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('shop empty state stays visible', () => {
    let ScrollPaginator;

    beforeEach(async () => {
        observers = [];
        globalThis.window = {
            fluentCartRestVars: {rest: {url: 'http://shop.test/wp-json/fluent-cart/v2'}},
            location: 'http://shop.test/shop/',
            addEventListener: () => {},
        };
        globalThis.document = {querySelector: () => null, querySelectorAll: () => []};
        globalThis.DOMParser = class {
            parseFromString() {
                return {querySelector: () => null};
            }
        };
        globalThis.IntersectionObserver = FakeIntersectionObserver;
        ScrollPaginator = (await import('../../resources/public/product-page/Paginator/ScrollPaginator.js')).default;
    });

    afterEach(() => {
        delete globalThis.window;
        delete globalThis.document;
        delete globalThis.DOMParser;
        delete globalThis.IntersectionObserver;
    });

    function build(children, {listHolds = true} = {}) {
        const classes = new Set();
        const message = children.find((child) => child.getAttribute(NO_RESULT) !== null);
        const productList = node({
            children,
            classList: {
                toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)),
                add: (name) => classes.add(name),
                remove: (name) => classes.delete(name),
            },
            contains: (el) => listHolds && el === message,
            insertAdjacentHTML: () => {},
        });
        const container = node({
            querySelector: (selector) => {
                if (selector === '[data-fluent-cart-shop-app-product-list]') return productList;
                if (selector === '[data-fluent-cart-shop-no-result-found]') return message || null;
                return null;
            },
        });
        const paginator = new ScrollPaginator(container, {}, {}, 'shop', 4);
        return {paginator, classes, message};
    }

    it('does not observe the empty-state message as a card to page from', async () => {
        build([noResultNode()]);
        await flush();

        expect(observers[0].targets).toEqual([]);
    });

    it('still observes the last card when products are listed', async () => {
        const cards = [node(), node()];
        build(cards);
        await flush();

        expect(observers[0].targets).toEqual([cards[1]]);
    });

    it('keeps the list visible when the empty message is already inside it', () => {
        const {paginator, classes} = build([noResultNode()]);

        paginator.renderNewProducts(`<div ${NO_RESULT}="" class="fluent-cart-shop-no-result-found"></div>`);

        expect(classes.has('product-list-empty')).toBe(false);
    });

    it('still hides the empty list when the message sits outside it', () => {
        const {paginator, classes} = build([noResultNode()], {listHolds: false});

        paginator.renderNewProducts(`<div ${NO_RESULT}="" class="fluent-cart-shop-no-result-found"></div>`);

        expect(classes.has('product-list-empty')).toBe(true);
    });
});
