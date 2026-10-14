import {describe, expect, it, vi} from 'vitest';

/**
 * The rate-history dialog (GET settings/multi-currency/rates) must only show
 * the rates of the currency in its title. A slower answer for a currency
 * opened earlier must not land in, or stop the spinner of, the current one.
 */

import {rateChange, useRateHistory} from '@/Modules/Settings/MultiCurrency/rateHistory';

const deferred = () => {
    let resolve, reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return {promise, resolve, reject};
};

describe('rate history dialog', () => {
    it('shows the rates of the opened currency', async () => {
        const fetchRates = vi.fn().mockResolvedValue({rates: [{rate: '0.92'}]});
        const {history, showHistory} = useRateHistory(fetchRates);

        await showHistory('EUR');

        expect(fetchRates).toHaveBeenCalledWith('EUR');
        expect(history.value).toMatchObject({currency: 'EUR', rates: [{rate: '0.92'}], loading: false, visible: true});
    });

    it('ignores a slower answer for a currency opened earlier', async () => {
        const eur = deferred();
        const gbp = deferred();
        const fetchRates = vi.fn(code => (code === 'EUR' ? eur.promise : gbp.promise));
        const {history, showHistory} = useRateHistory(fetchRates);

        const eurDone = showHistory('EUR');
        const gbpDone = showHistory('GBP');

        eur.resolve({rates: [{rate: '0.92'}]});
        await eurDone;

        expect(history.value).toMatchObject({currency: 'GBP', rates: [], loading: true});

        gbp.resolve({rates: [{rate: '0.79'}]});
        await gbpDone;

        expect(history.value).toMatchObject({currency: 'GBP', rates: [{rate: '0.79'}], loading: false});
    });

    it('does not report the error of a superseded request', async () => {
        const eur = deferred();
        const onError = vi.fn();
        const fetchRates = vi.fn(code => (code === 'EUR' ? eur.promise : new Promise(() => {})));
        const {history, showHistory} = useRateHistory(fetchRates, onError);

        const eurDone = showHistory('EUR');
        showHistory('GBP');

        eur.reject({message: 'timeout'});
        await eurDone;

        expect(onError).not.toHaveBeenCalled();
        expect(history.value).toMatchObject({currency: 'GBP', loading: true});
    });
});

describe('rate history change column', () => {
    it('compares each rate with the one before it', () => {
        expect(rateChange('0.81', '0.79')).toEqual({direction: 'up', percent: '+2.53%'});
        expect(rateChange('4', '123')).toEqual({direction: 'down', percent: '-96.75%'});
    });

    it('marks an unchanged rate and keeps tiny moves visible', () => {
        expect(rateChange('123.000000000000', '123')).toEqual({direction: 'same', percent: '0%'});
        expect(rateChange('1.0001', '1')).toEqual({direction: 'up', percent: '+0.01%'});
        expect(rateChange('1.00001', '1')).toEqual({direction: 'up', percent: '+<0.01%'});
    });

    it('has nothing to compare for the first rate or an unusable one', () => {
        expect(rateChange('0.79', undefined)).toBeNull();
        expect(rateChange('0.79', '0')).toBeNull();
        expect(rateChange('abc', '0.79')).toBeNull();
    });
});
