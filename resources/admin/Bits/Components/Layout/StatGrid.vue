<template>
  <dl :class="['fct-stat-grid', {'fct-stat-grid--fixed': columns}]" :style="columns ? {'--fct-stat-columns': columns} : null">
    <slot/>
  </dl>
</template>

<script setup>
defineProps({
  columns: {
    type: Number,
    default: null
  }
});
</script>
