<script setup>

import {defineModel, onMounted, ref, shallowRef} from "vue";

const model = defineModel();

const components = import.meta.glob('./**/*.vue');
const dynamicComponent = shallowRef(null);

onMounted(() => {
  loadComponent(props.name)
})


const loadComponent = async (name) => {
  const componentPath = `./${name}.vue`;
  if (components[componentPath]) {
    dynamicComponent.value = ((await components[componentPath]()).default);
  } else {
    console.error(`Form Component ${componentPath} not found`);
  }
};

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
  label: {
    type: String
  },
  attribute: {
    type: Object
  },
  callback: {
    type: Function,
  },
})


</script>

<template>


  <component :is="dynamicComponent" v-bind="{
    name,
    field,
    fieldKey,
    value,
    variant,
    nesting,

    statePath,
    form,
    attribute,
    label,
    callback
  }"
             v-model="model"

  />

  <slot name="fieldNote"/>
  <slot name="validationError"/>
</template>