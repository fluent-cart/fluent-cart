import {describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import vm from 'node:vm';

const read = path => fs.readFileSync(new URL(path, import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace(/^export default .*$/gm, '');

const handlerSource = read('../../resources/public/checkout/FluentCartCheckoutHandler.js');
const turnstileSource = read('../../resources/public/checkout/TurnstileHandler.js');

function element(overrides = {}) {
    const classes = new Set();
    return {
        style: {setProperty: vi.fn()},
        classList: {
            add: name => classes.add(name),
            remove: name => classes.delete(name),
            contains: name => classes.has(name),
            toggle: vi.fn()
        },
        dataset: {},
        addEventListener: vi.fn(),
        getAttribute: () => null,
        setAttribute: vi.fn(),
        querySelector: () => null,
        querySelectorAll: () => [],
        closest: () => null,
        appendChild: vi.fn(),
        remove: vi.fn(),
        ...overrides
    };
}

// Drives the real handler (and optionally the real TurnstileHandler) in a sandbox.
function checkout({realTurnstile = false} = {}) {
    const form = element();
    const toasts = [];
    const requests = [];
    const navigations = [];
    let buttonDisabled = false;
    let overlay = null;

    const checkoutUiService = {
        enableCheckoutButton: () => { buttonDisabled = false; },
        disableCheckoutButton: () => { buttonDisabled = true; },
        setCheckoutButtonText: vi.fn(),
        hideCheckoutButton: vi.fn(),
        showCheckoutButton: vi.fn(),
        isCheckoutButtonDisabled: () => buttonDisabled
    };

    const paymentLoader = {
        payMethod: 'stripe',
        load: vi.fn(),
        disableCheckoutButton: () => checkoutUiService.disableCheckoutButton(),
        enableCheckoutButton: () => checkoutUiService.enableCheckoutButton(),
        hideCheckoutButton: vi.fn()
    };

    const service = {init: vi.fn()};
    const location = {
        search: '',
        get href() { return 'https://shop.test/checkout/'; },
        set href(url) { navigations.push(url); }
    };

    const window = {
        addEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
        location,
        fluentcart: {$t: text => text},
        fluentCartRestVars: {rest: {url: 'https://shop.test/wp-json/', nonce: 'n'}},
        fluentCartCheckout: {nonce: 'n'},
        fluentcart_checkout_info: {rest: {nonce: 'n'}, order_confirmation_url: 'https://shop.test/confirm'},
        fluentcart_checkout_vars: {
            submit_button: {text: 'Place Order'},
            is_all_digital: '1',
            is_cart_locked: 'no',
            payment_methods_with_custom_checkout_buttons: [],
            turnstile: {enabled: realTurnstile, site_key: 'key'}
        },
        is_stripe_ready: true
    };
    window.window = window;
    window.self = window;
    window.top = window;

    const context = vm.createContext({
        console,
        Promise,
        setTimeout: vi.fn(),
        setInterval: vi.fn(),
        clearInterval: vi.fn(),
        clearTimeout: vi.fn(),
        FormData: function () {
            this.append = vi.fn();
            this.entries = () => [];
        },
        URL,
        URLSearchParams,
        CustomEvent: function (type, init) {
            this.type = type;
            this.detail = init && init.detail;
        },
        Toastify: function (config) { this.showToast = () => toasts.push(config); },
        fetch: vi.fn(() => {
            const request = {};
            request.promise = new Promise(resolve => { request.resolve = resolve; });
            requests.push(request);
            return request.promise;
        }),
        dayjs: Object.assign(() => ({}), {extend: vi.fn(), tz: {guess: () => 'UTC'}}),
        utc: {}, timezone: {},
        AddressField: function () { return service; },
        CouponService: function () { return service; },
        CheckoutHelper: {buildUrl: url => url, getParams: () => ({})},
        Url: function () {},
        ShippingService: {init: vi.fn()},
        AddressService: {init: vi.fn()},
        DataWatcher: {init: vi.fn()},
        TaxService: {init: vi.fn()},
        CheckoutUIService: {init: () => checkoutUiService},
        PaymentLoader: function () { return paymentLoader; },
        // Turnstile loaded, but no widget on the page: getToken() comes back empty.
        turnstile: {render: vi.fn(), reset: vi.fn(), remove: vi.fn()},
        document: {
            addEventListener: vi.fn(),
            querySelector: selector => (selector === '.fct-order-processing' ? overlay : null),
            querySelectorAll: () => [],
            createElement: () => element(),
            body: {appendChild: node => { overlay = node; }}
        },
        window
    });
    context.globalThis = context;

    if (realTurnstile) {
        vm.runInContext(turnstileSource, context);
    } else {
        vm.runInContext(
            'var TurnstileHandler = function () {' +
            ' this.handleCheckoutSecurityVerification = async () => true;' +
            ' this.reset = function () {}; };',
            context
        );
    }

    vm.runInContext(handlerSource, context);
    const handler = vm.runInContext(
        'new FluentCartCheckoutHandler(null, form, {})',
        Object.assign(context, {form})
    );

    const settle = () => new Promise(resolve => setImmediate(resolve));

    const respond = async body => {
        requests[requests.length - 1].resolve({json: async () => body});
        await settle();
        await settle();
    };

    return {
        handler, toasts, requests, navigations, settle, respond, window,
        isButtonDisabled: () => buttonDisabled,
        overlayVisible: () => !!overlay && !overlay.classList.contains('fct-loader-hidden')
    };
}

describe('Checkout processing overlay', () => {
    it('clears when the Turnstile check fails', async () => {
        const state = checkout({realTurnstile: true});

        await expect(state.handler.handleOrder()).resolves.toBe(false);

        expect(state.requests).toHaveLength(0);
        expect(state.overlayVisible()).toBe(false);
        expect(state.isButtonDisabled()).toBe(false);
        expect(state.handler.isSubmitting()).toBe(false);
        expect(state.toasts.map(toast => toast.text))
            .toContain('Security check failed. Please refresh the page and try again.');
    });

    it('clears when a success response has no next step', async () => {
        const state = checkout();

        state.handler.handleOrder();
        await state.settle();
        expect(state.overlayVisible()).toBe(true);

        await state.respond({status: 'success', message: 'Order placed'});

        expect(state.navigations).toEqual([]);
        expect(state.overlayVisible()).toBe(false);
        expect(state.isButtonDisabled()).toBe(false);
        expect(state.handler.isSubmitting()).toBe(false);
        expect(state.toasts.map(toast => toast.text).join(' ')).toContain('Something went wrong');
    });

    it('clears when an after-checkout callback throws and nothing navigates', async () => {
        const state = checkout();
        state.window.fluentCartCheckout.afterCheckoutCallbacks = [
            async () => { throw new Error('extension blew up'); }
        ];

        state.handler.handleOrder();
        await state.settle();
        await state.respond({status: 'success'});

        expect(state.overlayVisible()).toBe(false);
        expect(state.isButtonDisabled()).toBe(false);
        expect(state.toasts.map(toast => toast.text).join(' ')).toContain('Something went wrong');
    });

    it('still redirects when an after-checkout callback throws', async () => {
        const state = checkout();
        state.window.fluentCartCheckout.afterCheckoutCallbacks = [
            () => { throw new Error('extension blew up'); }
        ];

        state.handler.handleOrder();
        await state.settle();
        await state.respond({status: 'success', redirect_to: 'https://shop.test/receipt/'});

        expect(state.navigations).toEqual(['https://shop.test/receipt/']);
        expect(state.overlayVisible()).toBe(true);
    });

    it('keeps the overlay while redirecting to the receipt', async () => {
        const state = checkout();

        state.handler.handleOrder();
        await state.settle();
        await state.respond({status: 'success', redirect_to: 'https://shop.test/receipt/'});

        expect(state.navigations).toEqual(['https://shop.test/receipt/']);
        expect(state.overlayVisible()).toBe(true);
        expect(state.toasts).toEqual([]);
    });

    it('keeps the overlay while a gateway handles its custom next action', async () => {
        const state = checkout();

        state.handler.handleOrder();
        await state.settle();
        await state.respond({status: 'success', actionName: 'custom', nextAction: 'stripe'});

        const events = state.window.dispatchEvent.mock.calls.map(([event]) => event.type);
        expect(events).toContain('fluent_cart_payment_next_action_stripe');
        expect(state.overlayVisible()).toBe(true);
        expect(state.toasts).toEqual([]);
    });

    it('leaves navigation to an after-checkout callback that completes', async () => {
        const state = checkout();
        const callback = vi.fn(async () => {});
        state.window.fluentCartCheckout.afterCheckoutCallbacks = [callback];

        state.handler.handleOrder();
        await state.settle();
        await state.respond({status: 'success'});

        expect(callback).toHaveBeenCalled();
        expect(state.overlayVisible()).toBe(true);
        expect(state.toasts).toEqual([]);
    });
});
