<script setup>
import {onBeforeMount, ref} from "vue";

const props = defineProps({
  model: {
    type: Object,
    required: true
  },
  variant: {
    type: Object,
    required: true
  }
})

onBeforeMount(() => {
  if(props.model.data.selectionStates[props.variant.id] === undefined) {
    props.model.data.selectionStates[props.variant.id] = false;
  }
})
</script>

<template>
  <el-checkbox v-model="model.data.selectionStates[variant.id]" @change="(value) => {
    model.onVariationSelected(variant, value)
  }"/>
</template>

<style scoped>

</style>