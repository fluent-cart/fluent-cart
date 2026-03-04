/**
 * Vue directive to set the indeterminate property on a checkbox.
 * HTML has no `indeterminate` attribute — it's a JS-only property.
 *
 * Usage: <input type="checkbox" v-indeterminate="someBool" />
 */
export const vIndeterminate = {
    mounted(el, binding) {
        el.indeterminate = !!binding.value;
    },
    updated(el, binding) {
        el.indeterminate = !!binding.value;
    },
};
