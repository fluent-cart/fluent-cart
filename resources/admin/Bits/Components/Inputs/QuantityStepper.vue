<template>
  <div class="fct-quantity-stepper" role="group" :aria-label="label">
    <button
        type="button"
        :disabled="disabled || current <= min"
        :aria-label="translate('Decrease quantity')"
        @click="change(-1)"
    >&minus;</button>
    <span aria-live="polite">{{ current }}<small v-if="showMax"> / {{ max }}</small></span>
    <button
        type="button"
        :disabled="disabled || current >= max"
        :aria-label="translate('Increase quantity')"
        @click="change(1)"
    >+</button>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import translate from '@/utils/translator/Translator';

const props = defineProps({
  modelValue: {
    type: Number,
    default: 0,
  },
  min: {
    type: Number,
    default: 0,
  },
  max: {
    type: Number,
    default: 999999,
  },
  // Names the group for screen readers, e.g. "Quantity to ship now for Vase".
  label: {
    type: String,
    default: '',
  },
  showMax: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['update:modelValue']);

const current = computed(() => Number(props.modelValue) || 0);

const change = (delta) => {
  emit('update:modelValue', Math.min(props.max, Math.max(props.min, current.value + delta)));
};
</script>
