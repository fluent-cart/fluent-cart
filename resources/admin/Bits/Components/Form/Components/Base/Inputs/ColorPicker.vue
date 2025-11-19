<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {defineModel} from "vue";
import translate from "@/utils/translator/Translator";

const model = defineModel();

const props = defineProps({
  field: {
    type: Object
  },
  statePath: {
    type: String
  },
  value: {
    required: true
  },
  form: {
    type: Object
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

const handleColorReset = (key) => {
  model.value = '';
}
</script>

<template>

  <div class="fct-color-picker-box">

    <div class="fct-color-picker-trigger-wrap">
      <el-color-picker v-model="model" v-bind="attribute" :disabled="field.disabled"
                       popper-class="fct-color-picker-popover"/>
      <DynamicIcon v-if="!model" name="ColorPicker" class="fct-color-picker-icon w-4 h-4"/>
    </div>

    <span v-if="!model" class="text-xs text-system-light">{{ translate('Add...') }}</span>

    <div v-if="model" class="fct-color-picker-action-wrap">
      <span>{{ model }}</span>
      <DynamicIcon name="Cross" class="fct-color-picker-action w-5 h-5" @click="handleColorReset(fieldKey)"/>
    </div>
  </div>
</template>

<style scoped>

</style>
