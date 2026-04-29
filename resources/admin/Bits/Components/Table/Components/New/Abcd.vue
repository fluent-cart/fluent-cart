<script setup>
const props = defineProps({
  row: {
    type: Object,
  },
  index: {
    type: Number,
    required: true
  },
})
</script>

<template>
  {{row.first_name}} {{index}} jgfgujyghjhghgj
</template>

<style scoped>

</style>