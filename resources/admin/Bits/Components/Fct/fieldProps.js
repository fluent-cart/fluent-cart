import {computed, useAttrs} from 'vue';

// Label, tooltip, required mark, help text and validation errors every fct-* input can show.
export const fieldProps = {
    id: {type: String, default: undefined},
    label: {type: String, default: ''},
    tooltip: {type: String, default: ''},
    required: {type: Boolean, default: false},
    help: {type: String, default: ''},
    validationErrors: {type: Object, default: undefined},
    fieldKey: {type: String, default: ''},
};

// data-* attributes are plain HTML hooks (e.g. for tests and scripts), so they pass through.
export function useDataAttrs() {
    const attrs = useAttrs();
    return computed(() => Object.fromEntries(Object.entries(attrs).filter(([key]) => key.startsWith('data-'))));
}
