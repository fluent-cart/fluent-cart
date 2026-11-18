// Arrow keys, Home and End move the pick within a row, as in a radio group; right-to-left flips left and right.
export const nextRadioIndex = (event, index, count) => {
    if (event.key === 'Home') {
        return 0;
    }
    if (event.key === 'End') {
        return count - 1;
    }
    let step = {ArrowDown: 1, ArrowUp: -1, ArrowRight: 1, ArrowLeft: -1}[event.key];
    if (!step) {
        return null;
    }
    if ((event.key === 'ArrowRight' || event.key === 'ArrowLeft') && getComputedStyle(event.currentTarget).direction === 'rtl') {
        step = -step;
    }
    return (index + step + count) % count;
};

// Handles a key on one option of a radio group: moves focus to the next option and returns its index, or null.
export const moveRadioFocusWithKeys = (event, index, count) => {
    const next = nextRadioIndex(event, index, count);
    if (next !== null) {
        event.preventDefault();
        event.currentTarget.closest('[role="radiogroup"]').querySelectorAll('[role="radio"]')[next].focus();
    }
    return next;
};
