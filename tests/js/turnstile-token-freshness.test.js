import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import vm from 'node:vm';

const HANDLER = new URL('../../resources/public/checkout/TurnstileHandler.js', import.meta.url);

const source = fs.readFileSync(HANDLER, 'utf8')
    .replace(/^export default .*$/gm, '');

function element(attrs = {}) {
    return {
        attrs: {...attrs},
        style: {},
        offsetHeight: 0,
        getAttribute(name) { return name in this.attrs ? this.attrs[name] : null; },
        setAttribute(name, value) { this.attrs[name] = String(value); },
        remove: vi.fn(),
        querySelector: () => null,
        appendChild: vi.fn()
    };
}

function turnstileHandler({scriptLoaded = true} = {}) {
    const widget = element({'data-sitekey': 'site-key'});
    let mounted = widget;
    const wrapper = element();
    wrapper.querySelector = () => mounted;
    wrapper.appendChild = el => { mounted = el; };
    widget.remove = () => { mounted = null; };

    const renders = [];
    const toasts = [];
    const listeners = {};

    const context = vm.createContext({
        console,
        Promise,
        Date,
        setTimeout: (...args) => setTimeout(...args),
        clearTimeout: id => clearTimeout(id),
        setInterval: (...args) => setInterval(...args),
        clearInterval: id => clearInterval(id),
        Toastify: function (config) { this.showToast = () => toasts.push(config); },
        document: {
            querySelector: selector => {
                if (selector === '[data-fluent-cart-turnstile-widget]') return wrapper;
                if (selector === '[data-fluent-cart-turnstile-widget] .cf-turnstile') return mounted;
                return null;
            },
            createElement: () => {
                const el = element();
                el.remove = () => { if (mounted === el) mounted = null; };
                return el;
            }
        },
        window: {
            addEventListener: (type, fn) => { listeners[type] = fn; },
            fluentcart_checkout_vars: {turnstile: {enabled: true, site_key: 'site-key'}}
        }
    });
    context.window.window = context.window;

    if (scriptLoaded) {
        context.turnstile = {
            render: vi.fn((el, options) => {
                renders.push(options);
                return 'widget-' + renders.length;
            }),
            reset: vi.fn(),
            remove: vi.fn()
        };
    }

    vm.runInContext(source, context);
    const checkoutHandler = {translate: text => text, cleanupAfterProcessing: vi.fn()};
    const handler = vm.runInContext('new TurnstileHandler(checkoutHandler)', Object.assign(context, {checkoutHandler}));

    return {handler, context, renders, toasts, listeners, mounted: () => mounted};
}

function formData() {
    const appended = {};
    return {append: (key, value) => { appended[key] = value; }, appended};
}

describe('Turnstile token freshness', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-22T10:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('hands back a token issued moments ago without a new challenge', async () => {
        const state = turnstileHandler();
        state.renders[0].callback('fresh-token');

        await expect(state.handler.getToken()).resolves.toBe('fresh-token');
        expect(state.renders).toHaveLength(1);
    });

    it('fetches a new token once the cached one is older than Cloudflare allows', async () => {
        const state = turnstileHandler();
        state.renders[0].callback('old-token');

        vi.advanceTimersByTime(6 * 60 * 1000);

        const pending = state.handler.getToken();
        await vi.advanceTimersByTimeAsync(0);

        expect(state.renders).toHaveLength(2);
        state.renders[1].callback('new-token');
        await vi.advanceTimersByTimeAsync(200);

        await expect(pending).resolves.toBe('new-token');
    });

    it('uses a token once: it is not reused for the next submit', async () => {
        const state = turnstileHandler();
        state.renders[0].callback('single-use');

        const data = formData();
        await expect(state.handler.handleCheckoutSecurityVerification(data)).resolves.toBe(true);

        expect(data.appended.cf_turnstile_token).toBe('single-use');
        expect(state.context.window.fluentCartTurnstileToken).toBeNull();
    });

    it('drops the token when the page comes back from the back-forward cache', () => {
        const state = turnstileHandler();
        state.renders[0].callback('before-redirect');

        state.listeners.pageshow({persisted: true});

        expect(state.context.window.fluentCartTurnstileToken).toBeNull();
    });

    it('explains a security script that never loaded instead of asking for a refresh', async () => {
        const state = turnstileHandler({scriptLoaded: false});

        const verified = state.handler.handleCheckoutSecurityVerification(formData());
        await vi.advanceTimersByTimeAsync(11000);

        await expect(verified).resolves.toBe(false);
        expect(state.toasts).toHaveLength(1);
        expect(state.toasts[0].text).toContain('could not load');
    });
});
