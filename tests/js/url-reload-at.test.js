import {beforeEach, describe, expect, it, vi} from 'vitest';

vi.mock('vue-router', () => ({useRoute: () => ({})}));

import Url from '../../resources/admin/utils/support/Url.js';

/**
 * After an add-on install or activation the admin reloads, because the add-on registers its routes and assets
 * only on a fresh page load. Url.reloadAt() makes that reload land on the add-on's redirect target: an admin route
 * or a full URL.
 */
const ADMIN = 'https://shop.test/wp-admin/admin.php?page=fluent-cart';

describe('Url.reloadAt', () => {
    beforeEach(() => {
        globalThis.window = {
            history: {replaceState: vi.fn()},
            location: {href: ADMIN + '#/settings/addons', reload: vi.fn(), assign: vi.fn()},
        };
    });

    it('swaps the hash for an admin route without a router navigation, then reloads', () => {
        Url.reloadAt('/settings/payments/paystack');

        expect(window.history.replaceState).toHaveBeenCalledWith(null, '', '#/settings/payments/paystack');
        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    it('reloads a full URL to this admin page that differs only in the hash', () => {
        Url.reloadAt(ADMIN + '#/settings/shipping/carriers');

        expect(window.history.replaceState).toHaveBeenCalledWith(null, '', '#/settings/shipping/carriers');
        expect(window.location.reload).toHaveBeenCalledTimes(1);
        expect(window.location.assign).not.toHaveBeenCalled();
    });

    it('opens a full URL to another page', () => {
        Url.reloadAt('https://shop.test/wp-admin/admin.php?page=fluent-cart-elementor');

        expect(window.location.assign).toHaveBeenCalledWith('https://shop.test/wp-admin/admin.php?page=fluent-cart-elementor');
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('reloads the current page when there is no target', () => {
        Url.reloadAt('');

        expect(window.history.replaceState).not.toHaveBeenCalled();
        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });
});
