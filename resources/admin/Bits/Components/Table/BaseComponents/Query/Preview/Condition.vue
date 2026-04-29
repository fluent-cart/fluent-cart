<script setup>
import Preview from "@/Bits/Components/Table/BaseComponents/Query/Preview/Preview.vue";

const props = defineProps({
  condition: {
    type: Object,
    required: true
  },
  columns: {
    type: Object,
    required: true
  },
  isFirst: {
    type: Boolean,
    default: false,
  },
  queryState: {
    type: Object,
    required: true
  },
})
</script>

<template>


  <template v-if="Array.isArray(condition.conditions) && condition.conditions.length > 0">
    <span class="condition-bracket-left dark:text-gray-50">(</span>
    <Preview :query="condition" :columns="columns" :queryState="queryState"/>
    <span class="condition-bracket-right dark:text-gray-50 ">)</span>
  </template>


</template>

<style scoped>

</style>