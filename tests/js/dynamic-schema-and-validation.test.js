import {describe, expect, it, vi} from 'vitest';
import Arr from '../../resources/admin/utils/support/Arr.js';
import Condition from '../../resources/admin/utils/model/form/Condition/Condition.js';
import ConditionBuilder from '../../resources/admin/utils/model/form/Condition/Builder/ConditionBuilder.js';
import Evaluator from '../../resources/admin/utils/model/form/Condition/Builder/Evaluator.js';
import ModifierBuilder from '../../resources/admin/utils/model/form/Condition/Builder/ModifierBuilder.js';
import validateCheckout from '../../resources/public/checkout/validateCheckout.js';

const makeClassList = () => {
    const values = new Set();

    return {
        add: (name) => values.add(name),
        remove: (name) => values.delete(name),
        has: (name) => values.has(name),
    };
};

const makeInput = ({type = 'text', value = '', checked = false, label = '', placeholder = ''}) => {
    const error = {
        innerHTML: 'stale error',
        getAttribute(name) {
            return name === 'data-fluent_cart_checkout_error' ? '' : null;
        },
    };
    const wrapper = {
        classList: makeClassList(),
        querySelector: () => error,
    };
    const labelWrapper = {
        querySelector(selector) {
            return selector === 'label' && label ? {textContent: label} : null;
        },
    };
    const input = {
        type,
        value,
        checked,
        parentElement: wrapper,
        nextElementSibling: error,
        closest: () => labelWrapper,
        getAttribute(name) {
            return name === 'placeholder' ? placeholder : null;
        },
    };

    return {input, wrapper, error};
};

describe('dynamic schema and checkout validation utilities', () => {
    it('builds and evaluates relative dynamic-form accessors with exact modifiers', () => {
        const condition = ConditionBuilder.make(
            Evaluator.make('amount', [ModifierBuilder.make(2, '+')]),
            Evaluator.make('target', []),
            '==='
        );
        const built = condition.build();

        expect(built).toEqual({
            key: {
                accessor: 'amount',
                modifiers: [{operator: '+', value: 2}],
            },
            operator: '===',
            value: {
                accessor: 'target',
            },
        });
        expect(Arr.resolvePath('form.plan.price', 'amount')).toBe('form.plan.amount');
        expect(Arr.get(
            {rows: [{money: 1001}, {money: 2002}]},
            'rows.*.money'
        )).toEqual([1001, 2002]);
        expect(Arr.sum([{money: 1001}, {money: 2002}], '*.money')).toBe(3003);

        const evaluator = new Condition('form.plan.price', {
            form: {
                plan: {
                    amount: 10,
                    target: 12,
                },
            },
        });
        expect(evaluator.evaluate(condition)).toBe(true);
        expect(evaluator.evaluate([
            built,
            {
                condition_type: 'or',
                conditions: [
                    {key: {accessor: 'target', modifiers: []}, operator: '===', value: 99},
                    {key: {accessor: 'amount', modifiers: []}, operator: '===', value: 10},
                ],
            },
        ])).toBe(true);
    });

    it('rejects missing and invalid checkout fields while clearing valid field errors', async () => {
        vi.stubGlobal('window', {
            fluentcart: {
                $t(string, value) {
                    return string
                        .replace('%1$s', value)
                        .replace('%s', value);
                },
            },
        });

        const email = makeInput({
            type: 'email',
            value: 'invalid-address',
            label: 'Billing Email *',
        });
        const terms = makeInput({
            type: 'checkbox',
            checked: false,
            placeholder: 'Terms',
        });
        const oldError = {classList: makeClassList()};
        oldError.classList.add('fluent_cart_checkout_has_errors');
        const invalidRange = {
            querySelectorAll(selector) {
                return selector === '.fluent_cart_checkout_has_errors'
                    ? [oldError]
                    : [email.input, terms.input];
            },
        };

        await expect(validateCheckout(invalidRange)).rejects.toBe('validation_failed');
        expect(oldError.classList.has('fluent_cart_checkout_has_errors')).toBe(false);
        expect(email.wrapper.classList.has('has-error')).toBe(true);
        expect(email.error.innerHTML).toBe('Billing Email is invalid, please use a valid email.');
        expect(terms.wrapper.classList.has('has-error')).toBe(true);
        expect(terms.error.innerHTML).toBe('Terms field is required.');

        const validEmail = makeInput({
            type: 'email',
            value: 'shopper@example.com',
            label: 'Billing Email *',
        });
        const acceptedTerms = makeInput({
            type: 'checkbox',
            checked: true,
            placeholder: 'Terms',
        });
        const validRange = {
            querySelectorAll(selector) {
                return selector === '.fluent_cart_checkout_has_errors'
                    ? []
                    : [validEmail.input, acceptedTerms.input];
            },
        };

        await expect(validateCheckout(validRange)).resolves.toBe(true);
        expect(validEmail.wrapper.classList.has('has-error')).toBe(false);
        expect(validEmail.error.innerHTML).toBe('');
        expect(acceptedTerms.wrapper.classList.has('has-error')).toBe(false);
        expect(acceptedTerms.error.innerHTML).toBe('');
    });

    it('KNOWN-FAILURE — checkout accepts a valid plus-address email', async () => {
        vi.stubGlobal('window', {
            fluentcart: {
                $t(string, value) {
                    return string.replace('%1$s', value).replace('%s', value);
                },
            },
        });
        const plusAddress = makeInput({
            type: 'email',
            value: 'shopper+receipts@example.com',
            label: 'Billing Email *',
        });
        const range = {
            querySelectorAll(selector) {
                return selector === '.fluent_cart_checkout_has_errors' ? [] : [plusAddress.input];
            },
        };

        let result = 'resolved';
        try {
            await validateCheckout(range);
        } catch (error) {
            result = error;
        }

        if (result === 'resolved') {
            throw new Error('KNOWN-FAILURE unexpectedly passed; remove FIX-PLAN #32 and assert acceptance normally.');
        }

        expect(result).toBe('validation_failed');
        expect(plusAddress.wrapper.classList.has('has-error')).toBe(true);
        expect(plusAddress.error.innerHTML)
            .toBe('Billing Email is invalid, please use a valid email.');
    });
});
