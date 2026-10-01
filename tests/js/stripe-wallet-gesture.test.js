import {describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import vm from 'node:vm';
import {unwrapIife} from './support/unwrap-iife.js';

const CHECKOUT_HANDLER = new URL('../../resources/public/checkout/FluentCartCheckoutHandler.js', import.meta.url);
const STRIPE_CHECKOUT = new URL('../../resources/public/payment-methods/stripe-checkout.js', import.meta.url);

const source = unwrapIife(fs.readFileSync(STRIPE_CHECKOUT, 'utf8'));

// Execute the real checkout module with Stripe, XHR and DOM boundaries mocked.
async function checkout(options = {}) {
    const submitResult = options.submitResult || {};
    const listeners = {};
    const elementListeners = {};
    const overlay = {classList: {add: vi.fn(), remove: vi.fn()}};
    const requests = [];
    const toasts = [];
    let submitting = false;
    const paymentLoader = {
        changeLoaderStatus: vi.fn(), hideLoader: vi.fn(),
        enableCheckoutButton: vi.fn(() => { submitting = false; }),
        disableCheckoutButton: vi.fn(),
        triggerPaymentCompleteEvent: vi.fn(),
        isSubmitting: () => submitting
    };
    const element = {
        mount: vi.fn(),
        on: (name, handler) => { elementListeners[name] = handler; },
        addEventListener: (name, handler) => { elementListeners[name] = handler; }
    };
    let releaseSubmit = null;
    const elements = {
        create: () => element,
        submit: vi.fn(() => options.deferSubmit
            ? new Promise(resolve => { releaseSubmit = () => resolve(submitResult); })
            : Promise.resolve(submitResult))
    };
    const stripe = {
        elements: () => elements,
        confirmPayment: vi.fn().mockResolvedValue(
            options.confirmResult || {paymentIntent: {id: 'pi_test', status: 'succeeded'}}
        ),
        confirmSetup: vi.fn(() => new Promise(() => {}))
    };
    const location = {href: 'https://shop.test/checkout', search: ''};
    const context = vm.createContext({
        window: {
            addEventListener: (name, handler) => { listeners[name] = handler; },
            dispatchEvent: vi.fn(),
            location,
            fluentcart_checkout_vars: {submit_button: {text: 'Place Order'}, ajaxurl: 'https://shop.test/ajax'}
        },
        document: {
            addEventListener: vi.fn(), body: {},
            querySelector: selector => selector === '.fct-loader' ? overlay : null,
            querySelectorAll: () => [],
            getElementById: () => null
        },
        Stripe: () => stripe,
        Toastify: function (config) { this.showToast = () => toasts.push(config); },
        MutationObserver: function () { this.observe = vi.fn(); this.disconnect = vi.fn(); },
        CustomEvent: function () {},
        URL,
        URLSearchParams,
        XMLHttpRequest: function () {
            this.readyState = 4;
            this.status = 200;
            this.responseText = '{"redirect_url":"https://shop.test/receipt"}';
            this.open = vi.fn();
            this.setRequestHeader = vi.fn();
            this.send = vi.fn();
            requests.push(this);
        },
        requestAnimationFrame: callback => callback()
    });
    vm.runInContext(source, context);
    context.form = {querySelector: () => null};
    context.paymentLoader = paymentLoader;
    const args = {gateway_return_url: 'https://shop.test/?fluent-cart=fct_stripe_onsite_return&trx_hash=abc'};
    const init = () => vm.runInContext(
        'new StripeCheckout(form, {payment_args: {}, intent: {}}, paymentLoader).init()',
        context
    ).then(() => elementListeners.ready());

    await init();

    const settle = () => new Promise(resolve => setImmediate(resolve));

    // What the Place Order click does: dispatch, then await the collected work.
    const beforeCheckout = () => {
        const preparations = [];
        listeners.fluent_cart_before_checkout_stripe({
            detail: {waitFor: promise => preparations.push(Promise.resolve(promise))}
        });
        return Promise.all(preparations);
    };

    const nextAction = async () => {
        listeners.fluent_cart_payment_next_action_stripe({
            detail: {
                response: {
                    response: {object: 'payment_intent', client_secret: 'test_secret', id: 'pi_test'},
                    payment_args: args
                }
            }
        });
        await settle();
    };

    return {
        elements, stripe, overlay, paymentLoader, toasts, requests, location,
        init, beforeCheckout, nextAction, settle,
        beginSubmission: () => { submitting = true; },
        changePaymentElement: complete => elementListeners.change({complete}),
        releaseSubmit: () => releaseSubmit()
    };
}

describe('Stripe wallet user gesture', () => {
    it('collects the wallet inside the click, before the order request is made', async () => {
        const state = await checkout();

        const prepared = state.beforeCheckout();

        // Synchronously — an await before this point is what revokes the gesture.
        expect(state.elements.submit).toHaveBeenCalledOnce();
        await expect(prepared).resolves.toEqual([true]);
    });

    it('confirms with the submission prepared in the click, without collecting twice', async () => {
        const state = await checkout();

        await state.beforeCheckout();
        await state.nextAction();

        expect(state.elements.submit).toHaveBeenCalledOnce();
        expect(state.stripe.confirmPayment).toHaveBeenCalledOnce();
    });

    it('vetoes the order request when the buyer cancels the wallet', async () => {
        const state = await checkout({submitResult: {error: {message: 'Unable to show Apple Pay'}}});

        await expect(state.beforeCheckout()).resolves.toEqual([false]);
        expect(state.toasts.map(toast => toast.text)).toContain('Unable to show Apple Pay');
        expect(state.stripe.confirmPayment).not.toHaveBeenCalled();
    });

    it('vetoes the order when a re-init replaces Elements while the wallet is open', async () => {
        const state = await checkout({deferSubmit: true});

        const prepared = state.beforeCheckout();
        await state.init();
        state.releaseSubmit();

        // The collection belongs to the Elements instance the re-init discarded,
        // so it cannot be confirmed — the order must not be created.
        await expect(prepared).resolves.toEqual([false]);
        expect(state.stripe.confirmPayment).not.toHaveBeenCalled();
    });

    it('drops a submission prepared against an Elements instance a re-init replaced', async () => {
        const state = await checkout();

        await state.beforeCheckout();
        await state.init();
        await state.nextAction();

        expect(state.elements.submit).toHaveBeenCalledTimes(2);
    });
});

describe('Stripe confirmation recovery', () => {
    it('reconciles the same intent server-side when the confirmation report fails', async () => {
        const state = await checkout();
        state.location.href = 'https://shop.test/checkout';

        await state.beforeCheckout();
        await state.nextAction();

        const xhr = state.requests[0];
        xhr.status = 500;
        xhr.onreadystatechange();

        expect(state.location.href).toContain('fct_stripe_onsite_return');
        expect(state.location.href).toContain('payment_intent=pi_test');
        expect(state.paymentLoader.enableCheckoutButton).not.toHaveBeenCalledWith('Place Order');
    });

    it('never re-offers payment when the confirmation report is interrupted', async () => {
        const state = await checkout();
        state.location.href = 'https://shop.test/checkout';

        await state.beforeCheckout();
        await state.nextAction();

        state.requests[0].onerror();

        expect(state.location.href).toBe('https://shop.test/checkout');
        expect(state.paymentLoader.enableCheckoutButton).not.toHaveBeenCalledWith('Place Order');
        // Duration -1 keeps the notice up: the buyer must not pay again.
        expect(state.toasts).toContainEqual(expect.objectContaining({duration: -1}));
    });
});

describe('Stripe require-action fallback', () => {
    const requiresAction = (type, payload) => ({
        paymentIntent: {id: 'pi_test', status: 'requires_action', next_action: {type, [type]: payload}}
    });

    it('keeps the page on checkout while a WeChat QR is on screen', async () => {
        const state = await checkout({
            confirmResult: requiresAction('wechat_pay_display_qr_code', {
                hosted_instructions_url: 'https://stripe.test/wechat'
            })
        });
        state.location.href = 'https://shop.test/checkout';

        await state.beforeCheckout();
        await state.nextAction();

        expect(state.location.href).toBe('https://shop.test/checkout');
        expect(state.paymentLoader.hideLoader).toHaveBeenCalled();
        expect(state.paymentLoader.enableCheckoutButton).toHaveBeenCalledWith('Place Order');
        expect(state.overlay.classList.remove).toHaveBeenCalledWith('active');
    });

    it('keeps the page on checkout while a PromptPay QR is on screen', async () => {
        const state = await checkout({
            confirmResult: requiresAction('promptpay_display_qr_code', {
                hosted_instructions_url: 'https://stripe.test/promptpay'
            })
        });
        state.location.href = 'https://shop.test/checkout';

        await state.beforeCheckout();
        await state.nextAction();

        expect(state.location.href).toBe('https://shop.test/checkout');
        expect(state.paymentLoader.enableCheckoutButton).toHaveBeenCalledWith('Place Order');
    });

    it('still redirects for Cash App, whose modal the browser closes', async () => {
        const state = await checkout({
            confirmResult: requiresAction('cashapp_handle_redirect_or_display_qr_code', {
                hosted_instructions_url: 'https://stripe.test/cashapp'
            })
        });

        await state.beforeCheckout();
        await state.nextAction();

        expect(state.location.href).toBe('https://stripe.test/cashapp');
    });

    it('releases the loader for an action type it cannot handle', async () => {
        const state = await checkout({confirmResult: requiresAction('some_future_action', {})});
        state.location.href = 'https://shop.test/checkout';

        await state.beforeCheckout();
        await state.nextAction();

        expect(state.location.href).toBe('https://shop.test/checkout');
        expect(state.paymentLoader.hideLoader).toHaveBeenCalled();
    });
});

describe('Stripe payment element submit state', () => {
    it('leaves the button alone while an attempt is in flight', async () => {
        const state = await checkout();
        state.beginSubmission();
        state.paymentLoader.enableCheckoutButton.mockClear();

        state.changePaymentElement(true);

        expect(state.paymentLoader.enableCheckoutButton).not.toHaveBeenCalled();
    });

    it('restores the button once the gateway has handed it back', async () => {
        const state = await checkout();
        state.beginSubmission();
        state.paymentLoader.enableCheckoutButton('Place Order');
        state.paymentLoader.enableCheckoutButton.mockClear();

        state.changePaymentElement(true);

        expect(state.paymentLoader.enableCheckoutButton).toHaveBeenCalledWith('Place Order');
    });
});

describe('Checkout handler gesture ordering', () => {
    it('dispatches the before-checkout event with no await ahead of it', () => {
        const handler = fs.readFileSync(CHECKOUT_HANDLER, 'utf8');
        const body = handler.slice(handler.indexOf('async handleOrder('));
        const dispatchAt = body.indexOf('fluent_cart_before_checkout_');
        const submitAt = body.indexOf('this.submitCheckoutForm(');

        expect(dispatchAt).toBeGreaterThan(-1);
        expect(dispatchAt).toBeLessThan(submitAt);
        // Safari revokes the gesture at the first await, so there must be none before.
        expect(body.slice(0, dispatchAt).replace(/\/\/[^\n]*/g, '')).not.toMatch(/\bawait\b/);
    });
});
