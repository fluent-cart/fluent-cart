<template>
  <div class="fct-kv">
    <dt class="fct-kv__label">
      <slot name="label">{{ label }}</slot>
    </dt>
    <dd class="fct-kv__value">
      <slot>{{ isEmpty ? '—' : value }}</slot>
    </dd>
  </div>
</template>

<script setup>
import {computed} from 'vue';

const props = defineProps({
  label: {
    type: String,
    default: ''
  },
  value: {
    type: [String, Number],
    default: null
  }
});

// A dash keeps the row from collapsing; 0 is a real value.
const isEmpty = computed(() => props.value === null || props.value === undefined || props.value === '');
</script>
