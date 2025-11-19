<script setup>

import {onMounted, ref} from "vue";


const components = import.meta.glob('./**/*.vue');
const dynamicComponent = ref(null);

onMounted(() => {
  loadComponent(props.name)
})


const loadComponent = async (name) => {
  const componentPath = `./${name}.vue`;
  if (components[componentPath]) {
    dynamicComponent.value = ((await components[componentPath]()).default);
  } else {
    console.error(`Table Component ${componentPath} not found`);
  }
};

const props = defineProps({
  name: {
    type: String,
    required: true
  },
  row: {
    type: Object,
  },
  column: {
    type: Object,
    required: true
  },
  index: {
    type: Number,
    required: true
  },

  table: {
    type: Object,
    required: true
  },

  data: {
    type: Object,
    required: true
  }
})

</script>

<template>
  <component :is="dynamicComponent" v-bind="{row,index,table,data, column}"
  />
</template>