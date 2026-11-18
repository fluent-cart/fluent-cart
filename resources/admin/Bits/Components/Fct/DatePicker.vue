<template>
  <Field v-bind="$props" v-slot="field">
    <ElDatePicker
        ref="control"
        :id="type === 'daterange' && field.id ? [field.id, field.id + '-end'] : field.id"
        :class="[$attrs.class, xSmallClass]"
        :style="$attrs.style"
        :model-value="modelValue"
        :type="type"
        :size="size === 'x-small' ? '' : size"
        :disabled="disabled"
        :placeholder="placeholder"
        :start-placeholder="startPlaceholder"
        :end-placeholder="endPlaceholder"
        :range-separator="rangeSeparator"
        :format="format"
        :value-format="valueFormat"
        :default-value="defaultValue"
        :disabled-date="disabledDate"
        :unlink-panels="unlinkPanels"
        :clearable="clearable"
        :shortcuts="shortcuts"
        :placement="placement"
        :popper-class="popperClass"
        :teleported="teleported"
        :aria-label="ariaLabel"
        @update:model-value="emit('update:modelValue', $event)"
        @change="emit('change', $event)"
    />
  </Field>
</template>

<script setup>
import {computed, ref} from 'vue';
import {ElDatePicker} from 'element-plus';
import Field from './Field.vue';
import {fieldProps} from './fieldProps';

defineOptions({inheritAttrs: false});

const props = defineProps({
  ...fieldProps,
  modelValue: {type: [Date, String, Number, Array, Object], default: ''},
  type: {type: String, default: 'date', validator: (value) => ['date', 'datetime', 'daterange'].includes(value)},
  size: {type: String, default: '', validator: (value) => ['', 'small', 'x-small'].includes(value)},
  disabled: {type: Boolean, default: false},
  placeholder: {type: String, default: undefined},
  startPlaceholder: {type: String, default: undefined},
  endPlaceholder: {type: String, default: undefined},
  rangeSeparator: {type: String, default: '-'},
  // Display and v-model formats (dayjs tokens), e.g. "YYYY-MM-DD HH:mm:ss".
  format: {type: String, default: undefined},
  valueFormat: {type: String, default: undefined},
  defaultValue: {type: [Date, String, Number, Array, Object], default: undefined},
  disabledDate: {type: Function, default: undefined},
  unlinkPanels: {type: Boolean, default: false},
  clearable: {type: Boolean, default: true},
  shortcuts: {type: Array, default: () => []},
  placement: {type: String, default: 'bottom'},
  popperClass: {type: String, default: ''},
  teleported: {type: Boolean, default: true},
  ariaLabel: {type: String, default: undefined},
});

const emit = defineEmits(['update:modelValue', 'change']);

// Core styles x-small on the range editor and on the single-date input separately.
const xSmallClass = computed(() => {
  if (props.size !== 'x-small') {
    return '';
  }
  return props.type === 'daterange' ? 'el-range-editor--x-small' : 'el-input--x-small';
});

const control = ref(null);
defineExpose({
  focus: () => control.value && control.value.focus(),
});
</script>
