const PICK_WINDOW_MS = 1000;
const picks = new WeakMap();

/**
 * Makes the nice select's onChange the one save for a pick. A pick also fires
 * native change events, and each save can spend a live-rate carrier call.
 */
export function ownPickSave(niceSelect, save) {
    const element = niceSelect.el;
    niceSelect.setOnChange((value, ...rest) => {
        picks.set(element, {value: String(value ?? ''), at: Date.now()});
        return save(value, ...rest);
    });
}

/**
 * Whether a native change on this element was a nice-select pick its owner
 * already saved. Autofill and other script changes are not, so they still save.
 */
export function savedByPick(element, value) {
    const pick = element ? picks.get(element) : null;
    return !!pick && pick.value === String(value ?? '') && Date.now() - pick.at <= PICK_WINDOW_MS;
}
