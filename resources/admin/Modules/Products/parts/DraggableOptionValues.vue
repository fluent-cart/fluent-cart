<script setup>
import { computed } from "vue";
import { VueDraggableNext } from 'vue-draggable-next';
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

// Draggable list of an option's selected values, rendered inside the el-select
// #tag slot. Reordering changes the combination order (serial_index) for the
// current product only — the new order flows back through v-model into
// draft.variants → the save payload. Extracted from AdvancedVariationConfig so
// the Text / Color / Image value pickers share one implementation; they differ
// only by the leading swatch (color) / thumbnail (image).
const props = defineProps({
  // Selected values: numeric term ids, or not-yet-persisted typed strings.
  modelValue: {
    type: Array,
    default: () => [],
  },
  // The option group's term objects, used to resolve a value's label / swatch /
  // image. Pending typed strings won't match any term and fall back to the
  // string itself.
  terms: {
    type: Array,
    default: () => [],
  },
  // 'options' (text) | 'color' | 'image' — selects the leading visual.
  type: {
    type: String,
    default: 'options',
  },
});

const emit = defineEmits(['update:modelValue']);

const sameId = (a, b) => String(a) === String(b);

// Two-way proxy so VueDraggableNext can reorder and we surface the new order to
// the parent via v-model — without mutating the prop array directly.
const values = computed({
  get: () => props.modelValue,
  set: (next) => emit('update:modelValue', next),
});

const termFor = (val) => props.terms.find(t => sameId(t.id, val)) || null;

const labelFor = (val) => {
  if (typeof val === 'string') return val;
  const term = termFor(val);
  return term ? term.title : String(val);
};

const swatchStyle = (val) => {
  const term = termFor(val);
  return term && term.settings && term.settings.color ? { background: term.settings.color } : {};
};

const imageFor = (val) => {
  const term = termFor(val);
  return term && term.settings && term.settings.image ? term.settings.image : '';
};

const removeValue = (val) => {
  emit('update:modelValue', props.modelValue.filter(v => !sameId(v, val)));
};
</script>

<template>
  <VueDraggableNext
      v-model="values"
      handle=".fct-value-tag-handle"
      :disabled="values.length < 2"
      class="fct-value-tag-list"
      tag="div"
  >
    <span v-for="val in values" :key="String(val)" class="fct-swatch-tag">
      <span v-if="values.length > 1" class="fct-value-tag-handle" @mousedown.stop>
        <DynamicIcon name="ReorderDotsVertical"/>
      </span>
      <span v-if="type === 'color'" class="fct-swatch-tag-dot" :style="swatchStyle(val)"></span>
      <img v-else-if="type === 'image'" class="fct-swatch-tag-img" :src="imageFor(val)" :alt="labelFor(val)"/>
      <span class="fct-swatch-tag-label">{{ labelFor(val) }}</span>
      <span
          class="fct-swatch-tag-close"
          role="button"
          tabindex="0"
          :aria-label="translate('Remove')"
          @mousedown.stop
          @click.stop="removeValue(val)"
          @keydown.enter.space.prevent="removeValue(val)"
      >×</span>
    </span>
  </VueDraggableNext>
</template>
