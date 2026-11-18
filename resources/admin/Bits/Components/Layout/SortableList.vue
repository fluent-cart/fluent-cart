<template>
  <div ref="rootRef" class="fct-sortable-list">
    <VueDraggableNext
        v-bind="$attrs"
        :model-value="modelValue"
        :tag="tag"
        :class="{'fct-list': tag === 'ul'}"
        handle=".fct-sortable-list__handle"
        :disabled="disabled"
        :animation="150"
        ghost-class="fct-sortable-list__ghost"
        @update:model-value="commit"
    >
      <SortableItem
          v-for="(item, index) in modelValue"
          v-show="!filter || filter(item)"
          :key="keyOf(item, index)"
          :item="item"
          :index="index"
      >
        <slot name="item" :item="item" :index="index" :handle="SortHandle"/>
      </SortableItem>
    </VueDraggableNext>
    <span class="fct-sortable-list__status" aria-live="polite">{{ announcement }}</span>
  </div>
</template>

<script setup>
import {nextTick, provide, ref} from 'vue';
import {VueDraggableNext} from 'vue-draggable-next';
import translate from '@/utils/translator/Translator';
import {SORTABLE_KEY, SortableItem, SortHandle} from './sortable';

const props = defineProps({
  modelValue: {
    type: Array,
    required: true
  },
  // A field name, or (item, index) => key for items without a stable id yet.
  itemKey: {
    type: [String, Function],
    default: 'id'
  },
  // (item) => the handle's accessible name, e.g. "Reorder Stripe".
  handleLabel: {
    type: Function,
    default: () => translate('Reorder: drag, or use the arrow up and down keys')
  },
  disabled: {
    type: Boolean,
    default: false
  },
  // (item) => false hides an item while keeping it in the order (search).
  filter: {
    type: Function,
    default: null
  },
  tag: {
    type: String,
    default: 'ul'
  }
});

const emit = defineEmits(['update:modelValue', 'reorder']);

// A class or id set on SortableList belongs on the list itself, so screen CSS
// like `.my-list > .my-row` keeps matching.
defineOptions({inheritAttrs: false});

const rootRef = ref(null);
const announcement = ref('');

const keyOf = (item, index) => typeof props.itemKey === 'function' ? props.itemKey(item, index) : item[props.itemKey];

const commit = (list) => {
  emit('update:modelValue', list);
  emit('reorder', list);
};

const move = (index, step) => {
  const target = index + step;
  if (props.disabled || target < 0 || target >= props.modelValue.length) {
    return;
  }
  const list = [...props.modelValue];
  [list[index], list[target]] = [list[target], list[index]];
  const key = String(keyOf(list[target], target));
  commit(list);
  announcement.value = translate('Moved to position %1$s of %2$s', target + 1, list.length);

  // The handle re-renders in its new place; keep focus on it so repeated presses work.
  nextTick(() => {
    rootRef.value?.querySelector('[data-fct-sort-key="' + key.replace(/"/g, '\\"') + '"]')?.focus();
  });
};

provide(SORTABLE_KEY, {
  move,
  keyOf,
  labelOf: (item) => props.handleLabel(item),
  isDisabled: () => props.disabled
});
</script>
