import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../..');
const files = [
    'resources/public/customer-profile/Vue/SingleOrder.vue',
    'resources/public/customer-profile/Vue/parts/CustomerTaxBreakdownBox.vue',
];

globalThis.window = {
    fluentcart_customer_profile_vars: {
        shop: {decimal_separator: 'comma'},
    },
};
globalThis.translateNumber = (value) => value;

const extractFormatter = (file) => {
    const source = fs.readFileSync(path.join(root, file), 'utf8');
    const signature = 'formatOrderAmount(amount, withCurrency = true, hideEmpty = false)';
    const start = source.indexOf(signature);
    const openBrace = source.indexOf('{', start);
    let depth = 0;
    let end = openBrace;

    for (; end < source.length; end++) {
        if (source[end] === '{') depth++;
        if (source[end] === '}' && --depth === 0) break;
    }

    const methodSource = source.slice(start, end + 1);
    return new Function(`return function ${methodSource}`)();
};

const cases = [
    [0, '0,00 €'],
    [400, '4,00 €'],
    [1800, '18,00 €'],
    [940, '9,40 €'],
    [4680, '46,80 €'],
];

describe('customer dashboard currency formatting', () => {
    for (const file of files) {
        describe(path.basename(file), () => {
            const formatOrderAmount = extractFormatter(file);

            for (const [amount, expected] of cases) {
                it(`keeps the currency decimals for ${amount} cents`, () => {
                    const context = {
                        order: {currency: 'EUR'},
                        formatNumber: () => expected,
                    };

                    expect(formatOrderAmount.call(context, amount)).toBe(expected);
                });
            }
        });
    }
});
