/**
 * Which review filters the chips have selected.
 *
 * Star chips combine (5 and 4 shows both); With Photos and Verified narrow on top of
 * them. "All" clears everything. Chips outside the list's minimum rating do nothing.
 */

export const emptyChipState = () => ({ratings: [], hasMedia: false, verified: false});

/**
 * The state after a chip is clicked.
 *
 * @param {{ratings: number[], hasMedia: boolean, verified: boolean}} state
 * @param {string} value the chip's data-filter-chip
 * @param {number} minRating the list's floor, 0 for none
 * @return {{ratings: number[], hasMedia: boolean, verified: boolean}}
 */
export function toggleChip(state, value, minRating = 0) {
    if (value === 'all') {
        return emptyChipState();
    }

    if (value === 'has_media') {
        return {...state, hasMedia: !state.hasMedia};
    }

    if (value === 'verified') {
        return {...state, verified: !state.verified};
    }

    const rating = Number(value);

    if (!Number.isInteger(rating) || rating < 1 || rating > 5 || rating < minRating) {
        return state;
    }

    const ratings = state.ratings.includes(rating)
        ? state.ratings.filter((r) => r !== rating)
        : [...state.ratings, rating];

    return {...state, ratings: ratings.sort((a, b) => b - a)};
}

/**
 * Whether a chip shows as pressed. "All" is pressed when nothing else is.
 *
 * @param {{ratings: number[], hasMedia: boolean, verified: boolean}} state
 * @param {string} value
 * @return {boolean}
 */
export function isChipActive(state, value) {
    if (value === 'all') {
        return !state.ratings.length && !state.hasMedia && !state.verified;
    }

    if (value === 'has_media') {
        return state.hasMedia;
    }

    if (value === 'verified') {
        return state.verified;
    }

    return state.ratings.includes(Number(value));
}
