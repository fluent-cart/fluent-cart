import {afterEach, describe, expect, it} from 'vitest';
import translate, {_x} from '../../resources/public/customer-profile/translator/Translator.js';

const CONTEXT_DELIMITER = '\u0004';

globalThis.window = globalThis.window || {};

function setTrans(trans) {
    window.fluentcart_customer_profile_vars = {trans};
}

afterEach(() => {
    delete window.fluentcart_customer_profile_vars;
});

describe('customer portal _x()', () => {
    it('distinguishes the same msgid across two contexts', () => {
        setTrans({
            ['Order status' + CONTEXT_DELIMITER + 'Draft']: 'Entwurf',
            ['Email' + CONTEXT_DELIMITER + 'Draft']: 'Vorlage',
        });

        expect(_x('Draft', 'Order status')).toBe('Entwurf');
        expect(_x('Draft', 'Email')).toBe('Vorlage');
        expect(translate('Draft')).toBe('Draft');
    });

    it('falls back to the plain entry, then the original string', () => {
        setTrans({Draft: 'Entwurf'});

        expect(_x('Draft', 'Order status')).toBe('Entwurf');
        expect(_x('Paid', 'Order status')).toBe('Paid');
    });

    it('substitutes placeholders after the context argument', () => {
        setTrans({['Pagination' + CONTEXT_DELIMITER + '%1$s of %2$s']: '%1$s von %2$s'});

        expect(_x('%1$s of %2$s', 'Pagination', 3, 9)).toBe('3 von 9');
        expect(translate('%s items', 4)).toBe('4 items');
    });

    it('returns the original string when the map is missing', () => {
        window.fluentcart_customer_profile_vars = {};

        expect(_x('Draft', 'Order status')).toBe('Draft');
    });
});
