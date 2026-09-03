import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {
    resolveGroupPriceCommit,
    resolveGroupPriceBlur,
    createGroupPriceCommitTracker,
} from '@/Modules/Products/utils/groupPriceCommit';

/**
 * AdvancedVariationGroupedTable's group-price quick-set input used to apply
 * only on PriceInput's `change` event. PriceInput keeps its modelValue buffer
 * current on every keystroke via `update:modelValue`, and its own blur
 * handler emits `change` only when the normalized value still differs from
 * that already-current modelValue — so once a merchant finished typing and
 * tabbed away, the value never differed and `change` silently never fired.
 * The header showed the new price; the child variations never got it.
 *
 * resolveGroupPriceCommit validates the buffered value; resolveGroupPriceBlur
 * decides whether a blur should apply it, given a one-shot value armed by an
 * immediately preceding Enter. createGroupPriceCommitTracker is the stateful
 * wrapper AdvancedVariationGroupedTable.vue actually uses — the tests below
 * exercise it as a *sequence* of real onEnter()/onBlur() calls (the same
 * calls the component's @keyup.enter/@blur handlers make) rather than
 * hand-supplying a pendingEnterValue to the pure functions in isolation,
 * since the original failure was specifically about Vue event *ordering*.
 *
 * @vue/test-utils is not a dependency in this repo and the vitest environment
 * is `node`, so nothing here mounts AdvancedVariationGroupedTable.vue as a
 * real component (see tests/js/license-renew-gating.test.js for the same
 * constraint elsewhere in this suite). The tracker sequence tests below cover
 * the event *lifecycle* logic; the template-wiring test at the bottom of this
 * file is a source-contract check that the component actually calls this
 * tracker from the events it's supposed to.
 */
describe('resolveGroupPriceCommit', () => {
    it('applies a valid price', () => {
        expect(resolveGroupPriceCommit(15000)).toBe(15000);
    });

    it('does not apply an empty value', () => {
        expect(resolveGroupPriceCommit('')).toBeNull();
    });

    it('does not apply a null or undefined value', () => {
        expect(resolveGroupPriceCommit(null)).toBeNull();
        expect(resolveGroupPriceCommit(undefined)).toBeNull();
    });

    it('does not apply a non-numeric value', () => {
        expect(resolveGroupPriceCommit('abc')).toBeNull();
    });

    it('does not apply a non-finite value', () => {
        expect(resolveGroupPriceCommit('Infinity')).toBeNull();
        expect(resolveGroupPriceCommit(NaN)).toBeNull();
    });

    it('treats a numeric string the same as a number', () => {
        // groupPrices buffer may hold either shape depending on the last event.
        expect(resolveGroupPriceCommit('15000')).toBe(15000);
    });
});

describe('resolveGroupPriceBlur', () => {
    it('applies a valid price when no Enter is pending', () => {
        expect(resolveGroupPriceBlur(15000, undefined)).toBe(15000);
    });

    it('does not apply an invalid/empty buffered value', () => {
        expect(resolveGroupPriceBlur(null, undefined)).toBeNull();
        expect(resolveGroupPriceBlur(null, 15000)).toBeNull();
    });

    it('suppresses the trailing blur of the Enter that just committed the same value', () => {
        expect(resolveGroupPriceBlur(20000, 20000)).toBeNull();
    });

    it('applies a value that changed since the pending Enter commit', () => {
        expect(resolveGroupPriceBlur(25000, 20000)).toBe(25000);
    });

    it('re-applies the same price on a later blur with no pending Enter (different selection or edited child)', () => {
        expect(resolveGroupPriceBlur(20000, undefined)).toBe(20000);
    });
});

/**
 * createGroupPriceCommitTracker: the real, stateful event lifecycle, called
 * the same way the component calls it — onEnter() from @keyup.enter, onBlur()
 * from @blur — as a sequence, per the numbered scenarios required for this fix.
 */
describe('createGroupPriceCommitTracker', () => {
    const GROUP_A = 'termA';
    const GROUP_B = 'termB';

    // 1. Blur-only commit applies the group price.
    it('applies the group price on blur alone, with no preceding Enter', () => {
        const tracker = createGroupPriceCommitTracker();
        expect(tracker.onBlur(GROUP_A, 15000)).toBe(15000);
    });

    // 2. Enter-only commit applies the group price.
    it('applies the group price on Enter alone', () => {
        const tracker = createGroupPriceCommitTracker();
        expect(tracker.onEnter(GROUP_A, 15000)).toBe(15000);
    });

    // 3. Enter followed immediately by blur applies exactly once.
    it('applies exactly once when Enter is immediately followed by blur with the same buffered value', () => {
        const tracker = createGroupPriceCommitTracker();

        const fromEnter = tracker.onEnter(GROUP_A, 20000);
        const fromBlur = tracker.onBlur(GROUP_A, 20000);

        expect(fromEnter).toBe(20000);
        expect(fromBlur).toBeNull();
    });

    // 4. Blur followed by a later intentional blur with the same value can
    // commit again when relevant state changed (here: the merchant simply
    // triggers blur twice — plain blur never arms suppression, only Enter
    // does, so a second blur is never a "trailing blur" of the first).
    it('commits again on a later blur with the same value when nothing armed suppression', () => {
        const tracker = createGroupPriceCommitTracker();

        const first = tracker.onBlur(GROUP_A, 15000);
        const second = tracker.onBlur(GROUP_A, 15000);

        expect(first).toBe(15000);
        expect(second).toBe(15000);
    });

    // 5. The same value can be reapplied after child selection changes.
    it('reapplies the same value via Enter after the selection changes (selection is external state)', () => {
        const tracker = createGroupPriceCommitTracker();

        tracker.onEnter(GROUP_A, 10000);
        tracker.onBlur(GROUP_A, 10000); // trailing blur of that Enter — consumed, suppressed

        // Merchant selects different children (no tracker interaction — the
        // tracker has no concept of selection at all), then re-commits $100.
        const reapplied = tracker.onEnter(GROUP_A, 10000);

        expect(reapplied).toBe(10000);
    });

    // 6. The same value can be reapplied after a child price changes.
    it('reapplies the same value via blur after a child price changes independently', () => {
        const tracker = createGroupPriceCommitTracker();

        tracker.onEnter(GROUP_A, 10000);
        tracker.onBlur(GROUP_A, 10000); // consumed

        // Merchant edits one child's price inline elsewhere in the table —
        // again, nothing the tracker tracks — then blurs the group field
        // with the same $100 still buffered.
        const reapplied = tracker.onBlur(GROUP_A, 10000);

        expect(reapplied).toBe(10000);
    });

    // 7. Different values commit normally.
    it('commits a changed value normally after an Enter/blur pair for a different value', () => {
        const tracker = createGroupPriceCommitTracker();

        tracker.onEnter(GROUP_A, 10000);
        tracker.onBlur(GROUP_A, 10000); // consumed, suppressed

        const changed = tracker.onEnter(GROUP_A, 20000);
        expect(changed).toBe(20000);
    });

    it('applies a value that changed between Enter and its own trailing blur', () => {
        const tracker = createGroupPriceCommitTracker();

        tracker.onEnter(GROUP_A, 10000);
        // Merchant kept typing before tabbing away — buffer is now different.
        const onBlurResult = tracker.onBlur(GROUP_A, 15000);

        expect(onBlurResult).toBe(15000);
    });

    // 8. Empty input is rejected.
    it('rejects an empty buffered value on both Enter and blur', () => {
        const tracker = createGroupPriceCommitTracker();

        expect(tracker.onEnter(GROUP_A, '')).toBeNull();
        expect(tracker.onBlur(GROUP_A, '')).toBeNull();
    });

    // 9. Invalid and non-finite values are rejected.
    it('rejects invalid and non-finite buffered values on both Enter and blur', () => {
        const tracker = createGroupPriceCommitTracker();

        expect(tracker.onEnter(GROUP_A, 'abc')).toBeNull();
        expect(tracker.onBlur(GROUP_A, 'abc')).toBeNull();
        expect(tracker.onEnter(GROUP_A, 'Infinity')).toBeNull();
        expect(tracker.onBlur(GROUP_A, NaN)).toBeNull();
    });

    // 10. Discard/reset removes any temporary deduplication state.
    it('clears pending state on reset, so the next blur is not treated as a trailing blur', () => {
        const tracker = createGroupPriceCommitTracker();

        tracker.onEnter(GROUP_A, 10000);
        expect(tracker.hasPending(GROUP_A)).toBe(true);

        tracker.reset(); // discard

        expect(tracker.hasPending(GROUP_A)).toBe(false);
        expect(tracker.onBlur(GROUP_A, 10000)).toBe(10000);
    });

    it('reset(groupKey) clears only that group, leaving other groups armed', () => {
        const tracker = createGroupPriceCommitTracker();

        tracker.onEnter(GROUP_A, 10000);
        tracker.onEnter(GROUP_B, 20000);

        tracker.reset(GROUP_A);

        expect(tracker.hasPending(GROUP_A)).toBe(false);
        expect(tracker.hasPending(GROUP_B)).toBe(true);
    });

    // 11. Other groups are untouched by one group's Enter/blur lifecycle.
    it('keeps each group independent — one group arming suppression does not affect another', () => {
        const tracker = createGroupPriceCommitTracker();

        tracker.onEnter(GROUP_A, 10000); // arms only GROUP_A

        // A different group's blur with the same numeric value must not be
        // suppressed by GROUP_A's pending state.
        expect(tracker.onBlur(GROUP_B, 10000)).toBe(10000);

        // GROUP_A's own trailing blur is still correctly suppressed.
        expect(tracker.onBlur(GROUP_A, 10000)).toBeNull();
    });
});

/**
 * Source-contract check on the actual template wiring, complementing the
 * tracker-sequence tests above: those prove the lifecycle logic is correct in
 * isolation, this proves AdvancedVariationGroupedTable.vue's group-price
 * PriceInput actually calls it from the right DOM events. This is what would
 * have caught the original bug at the wiring level — the previous
 * implementation's logic (or lack of it) was fine in isolation; the bug was
 * that blur never called anything that read the live buffer.
 */
describe('AdvancedVariationGroupedTable.vue group-price input wiring', () => {
    const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
    const source = fs.readFileSync(
        path.join(repoRoot, 'resources/admin/Modules/Products/parts/AdvancedVariationGroupedTable.vue'),
        'utf8'
    );

    const groupPriceInputMatch = source.match(
        /<PriceInput[\s\S]*?class="fct-adv-group-price-input"[\s\S]*?\/>/
    );

    it('renders the group price quick-set input', () => {
        expect(groupPriceInputMatch, 'the group price PriceInput block must exist').not.toBeNull();
    });

    const groupPriceInputBlock = groupPriceInputMatch ? groupPriceInputMatch[0] : '';

    it('commits on blur via the tracker-backed handler', () => {
        expect(groupPriceInputBlock).toMatch(/@blur="onGroupPriceBlur\(group\)"/);
    });

    it('commits on Enter via the tracker-backed handler', () => {
        expect(groupPriceInputBlock).toMatch(/@keyup\.enter="commitGroupPriceOnEnter\(group\)"/);
    });

    it('does not rely on PriceInput\'s `change` event — the mechanism that caused the original bug', () => {
        expect(groupPriceInputBlock).not.toMatch(/@change=/);
    });

    it('onGroupPriceBlur delegates to the tracker-backed commit function', () => {
        const blurFn = source.match(/const onGroupPriceBlur = \(group\) => \{[\s\S]*?\n\};/);
        expect(blurFn, 'onGroupPriceBlur must exist').not.toBeNull();
        expect(blurFn[0]).toMatch(/commitGroupPriceOnBlur\(group\)/);
    });

    it('discard resets the tracker so no armed state survives a discard', () => {
        const discardWatch = source.match(/watch\(\(\) => props\.productEditModel\?\.data\?\.discardKey[\s\S]*?\}\);/);
        expect(discardWatch, 'the discardKey watch must exist').not.toBeNull();
        expect(discardWatch[0]).toMatch(/groupPriceTracker\.reset\(\)/);
    });
});
