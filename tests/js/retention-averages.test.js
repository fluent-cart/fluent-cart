import {describe, expect, it} from 'vitest';
import {averageRate, expectedLifetimeMonths} from '@/Modules/Reports/Utils/retentionAverages';

describe('averageRate', () => {
    it('excludes months with no starting base from the mean', () => {
        const items = [
            {retention_rate: 0, start_subscriptions: 0},
            {retention_rate: 0, start_subscriptions: '0'},
            {retention_rate: 80, start_subscriptions: 10},
            {retention_rate: '90', start_subscriptions: '20'},
        ];

        expect(averageRate(items, 'retention_rate', 'start_subscriptions')).toBe(85);
    });
});

describe('expectedLifetimeMonths', () => {
    it('caps near-perfect retention at the 120-month horizon', () => {
        expect(expectedLifetimeMonths(1)).toEqual({months: 120, capped: true});
        expect(expectedLifetimeMonths(0.999)).toEqual({months: 120, capped: true});
        expect(expectedLifetimeMonths(0.5)).toEqual({months: 2, capped: false});
    });
});
