<script setup>
  import {onMounted} from "vue";
  const props = defineProps({
    row: {
      type: Object,
    },
    index: {
      type: Number,
      required: true
    }
  })

  onMounted(()=>{

  })
</script>

<template>
    {{row.id}} This is my test component
</template>

<style scoped>

</style>