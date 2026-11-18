/* eslint-disable vue/one-component-per-file */
import {defineComponent, Fragment, h, inject, provide} from 'vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';

export const SORTABLE_KEY = Symbol('fct-sortable');
const ITEM_KEY = Symbol('fct-sortable-item');

// The first real element in rendered slot content, so each item stays the
// single DOM child sortablejs moves (no fragment anchors around it).
const firstElement = (nodes = []) => {
    for (const node of nodes) {
        if (node.type === Fragment) {
            const inner = firstElement(node.children);
            if (inner) return inner;
        } else if (typeof node.type !== 'symbol') {
            return node;
        }
    }
    return null;
};

// Renderless: gives its handle the item and index, renders the screen's own row.
export const SortableItem = defineComponent({
    name: 'FctSortableItem',
    props: {
        item: {type: null, required: true},
        index: {type: Number, required: true}
    },
    setup(props, {slots}) {
        provide(ITEM_KEY, props);
        return () => firstElement(slots.default?.());
    }
});

export const SortHandle = defineComponent({
    name: 'FctSortHandle',
    setup() {
        const list = inject(SORTABLE_KEY);
        const entry = inject(ITEM_KEY);
        const steps = {ArrowUp: -1, ArrowDown: 1};

        const onKeydown = (event) => {
            // Rows are often clickable; keys used on the handle must not reach them.
            if (['ArrowUp', 'ArrowDown', 'Enter', ' '].includes(event.key)) {
                event.stopPropagation();
            }
            if (steps[event.key]) {
                event.preventDefault();
                list.move(entry.index, steps[event.key]);
            }
        };

        return () => h('button', {
            type: 'button',
            class: 'fct-sortable-list__handle',
            'data-fct-sort-key': String(list.keyOf(entry.item, entry.index)),
            'aria-label': list.labelOf(entry.item),
            disabled: list.isDisabled(),
            onClick: (event) => event.stopPropagation(),
            onMousedown: (event) => event.stopPropagation(),
            onKeydown
        }, h(DynamicIcon, {name: 'ReorderDotsVertical'}));
    }
});
