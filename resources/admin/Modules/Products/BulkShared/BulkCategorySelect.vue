<script setup>
const props = defineProps({
  modelValue: {
    type: Array,
    default: () => [],
  },
  options: {
    type: Array,
    default: () => [],
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['update:modelValue', 'change']);

const onChange = (val) => {
  emit('update:modelValue', val);
  emit('change', val);
};
</script>

<template>
  <fct-select
    :model-value="modelValue"
    :options="options"
    multiple
    filterable
    allow-create
    default-first-option
    collapse-tags
    :collapse-tags-tooltip="false"
    :max-collapse-tags="1"
    size="small"
    :placeholder="$t('Select or type...')"
    :disabled="disabled"
    @update:model-value="onChange"
  />
</template>
