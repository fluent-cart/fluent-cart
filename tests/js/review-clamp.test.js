import {describe, expect, it} from 'vitest';
import {clampDecision, expandToggleState, isTruncated} from '../../resources/public/single-product/review-clamp';

/**
 * Clamping a review is only acceptable if the rest is reachable. These pin down
 * the two decisions behind that: whether a review is actually cut, and what the
 * control says about the state it is in.
 */
describe('review clamp', () => {
    describe('deciding whether anything is hidden', () => {
        it('offers to expand a review that overflows', () => {
            expect(isTruncated(200, 80)).toBe(true);
        });

        /*
         * A two-line review inside a four-line clamp is not clamped, and a
         * control that reveals nothing is worse than no control.
         */
        it('offers nothing when the review fits', () => {
            expect(isTruncated(80, 80)).toBe(false);
        });

        it('ignores a sub-pixel difference rather than calling it truncation', () => {
            expect(isTruncated(80.4, 80)).toBe(false);
            expect(isTruncated(81, 80)).toBe(false);
            expect(isTruncated(82, 80)).toBe(true);
        });
    });

    describe('what the control says', () => {
        it('offers to open while collapsed, and to close while open', () => {
            expect(expandToggleState(false).label).toBe('Read more');
            expect(expandToggleState(true).label).toBe('Show less');
        });

        it('prefers the localized strings when the page supplies them', () => {
            const trans = {read_more: 'Mehr lesen', show_less: 'Weniger anzeigen'};

            expect(expandToggleState(false, trans).label).toBe('Mehr lesen');
            expect(expandToggleState(true, trans).label).toBe('Weniger anzeigen');
        });

        it('falls back rather than labelling nothing', () => {
            expect(expandToggleState(false, {read_more: ''}).label).toBe('Read more');
            expect(expandToggleState(true, {}).label).toBe('Show less');
        });

        /* The class and aria-expanded are driven off this together, so a label
           saying one thing while the state says another cannot happen. */
        it('reports the state it was given', () => {
            expect(expandToggleState(1).expanded).toBe(true);
            expect(expandToggleState(undefined).expanded).toBe(false);
        });
    });

    describe('following the width', () => {
        /*
         * The measurement is only true of the width it was taken at. A review
         * measured across the full list fits in four lines; the same review in
         * a third of that width does not. Whichever way it goes the control has
         * to follow, or a clamped review has nothing to open it.
         */
        it('adds a control when a narrower column starts cutting the review', () => {
            expect(clampDecision({truncated: true, hasToggle: false})).toBe('add');
        });

        it('takes it away again when a wider column fits the whole thing', () => {
            expect(clampDecision({truncated: false, hasToggle: true})).toBe('remove');
        });

        it('leaves a review the reader has opened alone', () => {
            expect(clampDecision({expanded: true, truncated: true, hasToggle: true})).toBe('leave');
            expect(clampDecision({expanded: true, truncated: false, hasToggle: true})).toBe('leave');
        });

        it('does nothing when the control already matches the measurement', () => {
            expect(clampDecision({truncated: true, hasToggle: true})).toBe('none');
            expect(clampDecision({truncated: false, hasToggle: false})).toBe('none');
        });

        it('treats a missing state as a short review with no control', () => {
            expect(clampDecision()).toBe('none');
        });
    });
});
