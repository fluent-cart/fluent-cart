<script setup>
import {defineModel} from "vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";


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
  <div class="grid grid-cols-2 gap-4">


    <div @click="model = 'logo'"
         class="relative cursor-pointer border border-solid border-gray-outline rounded p-2 dark:border-dark-400 w-[110px]"
         :class="{'border-primary-500 dark:border-gray-200': model === 'logo'}">
      <p class="m-0 mb-2">{{ translate('Logo') }}</p>
      <div class="flex gap-2 items-center ">
        <DynamicIcon name="CreditCard" class="w-4 h-4 text-gray-400" :class="{'text-primary-500 dark:text-gray-200': model === 'logo'}"/>
        <span class="bg-gray-200 dark:bg-gray-700 h-2 w-full rounded"/>
      </div>
      <DynamicIcon name="CheckCircleFill" class="w-3 h-3 absolute top-2 right-2 text-primary-500 dark:text-gray-50" v-if="model === 'logo'"/>
    </div>

    <div @click="model = 'radio'"
         class="relative cursor-pointer border border-solid border-gray-outline rounded p-2 dark:border-dark-400 w-[110px]"
         :class="{'border-primary-500 dark:border-gray-200': model === 'radio'}">
      <p class="m-0 mb-2">{{ translate('Radio') }}</p>
      <div class="flex gap-2 items-center ">
        <DynamicIcon name="RadioSelector" class="w-4 h-4 text-gray-400" :class="{'text-primary-500 dark:text-gray-200': model === 'radio'}"/>
        <span class="bg-gray-200 dark:bg-gray-700 h-2 w-full rounded"/>
      </div>
      <DynamicIcon name="CheckCircleFill" class="w-3 h-3 absolute top-2 right-2 text-primary-500 dark:text-gray-50" v-if="model === 'radio'"/>
    </div>
  </div>
</template>

<style scoped>

</style>
