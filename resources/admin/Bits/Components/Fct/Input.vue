<template>
  <Field v-bind="$props" v-slot="field">
    <ElInputNumber
        v-if="type === 'number' && (controls || precision !== undefined)"
        ref="control"
        v-bind="dataAttrs"
        :id="field.id"
        :class="$attrs.class"
        :style="$attrs.style"
        :model-value="modelValue === '' || modelValue === null ? undefined : Number(modelValue)"
        :placeholder="placeholder"
        :size="size === 'x-small' ? 'small' : size"
        :disabled="disabled"
        :min="min === undefined ? -Infinity : Number(min)"
        :max="max === undefined ? Infinity : Number(max)"
        :step="step === undefined ? 1 : Number(step)"
        :precision="precision"
        :controls="controls"
        :controls-position="controlsPosition"
        :aria-label="ariaLabel"
        @update:model-value="emit('update:modelValue', $event)"
        @input="emit('input', $event)"
        @change="emit('change', $event)"
        @click="emit('click', $event)"
        @keyup="emit('keyup', $event)"
        @keydown="emit('keydown', $event)"
        @focus="emit('focus', $event)"
        @blur="emit('blur', $event)"
    >
      <template v-if="$slots.prefix" #prefix><slot name="prefix"/></template>
      <template v-if="$slots['decrease-icon']" #decrease-icon><slot name="decrease-icon"/></template>
      <template v-if="$slots['increase-icon']" #increase-icon><slot name="increase-icon"/></template>
    </ElInputNumber>
    <ElInput
        v-else
        ref="control"
        v-bind="dataAttrs"
        :id="field.id"
        :class="[$attrs.class, {'el-input--x-small': size === 'x-small'}]"
        :style="$attrs.style"
        :model-value="modelValue"
        :type="type"
        :placeholder="placeholder"
        :size="size === 'x-small' ? '' : size"
        :disabled="disabled"
        :readonly="readonly"
        :clearable="clearable"
        :show-password="showPassword"
        :rows="rows"
        :autosize="autosize"
        :resize="resize"
        :autocomplete="autocomplete"
        :autofocus="autofocus"
        :spellcheck="spellcheck"
        :inputmode="inputmode"
        :maxlength="maxlength"
        :minlength="minlength"
        :show-word-limit="showWordLimit"
        :min="min"
        :max="max"
        :step="step"
        :required="required || undefined"
        :aria-label="ariaLabel"
        :aria-describedby="ariaDescribedby"
        :aria-invalid="ariaInvalid"
        :aria-controls="ariaControls"
        :aria-activedescendant="ariaActivedescendant"
        @update:model-value="emit('update:modelValue', $event)"
        @input="emit('input', $event)"
        @change="emit('change', $event)"
        @clear="emit('clear')"
        @click="emit('click', $event)"
        @focus="emit('focus', $event)"
        @blur="emit('blur', $event)"
        @keyup="emit('keyup', $event)"
        @keydown="emit('keydown', $event)"
        @keypress="emit('keypress', $event)"
    >
      <template v-if="$slots.prefix" #prefix><slot name="prefix"/></template>
      <template v-if="$slots.suffix" #suffix><slot name="suffix"/></template>
      <template v-if="$slots.prepend" #prepend><slot name="prepend"/></template>
      <template v-if="$slots.append" #append><slot name="append"/></template>
    </ElInput>
  </Field>
</template>

<script setup>
import {ref} from 'vue';
import {ElInput, ElInputNumber} from 'element-plus';
import Field from './Field.vue';
import {fieldProps, useDataAttrs} from './fieldProps';

defineOptions({name: 'FctInput', inheritAttrs: false});

defineProps({
  ...fieldProps,
  modelValue: {type: [String, Number], default: ''},
  type: {type: String, default: 'text', validator: (value) => ['text', 'number', 'password', 'textarea', 'email', 'url'].includes(value)},
  placeholder: {type: String, default: undefined},
  size: {type: String, default: '', validator: (value) => ['', 'default', 'small', 'x-small'].includes(value)},
  disabled: {type: Boolean, default: false},
  ariaLabel: {type: String, default: undefined},
  readonly: {type: Boolean, default: false},
  clearable: {type: Boolean, default: false},
  showPassword: {type: Boolean, default: false},
  rows: {type: [Number, String], default: 2},
  autosize: {type: [Boolean, Object], default: false},
  resize: {type: String, default: undefined},
  autocomplete: {type: String, default: 'off'},
  autofocus: {type: Boolean, default: false},
  spellcheck: {type: [Boolean, String], default: undefined},
  inputmode: {type: String, default: undefined},
  maxlength: {type: [Number, String], default: undefined},
  minlength: {type: [Number, String], default: undefined},
  showWordLimit: {type: Boolean, default: false},
  ariaDescribedby: {type: String, default: undefined},
  ariaInvalid: {type: [Boolean, String], default: undefined},
  ariaControls: {type: String, default: undefined},
  ariaActivedescendant: {type: String, default: undefined},
  // type="number": a stepper (Number value). :controls="false" is a plain native number box (String value),
  // unless precision is set: rounding needs the number box, which then shows no buttons.
  min: {type: [Number, String], default: undefined},
  max: {type: [Number, String], default: undefined},
  step: {type: [Number, String], default: undefined},
  precision: {type: Number, default: undefined},
  controls: {type: Boolean, default: true},
  controlsPosition: {type: String, default: '', validator: (value) => ['', 'right'].includes(value)},
});

const emit = defineEmits(['update:modelValue', 'input', 'change', 'clear', 'click', 'focus', 'blur', 'keyup', 'keydown', 'keypress']);
const dataAttrs = useDataAttrs();

const control = ref(null);
defineExpose({
  focus: () => control.value && control.value.focus(),
  blur: () => control.value && control.value.blur(),
  // Selects the text (ElInputNumber has no select(), so reach its input).
  select: () => {
    const el = control.value && control.value.$el;
    const input = el && (el.tagName === 'INPUT' ? el : el.querySelector('input, textarea'));
    if (input) {
      input.select();
    }
  },
});
</script>
