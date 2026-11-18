<template>
  <Field v-bind="$props" v-slot="field">
    <ElCheckbox
        v-if="!options"
        :id="field.id"
        :class="$attrs.class"
        :style="$attrs.style"
        :model-value="modelValue"
        :true-value="trueValue"
        :false-value="falseValue"
        :size="size"
        :disabled="disabled"
        :indeterminate="indeterminate"
        :tabindex="tabindex"
        :aria-label="ariaLabel"
        @update:model-value="emit('update:modelValue', $event)"
        @change="emit('change', $event)"
        @click="emit('click', $event)"
    >
      <!-- Only with text: an empty label still takes 8px. -->
      <template v-if="$slots.default" #default><slot/></template>
    </ElCheckbox>
    <component
        :is="type === 'radio' ? ElRadioGroup : ElCheckboxGroup"
        v-else
        :id="field.id"
        :class="$attrs.class"
        :style="$attrs.style"
        :model-value="modelValue"
        :size="size"
        :disabled="disabled"
        :aria-label="ariaLabel"
        :aria-labelledby="ariaLabel ? undefined : field.labelId"
        @update:model-value="emit('update:modelValue', $event)"
        @change="emit('change', $event)"
    >
      <component
          :is="optionComponent"
          v-for="(option, index) in options"
          :key="index"
          :value="option.value"
          :disabled="option.disabled"
          :aria-label="option.ariaLabel"
          v-bind="type === 'radio' && !button ? {border} : {}"
          @click="emit('click', $event, option)"
      >
        <slot name="option" :option="option">{{ option.label }}</slot>
      </component>
    </component>
  </Field>
</template>

<script setup>
import {computed} from 'vue';
import {ElCheckbox, ElCheckboxButton, ElCheckboxGroup, ElRadio, ElRadioButton, ElRadioGroup} from 'element-plus';
import Field from './Field.vue';
import {fieldProps} from './fieldProps';

// One component for checkboxes and radios: no options = a single checkbox; options = a group.
defineOptions({name: 'FctChoice', inheritAttrs: false});

const props = defineProps({
  ...fieldProps,
  modelValue: {type: [Boolean, String, Number, Array], default: undefined},
  type: {type: String, default: 'checkbox', validator: (value) => ['checkbox', 'radio'].includes(value)},
  options: {type: Array, default: undefined},
  button: {type: Boolean, default: false},
  trueValue: {type: [String, Number], default: undefined},
  falseValue: {type: [String, Number], default: undefined},
  size: {type: String, default: '', validator: (value) => ['', 'small', 'large'].includes(value)},
  disabled: {type: Boolean, default: false},
  // Single checkbox: the "some selected" state of a select-all box.
  indeterminate: {type: Boolean, default: false},
  tabindex: {type: [Number, String], default: undefined},
  // Radio options drawn with a border.
  border: {type: Boolean, default: false},
  ariaLabel: {type: String, default: undefined},
});

const emit = defineEmits(['update:modelValue', 'change', 'click']);

const optionComponent = computed(() => {
  if (props.type === 'radio') {
    return props.button ? ElRadioButton : ElRadio;
  }
  return props.button ? ElCheckboxButton : ElCheckbox;
});
</script>
