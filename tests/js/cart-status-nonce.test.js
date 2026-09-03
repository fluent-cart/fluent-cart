import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

import FluentCartCart from '../../resources/public/globals/Cart/FluentCartCart.js';

/**
 * WebCheckoutHandler::globalCheckoutRouteHandler() validates X-WP-Nonce
 * before dispatching every fc_checkout_action, including cart_status. The
 * getCart() request built its own headers object instead of reusing
 * #updateCart's, so the nonce silently never made it onto the wire and the
 * status check always came back 403.
 */

function jsonResponse(body) {
    return Promise.resolve({
        ok: true,
        json: () => Promise.resolve(body),
    });
}

beforeEach(() => {
    vi.stubGlobal('window', {
        fluentCartRestVars: {
            ajaxurl: 'https://example.test/wp-admin/admin-ajax.php',
            rest: {nonce: 'test-nonce'},
        },
        dispatchEvent: vi.fn(),
    });
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('cart-status request nonce', () => {

    it('sends X-WP-Nonce on the cart-status request', async () => {
        const fetchMock = vi.fn(() => jsonResponse({cart_data: []}));
        vi.stubGlobal('fetch', fetchMock);

        await new FluentCartCart().getCart();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toContain('fc_checkout_action=fluent_cart_cart_status');
        expect(options.headers['X-WP-Nonce']).toBe('test-nonce');
    });

    /**
     * The header is only set when a nonce value actually exists — omitted
     * entirely otherwise, rather than sent with a coerced "undefined"
     * string. A real fetch() runs `headers` through the Headers
     * constructor, which stringifies a present-but-undefined value instead
     * of dropping the key, so building the object conditionally is what
     * keeps a missing nonce from putting a garbage header on the wire.
     */
    it('does not throw, and omits the header, when the localized REST data has no rest block', async () => {
        window.fluentCartRestVars = {ajaxurl: 'https://example.test/wp-admin/admin-ajax.php'};
        const fetchMock = vi.fn(() => jsonResponse({cart_data: []}));
        vi.stubGlobal('fetch', fetchMock);

        await expect(new FluentCartCart().getCart()).resolves.toEqual([]);

        const [, options] = fetchMock.mock.calls[0];
        expect(new Headers(options.headers).has('X-WP-Nonce')).toBe(false);
    });

    it('does not throw, and omits the header, when fluentCartRestVars.rest is null', async () => {
        window.fluentCartRestVars = {
            ajaxurl: 'https://example.test/wp-admin/admin-ajax.php',
            rest: null,
        };
        const fetchMock = vi.fn(() => jsonResponse({cart_data: []}));
        vi.stubGlobal('fetch', fetchMock);

        await expect(new FluentCartCart().getCart()).resolves.toEqual([]);

        const [, options] = fetchMock.mock.calls[0];
        expect(new Headers(options.headers).has('X-WP-Nonce')).toBe(false);
    });
});
