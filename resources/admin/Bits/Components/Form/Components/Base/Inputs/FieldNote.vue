<script setup>
defineProps({
  note: String
})
</script>

<template>
  <div class="form-note" v-html="note"></div>
</template>

<style scoped>

</style>