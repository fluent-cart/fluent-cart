<template>
  <dl :class="['fct-kv-list', `fct-kv-list--${layout}`]">
    <slot/>
  </dl>
</template>

<script setup>
defineProps({
  layout: {
    type: String,
    default: 'row',
    validator: (value) => ['row', 'stacked'].includes(value)
  }
});
</script>
