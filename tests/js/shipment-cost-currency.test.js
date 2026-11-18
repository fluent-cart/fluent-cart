import {describe, expect, it} from 'vitest';
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
});
