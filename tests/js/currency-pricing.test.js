import {describe, expect, it} from 'vitest';
import {orderQuote, shopperRate, toPrice} from '../../resources/admin/utils/support/CurrencyPricing';

// The admin mirror of PriceConverter::toPrice(): the order-edit screen prices
// a new line with it before the server prices it again on save, so the two
// must agree. Same cases as PricingRulesTest / OrderEditCurrencyTest.
describe('toPrice', () => {
    const gbp = {rate: '0.790000000000', decimals: 2};

    it('converts at the rate, rounded to the smallest unit', () => {
        expect(toPrice(4980, gbp)).toBe(3934);
    });

    it('applies the adjustment, the rounding step and the charm ending', () => {
        expect(toPrice(4980, {...gbp, rounding: 'up_1', charm: 'minus_0_01', adjustment: '5'})).toBe(4199);
        expect(toPrice(4980, {...gbp, rounding: 'up_1', charm: 'minus_0_01'})).toBe(3999);
        expect(toPrice(4980, {...gbp, rounding: 'up_0_50'})).toBe(3950);
    });

    it('does not round an exact step up', () => {
        expect(toPrice(3900, {rate: 1, decimals: 2, rounding: 'up_1'})).toBe(3900);
    });

    it('rounds a zero-decimal currency to whole units and skips charm', () => {
        expect(toPrice(740023, {rate: 1, decimals: 0, rounding: 'up_0_50'})).toBe(740100);
        expect(toPrice(740023, {rate: 1, decimals: 0, rounding: 'up_10', charm: 'minus_0_01'})).toBe(741000);
    });
});

describe('orderQuote', () => {
    const fx = {currency: 'GBP', base_currency: 'USD', rate: '0.79', source: 'manual'};

    it('uses the snapshot of an order in another currency', () => {
        expect(orderQuote({config: {fx}})).toBe(fx);
    });

    it('does nothing for a base-currency order or an unknown rate', () => {
        expect(orderQuote({config: {fx: {...fx, currency: 'USD'}}})).toBeNull();
        expect(orderQuote({config: {fx: {...fx, source: 'legacy_unknown'}}})).toBeNull();
        expect(orderQuote({config: {}})).toBeNull();
        expect(orderQuote(null)).toBeNull();
    });
});

// The settings preview prices 100 of the base currency at the rate shoppers
// get — ExchangeRateService::current(): the store's margin on a feed rate
// while a feed is selected, none on a manual rate or in manual mode.
describe('shopperRate', () => {
    const feedRow = {rate: '0.790000000000', source: 'ecb'};

    it('adds the store margin to a feed rate while a feed is selected', () => {
        expect(shopperRate(feedRow, {rate_provider: 'ecb', rate_margin: 2})).toBeCloseTo(0.8058, 10);
    });

    it('never marks up a manual rate, or any rate in manual mode', () => {
        expect(shopperRate({rate: '0.79', source: 'manual'}, {rate_provider: 'ecb', rate_margin: 2})).toBe(0.79);
        expect(shopperRate(feedRow, {rate_provider: 'manual', rate_margin: 2})).toBe(0.79);
    });

    it('is null without a rate', () => {
        expect(shopperRate(null, {rate_provider: 'ecb', rate_margin: 2})).toBeNull();
    });
});
