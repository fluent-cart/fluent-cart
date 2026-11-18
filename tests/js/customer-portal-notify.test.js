import {afterEach, describe, expect, it, vi} from 'vitest';

const {ElNotification} = vi.hoisted(() => ({ElNotification: vi.fn()}));
vi.mock('element-plus', () => ({ElNotification}));

import Notify from '../../resources/public/customer-profile/utils/PortalNotify.js';

globalThis.window = globalThis.window || {};

afterEach(() => {
    delete window.fluentcart_customer_profile_vars;
    delete globalThis.document;
});

describe('customer portal notices', () => {
    it('lists every field error of a 422 response under a translated title', () => {
        window.fluentcart_customer_profile_vars = {trans: {Error: 'Fehler'}};

        Notify.validationErrors({
            status_code: 422,
            data: {city: {required: 'City is required.'}, postcode: {required: 'Zip is required.', max: 'Zip is too long.'}},
        });

        expect(ElNotification).toHaveBeenCalledWith(expect.objectContaining({
            type: 'error',
            title: 'Fehler',
            message: 'City is required. Zip is required. Zip is too long.',
        }));
    });

    it('shows the message of a validation error that has one', () => {
        Notify.validationErrors({status_code: 422, data: {message: 'Address not found.'}});

        expect(ElNotification).toHaveBeenCalledWith(expect.objectContaining({message: 'Address not found.'}));
    });

    it('opens on the left in a right-to-left page', () => {
        globalThis.document = {documentElement: {dir: 'rtl'}};
        Notify.success('Saved.');

        expect(ElNotification).toHaveBeenCalledWith(expect.objectContaining({position: 'top-left'}));
    });
});
