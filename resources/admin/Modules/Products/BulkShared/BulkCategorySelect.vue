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
  <el-select
    :model-value="modelValue"
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
  >
    <el-option
      v-for="cat in options"
      :key="cat.value"
      :label="cat.label"
      :value="cat.value"
    />
  </el-select>
</template>
