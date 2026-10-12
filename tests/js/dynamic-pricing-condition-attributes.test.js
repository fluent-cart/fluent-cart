import {describe, expect, it, vi} from 'vitest';

// A top-level group, and Checkout Fields holding the settings groups.
vi.mock('@/utils/Config/AppConfig', () => ({
    default: {
        get(key, defaultValue) {
            if (key !== 'dynamic_pricing_condition_attributes') {
                return defaultValue;
            }

            return [
                {
                    label: 'Cart',
                    value: 'cart',
                    children: [{label: 'Quantity', value: 'quantity', input_type: 'number'}],
                },
                {
                    label: 'Checkout Fields',
                    value: 'checkout_fields',
                    children: [
                        {
                            label: 'Basic Info',
                            value: 'checkout_basic_info',
                            children: [{label: 'Email', value: 'checkout_email', input_type: 'text'}],
                        },
                        {
                            label: 'Billing Address',
                            value: 'checkout_billing_address',
                            children: [{label: 'City', value: 'checkout_billing_city', input_type: 'text'}],
                        },
                    ],
                },
            ];
        },
    },
}));

const {getAttribute, getAttributeLabel} = await import('@/Modules/DynamicPricing/conditionAttributes');

describe('dynamic pricing condition attributes', () => {
    it('finds a top-level attribute with its group label', () => {
        expect(getAttribute('quantity')).toMatchObject({value: 'quantity', group: 'Cart'});
    });

    it('finds an attribute nested in a group of a group, labelled by its own group', () => {
        expect(getAttribute('checkout_billing_city')).toMatchObject({value: 'checkout_billing_city', group: 'Billing Address'});
        expect(getAttributeLabel('checkout_email')).toBe('Email');
    });

    it('does not treat a nested group as an attribute', () => {
        expect(getAttribute('checkout_billing_address')).toBeNull();
        expect(getAttribute('checkout_fields')).toBeNull();
    });
});
