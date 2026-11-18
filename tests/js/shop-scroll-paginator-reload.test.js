import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

// Scroll paging stopped after one extra page: the new last card reported while a load was
// finishing, that report was dropped, and threshold 1.0 never counted a cut-off card.

let observers;

class FakeIntersectionObserver {
    constructor(callback, options) {
        this.callback = callback;
        this.options = options;
        this.targets = [];
        this.paused = false; // a hidden tab: the browser sends no reports
        observers.push(this);
    }

    observe(target) {
        this.targets.push(target);
        // Like a real observer: one report of the current state, none after disconnect().
        queueMicrotask(() => {
            if (!this.paused && this.targets.includes(target)) {
                this.callback([{target, isIntersecting: target.visible}]);
            }
        });
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

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('ScrollPaginator loads the next page', () => {
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
        vi.useRealTimers();
        delete globalThis.window;
        delete globalThis.document;
        delete globalThis.DOMParser;
        delete globalThis.IntersectionObserver;
    });

    // The first load starts on page load, as the last server-rendered card is in view.
    async function start() {
        const cards = [node(), node({visible: true})];
        const productList = node({children: cards});
        const container = node({
            querySelector: (selector) => (selector === '[data-fluent-cart-shop-app-product-list]' ? productList : null),
            querySelectorAll: (selector) => (selector === '[data-fluent-cart-shop-app-single-product]' ? cards : []),
        });
        const paginator = new ScrollPaginator(container, {}, {}, 'shop', 4);
        paginator.loadData = vi.fn(() => {
            paginator.loading = true;
            paginator.onLoadingData();
        });
        paginator.renderNewProducts = () => cards.push(node({visible: true}));
        await flush();

        return {paginator, cards};
    }

    // A response with cards: they render, then the request finishes 500ms later.
    async function finishLoad(paginator, {withCards = true} = {}) {
        if (withCards) {
            paginator.onDataLoaded({products: {views: '<div></div>'}}, '<div></div>');
        } else {
            paginator.onDataLoadError(new Error('offline'));
        }
        await flush();
        vi.useFakeTimers();
        paginator.onApiCallFinished();
        vi.advanceTimersByTime(500);
        vi.useRealTimers();
        await flush();
    }

    it('counts a last card that is only partly on screen', async () => {
        await start();

        expect(observers[0].options.threshold).toContain(0);
    });

    it('loads on when the new last card came into view during the load', async () => {
        const {paginator} = await start();
        expect(paginator.loadData).toHaveBeenCalledTimes(1);

        await finishLoad(paginator);

        expect(paginator.loadData).toHaveBeenCalledTimes(2);
    });

    // Guards for the change above: each fails if the re-check loads without a real report.
    it('does not load again when the load added no card', async () => {
        const {paginator} = await start();

        await finishLoad(paginator, {withCards: false});

        expect(paginator.loadData).toHaveBeenCalledTimes(1);
    });

    it('does not load in a hidden tab, where the browser sends no reports', async () => {
        const {paginator} = await start();
        observers[0].paused = true;

        await finishLoad(paginator);

        expect(paginator.loadData).toHaveBeenCalledTimes(1);
    });

    it('does not load while another request, such as a filter, is still running', async () => {
        const {paginator} = await start();
        paginator.onLoadingData();

        await finishLoad(paginator);

        expect(paginator.loadData).toHaveBeenCalledTimes(1);
    });
});
