<script setup>
import {defineModel} from "vue";
import MediaInput from "@/Bits/Components/Inputs/MediaInput.vue";

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
  <div class="setting-media-wrap">
    <MediaInput v-model="model" :field="field" icon="Upload" v-bind="attribute"/>
  </div>
</template>

<style scoped>

</style>