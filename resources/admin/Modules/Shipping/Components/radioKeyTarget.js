/**
 * Index a radiogroup key moves to, or null for keys the group doesn't handle.
 *
 * @param {string} key KeyboardEvent.key
 * @param {number} index Index of the focused radio
 * @param {number} count Number of radios
 * @return {number|null}
 */
export const radioKeyTarget = (key, index, count) => {
    if (!count) {
        return null;
    }

    switch (key) {
        case 'ArrowDown':
        case 'ArrowRight':
            return (index + 1) % count;
        case 'ArrowUp':
        case 'ArrowLeft':
            return (index - 1 + count) % count;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
};
