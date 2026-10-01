import {describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import vm from 'node:vm';

const HANDLER = new URL('../../resources/public/checkout/FluentCartCheckoutHandler.js', import.meta.url);

const source = fs.readFileSync(HANDLER, 'utf8')
    .replace(/^import .*$/gm, '')
    .replace(/^export default .*$/gm, '');

function element(overrides = {}) {
    return {
        style: {setProperty: vi.fn()},
        classList: {add: vi.fn(), remove: vi.fn(), toggle: vi.fn()},
        dataset: {},
        addEventListener: vi.fn(),
        querySelector: () => null,
        querySelectorAll: () => [],
        closest: () => null,
        appendChild: vi.fn(),
        remove: vi.fn(),
        ...overrides
    };
}

function checkout({responseBody = {status: 'success'}} = {}) {
    const form = element();
    const toasts = [];
    const requests = [];
    const buttonText = [];
    let buttonDisabled = false;

    const checkoutUiService = {
        enableCheckoutButton: () => { buttonDisabled = false; },
        disableCheckoutButton: () => { buttonDisabled = true; },
        setCheckoutButtonText: text => buttonText.push(text),
        hideCheckoutButton: vi.fn(),
        showCheckoutButton: vi.fn(),
        isCheckoutButtonDisabled: () => false
    };

    const paymentLoader = {
        payMethod: 'stripe',
        load: vi.fn(),
        disableCheckoutButton: vi.fn(message => {
            buttonText.push(message);
            checkoutUiService.disableCheckoutButton();
        }),
        enableCheckoutButton: vi.fn(message => {
            handler.endSubmission?.();
            buttonText.push(message);
            checkoutUiService.enableCheckoutButton();
        }),
        isSubmitting: () => handler.isSubmitting()
    };

    const noop = function () {};
    const service = {init: vi.fn()};

    const context = vm.createContext({
        console,
        Promise,
        setTimeout: vi.fn(),
        FormData: function () {
            this.append = vi.fn();
            this.entries = () => [];
        },
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
        TurnstileHandler: function () {
            this.handleCheckoutSecurityVerification = async () => true;
            this.reset = vi.fn();
        },
        CheckoutUIService: {init: () => checkoutUiService},
        PaymentLoader: function () { return paymentLoader; },
        document: {
            addEventListener: vi.fn(),
            querySelector: () => null,
            querySelectorAll: () => [],
            createElement: () => element(),
            body: {appendChild: vi.fn()}
        },
        window: {
            addEventListener: vi.fn(),
            dispatchEvent: vi.fn(),
            fluentcart: {$t: text => text},
            fluentCartRestVars: {rest: {url: 'https://shop.test/wp-json/', nonce: 'n'}},
            fluentCartCheckout: {nonce: 'n'},
            fluentcart_checkout_info: {rest: {nonce: 'n'}, order_confirmation_url: 'https://shop.test/confirm'},
            fluentcart_checkout_vars: {
                submit_button: {text: 'Place Order'},
                is_all_digital: '1',
                is_cart_locked: 'no',
                payment_methods_with_custom_checkout_buttons: []
            },
            is_stripe_ready: true
        }
    });
    context.window.window = context.window;
    context.globalThis = context;

    vm.runInContext(source, context);

    const handler = vm.runInContext(
        'new FluentCartCheckoutHandler(null, form, {})',
        Object.assign(context, {form})
    );

    const settle = () => new Promise(resolve => setImmediate(resolve));

    const respond = async (body = responseBody) => {
        requests[requests.length - 1].resolve({json: async () => body});
        await settle();
    };

    return {handler, form, paymentLoader, requests, toasts, settle, respond,
        buttonText, isButtonDisabled: () => buttonDisabled, context};
}

describe('Checkout submission state', () => {
    it('refuses a second submit while the first attempt is still in flight', async () => {
        const state = checkout();

        state.handler.handleOrder();
        await state.settle();

        expect(state.requests).toHaveLength(1);

        state.handler.handleOrder();
        await state.settle();

        expect(state.requests).toHaveLength(1);
        expect(state.handler.isSubmitting()).toBe(true);
    });

    it('accepts a retry once the gateway hands the button back', async () => {
        const state = checkout();

        state.handler.handleOrder();
        await state.settle();
        state.paymentLoader.enableCheckoutButton('Place Order');

        expect(state.handler.isSubmitting()).toBe(false);

        state.handler.handleOrder();
        await state.settle();

        expect(state.requests).toHaveLength(2);
    });

    it('releases the submission when the order is refused', async () => {
        const state = checkout();

        state.handler.handleOrder();
        await state.settle();
        await state.respond({status: 'failed', message: 'Out of stock'});

        expect(state.handler.isSubmitting()).toBe(false);
    });

    it('releases the submission when a third-party callback throws', async () => {
        const state = checkout();
        state.context.window.fluentCartCheckout.beforeCheckoutCallbacks = [
            () => { throw new Error('extension blew up'); }
        ];

        await expect(state.handler.handleOrder()).resolves.toBe(false);

        expect(state.requests).toHaveLength(0);
        expect(state.isButtonDisabled()).toBe(false);
        expect(state.handler.isSubmitting()).toBe(false);
    });
});
