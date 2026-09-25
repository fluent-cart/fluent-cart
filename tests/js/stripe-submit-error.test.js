import {describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../resources/public/payment-methods/stripe-checkout.js', import.meta.url), 'utf8');

// Execute the real checkout module with Stripe and DOM boundaries mocked.
async function checkout(submitResult, object = 'payment_intent') {
    const listeners = {};
    const elementListeners = {};
    const overlay = {classList: {add: vi.fn(), remove: vi.fn()}};
    const paymentLoader = {
        changeLoaderStatus: vi.fn(), hideLoader: vi.fn(),
        enableCheckoutButton: vi.fn(), disableCheckoutButton: vi.fn()
    };
    const element = {
        mount: vi.fn(),
        on: (name, handler) => { elementListeners[name] = handler; },
        addEventListener: vi.fn()
    };
    const elements = {create: () => element, submit: vi.fn().mockResolvedValue(submitResult)};
    // Keep confirmation pending: these tests concern whether it is invoked.
    const stripe = {
        elements: () => elements,
        confirmPayment: vi.fn(() => new Promise(() => {})),
        confirmSetup: vi.fn(() => new Promise(() => {}))
    };
    const toast = vi.fn();
    const context = vm.createContext({
        window: {
            addEventListener: (name, handler) => { listeners[name] = handler; },
            dispatchEvent: vi.fn(),
            fluentcart_checkout_vars: {submit_button: {text: 'Place Order'}}
        },
        document: {
            addEventListener: vi.fn(), body: {},
            querySelector: selector => selector === '.fct-loader' ? overlay : null,
            getElementById: () => null
        },
        Stripe: () => stripe,
        Toastify: function (options) { this.showToast = () => toast(options.text); },
        MutationObserver: function () { this.observe = vi.fn(); this.disconnect = vi.fn(); },
        CustomEvent: function () {},
        requestAnimationFrame: callback => callback()
    });
    vm.runInContext(source, context);
    context.form = {querySelector: () => null};
    context.paymentLoader = paymentLoader;
    await vm.runInContext('new StripeCheckout(form, {payment_args: {}, intent: {}}, paymentLoader).init()', context);
    elementListeners.ready();
    const submit = async () => {
        listeners.fluent_cart_payment_next_action_stripe({
            detail: {response: {response: {object, client_secret: 'test_secret'}, payment_args: {}}}
        });
        await new Promise(resolve => setImmediate(resolve));
    };
    await submit();
    return {stripe, elements, overlay, paymentLoader, toast, submit};
}

describe('Stripe Elements submit errors', () => {
    it.each(['payment_intent', 'setup_intent'])('stops %s confirmation and releases checkout on a submit error', async object => {
        const state = await checkout({error: {type: 'validation_error', message: 'Payment details are incomplete'}}, object);
        expect(state.stripe.confirmPayment).not.toHaveBeenCalled();
        expect(state.stripe.confirmSetup).not.toHaveBeenCalled();
        expect(state.toast).toHaveBeenCalledWith('Payment details are incomplete');
        expect(state.overlay.classList.remove).toHaveBeenCalledWith('active');
        expect(state.paymentLoader.hideLoader).toHaveBeenCalled();
        expect(state.paymentLoader.enableCheckoutButton).toHaveBeenCalledWith('Place Order');
    });

    it.each(['payment_intent', 'setup_intent'])('allows %s confirmation after the buyer corrects a submit error', async object => {
        const state = await checkout({error: {message: 'Unable to show Apple Pay'}}, object);
        expect(state.stripe.confirmPayment).not.toHaveBeenCalled();
        expect(state.stripe.confirmSetup).not.toHaveBeenCalled();
        state.elements.submit.mockResolvedValue({});
        await state.submit();
        const method = object === 'setup_intent' ? 'confirmSetup' : 'confirmPayment';
        expect(state.stripe[method]).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({
            elements: state.elements, clientSecret: 'test_secret'
        }));
    });
});
