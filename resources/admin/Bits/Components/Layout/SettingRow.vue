<template>
  <div
      :class="['fct-setting-field', `fct-setting-field--${layout}`, {'fct-setting-field--error': error}]"
      :role="group ? 'group' : null"
      :aria-labelledby="group ? labelId : null"
      :aria-describedby="group ? describedBy : null"
  >
    <div class="fct-setting-field__info">
      <component :is="group ? 'span' : 'label'" :id="labelId" class="fct-setting-field__label" :for="group ? null : id">
        <slot name="label">{{ label }}</slot>
      </component>
      <div v-if="hasDescription" :id="descriptionId" class="fct-setting-field__description">
        <slot name="description">{{ description }}</slot>
      </div>
    </div>

    <div class="fct-setting-field__control">
      <slot :id="id" :describedBy="describedBy"/>
      <p v-if="error" :id="errorId" class="fct-setting-field__error" role="alert">{{ error }}</p>
    </div>
  </div>
</template>

<script setup>
import {computed, useId, useSlots} from 'vue';

const props = defineProps({
  label: {
    type: String,
    required: true
  },
  description: {
    type: String,
    default: ''
  },
  error: {
    type: String,
    default: ''
  },
  // inline: a lone switch or number box on the right edge; the label takes the rest of the line.
  // toggle: a feature switch before its label, with a line saying what the store does now.
  layout: {
    type: String,
    default: 'columns',
    validator: (value) => ['columns', 'inline', 'toggle'].includes(value)
  },
  // Radios/checkboxes: the row becomes a labelled group instead of a <label for>.
  group: {
    type: Boolean,
    default: false
  }
});

const slots = useSlots();
const id = 'fct-setting-' + useId();
const labelId = id + '-label';
const descriptionId = id + '-description';
const errorId = id + '-error';

const hasDescription = computed(() => !!(props.description || slots.description));

const describedBy = computed(() => [
  hasDescription.value && descriptionId,
  props.error && errorId
].filter(Boolean).join(' ') || null);
</script>
