/**
 * Validate the grouped-variant quick-set price buffer before it is applied
 * to the group's children.
 *
 * `price` is read straight from the group header's write-only buffer
 * (groupPrices[termId] in AdvancedVariationGroupedTable.vue), which PriceInput
 * keeps current via `update:modelValue` on every keystroke — independent of
 * whether PriceInput also emits `change`. PriceInput suppresses `change` on
 * blur whenever its normalized value already equals the modelValue it last
 * emitted during typing, which is the common case once the merchant stops
 * typing and tabs away. Relying on `change` therefore silently drops the
 * group-price commit; reading the buffer directly on blur/Enter does not.
 *
 * Both `price` and the returned value are CENTS, matching PriceInput's
 * modelValue contract. Negative values are not rejected here — PriceInput's
 * own keydown filter only allows digits/dot/comma (no minus sign), and
 * AdvancedVariationTable.vue's onGroupPriceApply independently rejects a
 * negative dollarPrice before touching any variant. Both are pre-existing,
 * unmodified guards this fix does not duplicate.
 *
 * @param {string|number|null|undefined} price - current buffered value
 * @returns {number|null} cents value to apply, or null to skip (empty or
 *   invalid)
 */
export function resolveGroupPriceCommit(price) {
    if (price === '' || price === null || price === undefined) {
        return null;
    }

    const numericPrice = Number(price);
    if (!Number.isFinite(numericPrice)) {
        return null;
    }

    return numericPrice;
}

/**
 * Decide whether a blur should apply the group price, given a value armed by
 * an Enter keypress on the same group that has not yet had its trailing blur.
 *
 * This is a ONE-SHOT check, not a session-wide "already applied this value"
 * guard: pressing Enter commits and arms `pendingEnterValue` for exactly the
 * next blur on that field. If that immediate blur still shows the same value,
 * it is the trailing blur of the same edit and must not reapply. Any other
 * blur — no preceding Enter, or the buffer changed since the Enter — applies
 * normally. In particular, applying $X, changing which children are
 * selected or editing a child directly, and then applying $X again later,
 * must go through: none of those later blurs have a pending value that
 * matches, because the caller (createGroupPriceCommitTracker below) consumes
 * the pending value the moment it is checked, whether or not this function
 * suppresses.
 *
 * @param {number|null} numericPrice - current buffered value, already
 *   resolved via resolveGroupPriceCommit (null means empty/invalid)
 * @param {number|undefined} pendingEnterValue - value committed by the most
 *   recent still-unconsumed Enter on this group; undefined if there is none
 * @returns {number|null} cents value to apply, or null to skip
 */
export function resolveGroupPriceBlur(numericPrice, pendingEnterValue) {
    if (numericPrice === null) {
        return null;
    }

    if (pendingEnterValue !== undefined && pendingEnterValue === numericPrice) {
        return null;
    }

    return numericPrice;
}

/**
 * Stateful controller for the Enter-arms / blur-consumes lifecycle, keyed by
 * group (termId). Owns the "pending" bookkeeping so both
 * AdvancedVariationGroupedTable.vue and tests exercise the same real event
 * sequence — onEnter() then onBlur() — rather than each test hand-supplying a
 * `pendingEnterValue` to the pure functions above in isolation. This is what
 * makes a same-value reapplication after a selection or child-price change
 * provable as a *sequence*: the pending value armed by one Enter is consumed
 * by the very next onBlur() call for that group, one time only, and nothing
 * else re-arms it.
 *
 * One tracker instance per AdvancedVariationGroupedTable.vue component
 * instance; call reset() on discard so a discarded edit's armed state
 * doesn't leak into whatever the merchant does next.
 */
export function createGroupPriceCommitTracker() {
    let pending = {};

    return {
        /**
         * @param {string|number} groupKey - group.termId
         * @param {string|number|null|undefined} price - current buffer value
         * @returns {number|null} cents value to apply, or null to skip
         */
        onEnter(groupKey, price) {
            const numericPrice = resolveGroupPriceCommit(price);
            if (numericPrice === null) {
                return null;
            }

            pending = {...pending, [groupKey]: numericPrice};
            return numericPrice;
        },

        /**
         * @param {string|number} groupKey - group.termId
         * @param {string|number|null|undefined} price - current buffer value
         * @returns {number|null} cents value to apply, or null to skip
         */
        onBlur(groupKey, price) {
            const numericPrice = resolveGroupPriceCommit(price);
            const pendingValue = pending[groupKey];

            if (pendingValue !== undefined) {
                const next = {...pending};
                delete next[groupKey];
                pending = next;
            }

            return resolveGroupPriceBlur(numericPrice, pendingValue);
        },

        /**
         * Clear pending state. Omit groupKey to reset every group (discard).
         * @param {string|number} [groupKey]
         */
        reset(groupKey) {
            if (groupKey === undefined) {
                pending = {};
                return;
            }
            const next = {...pending};
            delete next[groupKey];
            pending = next;
        },

        /** @param {string|number} groupKey */
        hasPending(groupKey) {
            return pending[groupKey] !== undefined;
        },
    };
}
