<script setup>
/**
 * ----------------------------------------------------------------------------
 * Imports
 * ----------------------------------------------------------------------------
 */
import { computed } from "vue";

/**
 * ----------------------------------------------------------------------------
 * Props
 * ----------------------------------------------------------------------------
 */
const props = defineProps({
  amount: {
    type: [Number, String],
    required: true
  },

  placement: {
    type: String,
    default: 'top'
  },

  title: {
    type: String,
    default: ''
  }
})

/**
 * ----------------------------------------------------------------------------
 * Computed Properties
 * ----------------------------------------------------------------------------
 */
const tooltip = computed(() => {
  return props.title ? props.title + ' ' + props.amount : props.amount;
});
</script>

<template>
  <fct-tooltip :placement="placement">
    <template #content>
      <span>{{ tooltip }}</span>
    </template>
    <span class="cursor-pointer" v-html="amount"></span>
  </fct-tooltip>
</template>
