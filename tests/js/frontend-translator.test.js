import {describe, expect, it} from 'vitest';
import makeTranslator from '../../resources/public/globals/translator.js';

const CONTEXT_DELIMITER = '\u0004';

describe('frontend window.fluentcart translator', () => {
    it('distinguishes the same msgid across two contexts', () => {
        const {$t, _x} = makeTranslator({
            ['Order status' + CONTEXT_DELIMITER + 'Draft']: 'Entwurf',
            ['Email' + CONTEXT_DELIMITER + 'Draft']: 'Vorlage',
        });

        expect(_x('Draft', 'Order status')).toBe('Entwurf');
        expect(_x('Draft', 'Email')).toBe('Vorlage');
        expect($t('Draft')).toBe('Draft');
    });

    it('falls back to the plain entry, then the original string', () => {
        const {_x} = makeTranslator({Draft: 'Entwurf'});

        expect(_x('Draft', 'Order status')).toBe('Entwurf');
        expect(_x('Paid', 'Order status')).toBe('Paid');
    });

    it('substitutes placeholders after the context argument', () => {
        const {$t, _x} = makeTranslator({['Pagination' + CONTEXT_DELIMITER + '%1$s of %2$s']: '%1$s von %2$s'});

        expect(_x('%1$s of %2$s', 'Pagination', 3, 9)).toBe('3 von 9');
        expect($t('%s items', 4)).toBe('4 items');
        expect($t('%1$s of %2$s', 3)).toBe('3 of %2$s');
    });
});
