<template>
  <Field v-bind="$props" v-slot="field">
    <ElSwitch
        :id="field.id"
        :class="$attrs.class" :style="$attrs.style"
        :model-value="modelValue"
        :active-value="activeValue"
        :inactive-value="inactiveValue"
        :active-text="activeText"
        :inactive-text="inactiveText"
        :before-change="beforeChange"
        :size="size"
        :disabled="disabled"
        :aria-label="ariaLabel"
        :loading="loading"
        @update:model-value="emit('update:modelValue', $event)"
        @change="emit('change', $event)"
    />
  </Field>
</template>

<script setup>
import {ElSwitch} from 'element-plus';
import Field from './Field.vue';
import {fieldProps} from './fieldProps';

defineOptions({name: 'FctSwitch', inheritAttrs: false});

defineProps({
  ...fieldProps,
  modelValue: {type: [Boolean, String, Number], default: false},
  activeValue: {type: [Boolean, String, Number], default: true},
  inactiveValue: {type: [Boolean, String, Number], default: false},
  activeText: {type: String, default: ''},
  inactiveText: {type: String, default: ''},
  // Return false (or a rejected promise) to stop the toggle.
  beforeChange: {type: Function, default: undefined},
  size: {type: String, default: '', validator: (value) => ['', 'small'].includes(value)},
  disabled: {type: Boolean, default: false},
  ariaLabel: {type: String, default: undefined},
  loading: {type: Boolean, default: false},
});

const emit = defineEmits(['update:modelValue', 'change']);
</script>
