<script setup>
import {defineModel} from "vue";


const model = defineModel();

const props = defineProps({
  name: {
    type: String,
    required: true
  },

  field: {
    type: Object
  },

  fieldKey: {
    type: String
  },
  value: {
    required: true
  },
  variant: {
    type: String
  },
  nesting: {
    type: Boolean,
    default: false
  },
  statePath: {
    type: String
  },
  form: {
    type: Object,
    required: true
  },
  callback: {
    type: Function,
    required: true
  },
  label:{
    type: String
  },
  attribute: {
    required: true
  }
})

</script>

<template>
  <el-switch v-model="model" :disabled="field.disabled" v-bind="attribute"/>
</template>

<style scoped>

</style>