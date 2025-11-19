<script setup>
import {onMounted} from "vue";

defineOptions({
  inheritAttrs: false
})
const props = defineProps(['cells', 'rowData'])

</script>

<template>
  <span v-if="rowData?.children[0]?.detail">
    {{ rowData.children[0]?.detail }}
  </span>

  <template v-for="cell in cells" v-else>
    <component :is="cell"/>
  </template>

</template>