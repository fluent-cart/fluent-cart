import {beforeEach, describe, expect, it, vi} from 'vitest';

import CheckoutUIService from '../../resources/public/checkout/CheckoutUIService.js';

/**
 * A short-lived hold (an add-on saving a selection) disables the place-order
 * button. Releasing it must restore whatever state the button was last asked
 * for — a gateway that disabled it meanwhile keeps it disabled.
 */

function button() {
    const attrs = {};
    return {
        dataset: {},
        setAttribute: (name, value) => { attrs[name] = value; },
        removeAttribute: (name) => { delete attrs[name]; },
        get disabled() {
            return 'disabled' in attrs;
        }
    };
}

function service() {
    const btn = button();
    const form = {querySelector: (selector) => (selector === '#fluent_cart_order_btn' ? btn : null)};
    return {ui: new CheckoutUIService({form}), btn};
}

beforeEach(() => {
    vi.stubGlobal('window', {dispatchEvent: vi.fn()});
});

describe('CheckoutUIService button holds', () => {
    it('disables the button while held and gives it back on release', () => {
        const {ui, btn} = service();
        ui.enableCheckoutButton();

        ui.holdCheckoutButton('selection');
        expect(btn.disabled).toBe(true);
        expect(ui.isCheckoutButtonDisabled()).toBe(true);

        ui.releaseCheckoutButton('selection');
        expect(btn.disabled).toBe(false);
    });

    it('keeps a disable requested during the hold after the hold is released', () => {
        const {ui, btn} = service();
        ui.enableCheckoutButton();

        ui.holdCheckoutButton('selection');
        ui.disableCheckoutButton(); // e.g. a gateway failing to load

        ui.releaseCheckoutButton('selection');
        expect(btn.disabled).toBe(true);
    });

    it('does not let an enable requested during the hold release it early', () => {
        const {ui, btn} = service();

        ui.holdCheckoutButton('selection');
        ui.enableCheckoutButton(); // e.g. payment methods re-initialised

        expect(btn.disabled).toBe(true);

        ui.releaseCheckoutButton('selection');
        expect(btn.disabled).toBe(false);
    });

    it('stays disabled until every holder has released', () => {
        const {ui, btn} = service();
        ui.enableCheckoutButton();

        ui.holdCheckoutButton('selection');
        ui.holdCheckoutButton('order-bump');
        ui.releaseCheckoutButton('selection');

        expect(btn.disabled).toBe(true);

        ui.releaseCheckoutButton('order-bump');
        expect(btn.disabled).toBe(false);
    });

    it('leaves plain enable and disable unchanged when nothing holds the button', () => {
        const {ui, btn} = service();

        ui.disableCheckoutButton();
        expect(btn.disabled).toBe(true);

        ui.enableCheckoutButton();
        expect(btn.disabled).toBe(false);
    });
});
