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
  label: {
    type: String
  },
  attribute: {
    required: true
  }
})

</script>

<template>
  <el-checkbox
      v-model="model"
      v-bind="attribute"
      :true-value="'yes'" :false-value="'no'"
      :disabled="field.disabled"
  >
    {{ label }}
  </el-checkbox>
</template>

<style scoped>

</style>