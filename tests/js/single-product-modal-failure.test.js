import {beforeEach, describe, expect, it, vi} from 'vitest';

/**
 * The product modal endpoint answers 422 for a product that is no longer
 * visible (draft/private). fluentCartAjax rejects non-2xx, and the modal
 * used to leave its loading flag set and the spinner overlay on the page,
 * so no other product modal could open until a reload.
 */

function fakeElement() {
    const el = {
        className: '',
        style: {},
        innerHTML: '',
        children: [],
        classList: {add: vi.fn(), remove: vi.fn()},
        setAttribute: vi.fn(),
        appendChild(child) {
            el.children.push(child);
            return child;
        },
        remove: vi.fn(),
        querySelectorAll: () => [],
    };
    return el;
}

let responses;
let created;

beforeEach(() => {
    vi.resetModules();
    created = [];
    responses = [];
    globalThis.window = {
        fluentCartRestVars: {rest: {url: ''}},
        fluentCartAjax: {
            get: vi.fn(() => ({request: responses.shift(), controller: {}})),
        },
        addEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
        FluentCartSingleProduct: function () {
            this.init = vi.fn();
        },
    };
    globalThis.CustomEvent = class {
        constructor(name, init) {
            this.type = name;
            this.detail = init && init.detail;
        }
    };
    globalThis.document = {
        addEventListener: vi.fn(),
        querySelector: () => null,
        createElement: () => {
            const el = fakeElement();
            created.push(el);
            return el;
        },
        body: {appendChild: vi.fn(), style: {}},
    };
});

async function loadModal() {
    const {default: SingleProductModal} = await import('../../resources/public/product-page/SingleProductModal.js');
    SingleProductModal.cachedView = {};
    return new SingleProductModal();
}

describe('product modal after a refused request', () => {
    it('removes the overlay and does not lock the page scroll', async () => {
        const modal = await loadModal();
        responses.push(Promise.reject(new Error('Product not found')));
        const button = fakeElement();

        await modal.openModal(11, button);

        // created[0] is the wrapper appended to <body>.
        expect(created[0].remove).toHaveBeenCalled();
        expect(document.body.style.overflow).not.toBe('hidden');
        expect(button.classList.remove).toHaveBeenCalledWith('loading');
    });

    it('still opens another product afterwards', async () => {
        const modal = await loadModal();
        responses.push(Promise.reject(new Error('Product not found')));
        responses.push(Promise.resolve({view: '<div>Visible product</div>'}));

        await modal.openModal(11, fakeElement());
        await modal.openModal(12, fakeElement());

        expect(window.fluentCartAjax.get).toHaveBeenCalledTimes(2);
        expect(document.body.style.overflow).toBe('hidden');
    });

    it('does not cache a failed response as a view', async () => {
        const modal = await loadModal();
        responses.push(Promise.reject(new Error('Product not found')));
        responses.push(Promise.reject(new Error('Product not found')));

        await modal.openModal(11, fakeElement());
        await modal.openModal(11, fakeElement());

        expect(window.fluentCartAjax.get).toHaveBeenCalledTimes(2);
    });
});
