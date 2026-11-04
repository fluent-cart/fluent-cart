import {describe, expect, it} from 'vitest';
import {readFileSync} from 'node:fs';
import {fileURLToPath, URL} from 'node:url';

import {shipmentCostCurrency} from '@/Modules/Orders/Components/shipmentCostCurrency';

describe('the Create shipment cost currency', () => {
    it('is the order currency for a hand-typed cost', () => {
        expect(shipmentCostCurrency({orderCurrency: 'eur'})).toBe('EUR');
    });

    it('is unknown for a label carrier until a rate is picked', () => {
        expect(shipmentCostCurrency({buysLabel: true, orderCurrency: 'EUR'})).toBe('');
    });

    it('follows the picked rate', () => {
        expect(shipmentCostCurrency({rateCurrency: 'usd', buysLabel: true, orderCurrency: 'EUR'})).toBe('USD');
        expect(shipmentCostCurrency({rateCurrency: 'EUR', orderCurrency: 'EUR'})).toBe('EUR');
    });

    it('is bound to PriceInput, which hides its sign while unknown', () => {
        const form = readFileSync(fileURLToPath(new URL('../../resources/admin/Modules/Orders/Components/ShipmentForm.vue', import.meta.url)), 'utf8');
        const input = readFileSync(fileURLToPath(new URL('../../resources/admin/Bits/Components/Inputs/PriceInput.vue', import.meta.url)), 'utf8');

        expect(form).toMatch(/:currency="costCurrency"\s+:show-currency-sign="!!costCurrency"/);
        expect(input).toMatch(/<template v-if="showCurrencySign" #prefix>/);
    });
});
