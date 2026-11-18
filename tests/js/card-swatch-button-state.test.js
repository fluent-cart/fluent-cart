import {describe, expect, it} from 'vitest';
import {cardButtonState} from '../../resources/public/product-card/product-attribute-swatches.js';

/**
 * The product card button follows the variant its swatch rows pin: add to cart,
 * Buy Now for a subscription, or a disabled reason.
 */

const config = {
    can_add: true,
    can_buy: true,
    labels: {add: 'Add To Cart', buy: 'Buy Now', out_of_stock: 'Out of stock', unavailable: 'Not available', choose: 'Select options'},
};
const oneTime = {id: 11, in_stock: true, subscription: false};

describe('cardButtonState', () => {
    it('adds a one-time variant to the cart', () => {
        expect(cardButtonState(oneTime, config)).toEqual({mode: 'add', label: 'Add To Cart'});
    });

    it('buys a subscription variant through checkout', () => {
        expect(cardButtonState({...oneTime, subscription: true}, config)).toEqual({mode: 'buy', label: 'Buy Now'});
    });

    it('disables the button for a sold-out variant', () => {
        expect(cardButtonState({...oneTime, in_stock: false}, config)).toEqual({mode: 'off', label: 'Out of stock'});
    });

    it('disables the button for a combination the product does not sell', () => {
        expect(cardButtonState(null, config)).toEqual({mode: 'off', label: 'Not available'});
    });

    it('waits while a row has no pick', () => {
        expect(cardButtonState(oneTime, config, false)).toEqual({mode: 'off', label: 'Select options'});
    });

    it('never offers a purchase the store hides', () => {
        const catalog = {...config, can_add: false, can_buy: false};
        expect(cardButtonState(oneTime, catalog).mode).toBe('off');
        expect(cardButtonState({...oneTime, subscription: true}, catalog).mode).toBe('off');
    });
});
