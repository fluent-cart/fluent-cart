import {describe, expect, it, vi} from 'vitest';
import {reactive, ref} from 'vue';

/**
 * The report line-chart granularity selector (Auto / Daily / Monthly / Yearly)
 * is built by useGroupKeyOptions(). The Auto option's label must show the
 * granularity the SERVER's auto heuristic (ReportHelper::defineGroupKey) will
 * pick for the current date range — <= 91 days: Daily, <= 365: Monthly,
 * otherwise Yearly — computed client-side from reportFilter.data.dateRange.
 *
 * Two regressions are pinned here:
 * - The label must NOT echo a manual Daily/Monthly/Yearly selection (the
 *   original bug: picking Monthly flipped the option to "Auto (Monthly)"
 *   even on a 30-day range where Auto resolves to Daily).
 * - groupKeys must be reactive: changing the date range must update the Auto
 *   label without a page reload (a plain array evaluated once at setup
 *   captured the first label forever).
 */

vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) =>
        args.reduce((out, arg, i) => out.replace(`%${i + 1}$s`, String(arg)), text),
}));

import useGroupKeyOptions from '@/Modules/Reports/Utils/useGroupKeyOptions';

const filterWithRange = (start, end) => reactive({data: {dateRange: [start, end]}});

const autoOption = (groupKeys) => groupKeys.value.find((o) => o.value === 'default');

describe('useGroupKeyOptions option list', () => {
    it('offers Auto, Daily, Monthly and Yearly', () => {
        const {groupKeys} = useGroupKeyOptions(
            filterWithRange('2026-07-21 00:00:00', '2026-08-20 23:59:59'),
            ref('default')
        );

        expect(groupKeys.value.map((o) => o.value)).toEqual([
            'default',
            'daily',
            'monthly',
            'yearly',
        ]);
    });
});

describe('useGroupKeyOptions Auto label', () => {
    it('shows Daily for a range of up to 91 days', () => {
        const {groupKeys} = useGroupKeyOptions(
            filterWithRange('2026-07-21 00:00:00', '2026-08-20 23:59:59'),
            ref('default')
        );

        expect(autoOption(groupKeys).label).toBe('Auto (Daily)');
    });

    it('shows Monthly for a range of up to 365 days', () => {
        const {groupKeys} = useGroupKeyOptions(
            filterWithRange('2026-01-01 00:00:00', '2026-06-30 23:59:59'),
            ref('default')
        );

        expect(autoOption(groupKeys).label).toBe('Auto (Monthly)');
    });

    it('shows Yearly for a range beyond 365 days', () => {
        const {groupKeys} = useGroupKeyOptions(
            filterWithRange('2024-01-01 00:00:00', '2026-08-20 23:59:59'),
            ref('default')
        );

        expect(autoOption(groupKeys).label).toBe('Auto (Yearly)');
    });

    it('matches the server day-count at the 91-day boundary despite the 23:59:59 end time', () => {
        // PHP's DateInterval->days truncates: startOfDay -> endOfDay across
        // exactly 91 calendar days is 91, still Daily. Math.ceil() over the
        // same strings would call it 92 and disagree with the server.
        const {groupKeys} = useGroupKeyOptions(
            filterWithRange('2026-01-01 00:00:00', '2026-04-02 23:59:59'),
            ref('default')
        );

        expect(autoOption(groupKeys).label).toBe('Auto (Daily)');
    });

    it('shows Monthly one day past the boundary', () => {
        const {groupKeys} = useGroupKeyOptions(
            filterWithRange('2026-01-01 00:00:00', '2026-04-03 23:59:59'),
            ref('default')
        );

        expect(autoOption(groupKeys).label).toBe('Auto (Monthly)');
    });

    it('does not echo a manual selection', () => {
        const selected = ref('monthly');
        const {groupKeys} = useGroupKeyOptions(
            filterWithRange('2026-07-21 00:00:00', '2026-08-20 23:59:59'),
            selected
        );

        expect(
            autoOption(groupKeys).label,
            'a manual Monthly selection must not relabel the Auto option'
        ).toBe('Auto');

        selected.value = 'default';
        expect(autoOption(groupKeys).label).toBe('Auto (Daily)');
    });

    it('updates when the date range changes, without a reload', () => {
        const reportFilter = filterWithRange('2026-07-21 00:00:00', '2026-08-20 23:59:59');
        const {groupKeys} = useGroupKeyOptions(reportFilter, ref('default'));

        expect(autoOption(groupKeys).label).toBe('Auto (Daily)');

        reportFilter.data.dateRange = ['2024-01-01 00:00:00', '2026-08-20 23:59:59'];

        expect(
            autoOption(groupKeys).label,
            'groupKeys must be a computed — a plain array captures the first label forever'
        ).toBe('Auto (Yearly)');
    });

    it('falls back to a plain Auto when the range is not yet known', () => {
        const {groupKeys} = useGroupKeyOptions(reactive({data: {dateRange: []}}), ref('default'));

        expect(autoOption(groupKeys).label).toBe('Auto');
    });
});
