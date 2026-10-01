import {describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import vm from 'node:vm';

const CUSTOM_PAGE = new URL('../../resources/public/payments/custom-payment-page.js', import.meta.url);

// Strip the ES import so the module runs as a script; the loader is injected.
const source = fs.readFileSync(CUSTOM_PAGE, 'utf8')
    .replace(/^import PaymentLoader.*$/m, '');

function customPage({payMethod = 'stripe'} = {}) {
    const dispatched = [];
    const preparations = [];
    const loaderElement = {addClass: vi.fn(), removeClass: vi.fn()};
    const formElement = {on: vi.fn()};
    const posts = [];

    const jQuery = selector => {
        if (typeof selector === 'function') {
            selector();
            return formElement;
        }
        if (selector === '.fct-loader') {
            return loaderElement;
        }
        if (typeof selector === 'string' && selector.indexOf('form') === 0) {
            return [formElement];
        }
        return formElement;
    };
    jQuery.post = vi.fn((url, payload) => {
        const request = {url, payload};
        request.promise = new Promise(resolve => { request.resolve = resolve; });
        posts.push(request);
        const chain = {
            then: handler => { request.promise = request.promise.then(handler); return chain; },
            catch: handler => { request.promise = request.promise.catch(handler); return chain; }
        };
        return chain;
    });

    let orderHandler = null;

    const context = vm.createContext({
        jQuery,
        PaymentLoader: function (options) {
            orderHandler = options.orderHandler;
            this.load = vi.fn();
            this.getPaymentMethod = () => payMethod;
        },
        URLSearchParams,
        CustomEvent: function (type, init) {
            this.type = type;
            this.detail = init && init.detail;
        },
        alert: vi.fn(),
        window: {
            fluentcart_checkout_info: {
                baseUrl: 'https://shop.test/',
                paymentMode: 'test',
                orderHash: 'hash_1',
                rest_url: 'https://shop.test/wp-json/',
                rest: {nonce: 'nonce_1'},
                ajaxurl: 'https://shop.test/ajax',
                variationId: 7,
                order_information_url: 'https://shop.test/receipt',
                custom_checkout_url: 'https://shop.test/custom-checkout'
            },
            fluentcart: {$t: text => text},
            location: {search: '', href: 'https://shop.test/pay'},
            dispatchEvent: event => {
                dispatched.push(event);
                if (event.type === 'fluent_cart_before_checkout_' + payMethod) {
                    preparations.forEach(prepare => event.detail.waitFor(prepare()));
                }
                return true;
            }
        }
    });

    context['is_' + payMethod + '_ready'] = true;
    context.window['is_' + payMethod + '_ready'] = true;
    vm.runInContext(source, context);

    return {
        dispatched,
        posts,
        loaderElement,
        onPrepare: prepare => preparations.push(prepare),
        submit: () => orderHandler(undefined),
        countDispatched: type => dispatched.filter(event => event.type === type).length
    };
}

const flush = () => new Promise(resolve => setTimeout(resolve, 0));

describe('Custom payment page in-flight guard', () => {
    it('blocks a second submit while gateway preparation is still pending', async () => {
        const page = customPage();
        let release = null;
        page.onPrepare(() => new Promise(resolve => { release = resolve; }));

        const first = page.submit();
        await flush();

        const second = await page.submit();

        expect(second).toBe(false);
        expect(page.countDispatched('fluent_cart_before_checkout_stripe')).toBe(1);
        expect(page.posts).toHaveLength(0);

        release(true);
        await flush();

        expect(page.posts).toHaveLength(1);

        page.posts[0].resolve({});
        await first;
    });

    it('releases the guard when a gateway vetoes the order', async () => {
        const page = customPage();
        page.onPrepare(() => Promise.resolve(false));

        expect(await page.submit()).toBe(false);
        expect(page.posts).toHaveLength(0);

        await page.submit();

        expect(page.countDispatched('fluent_cart_before_checkout_stripe')).toBe(2);
    });

    it('treats a rejected preparation as a veto instead of creating the order', async () => {
        const page = customPage();
        page.onPrepare(() => Promise.reject(new Error('sheet failed to open')));

        expect(await page.submit()).toBe(false);
        expect(page.posts).toHaveLength(0);
    });
});
