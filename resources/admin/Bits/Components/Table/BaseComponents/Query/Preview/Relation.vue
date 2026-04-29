<script setup>
import Preview from "@/Bits/Components/Table/BaseComponents/Query/Preview/Preview.vue";

const props = defineProps({
  relation: {
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


  <span class="text-purple-500">{{ relation.title }} {{ $t('Relation:') }}</span>
  <span class="condition-bracket-left">(</span>

  <Preview :query="relation" :columns="relation.columns" :queryState="queryState"/>
  <span class="condition-bracket-right">)</span>

</template>

<style scoped>

</style>