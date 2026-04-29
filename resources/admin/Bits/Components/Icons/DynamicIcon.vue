<script setup>

//Empty/ListViewIcon
import {markRaw, onMounted, ref, shallowRef, watch} from "vue";

const components = import.meta.glob('./**/*.vue');

const dynamicIcon = shallowRef(null);
const dynamicDarkIcon = shallowRef(null);

onMounted(() => {
  loadIcon(props.name);
})

const loadIcon = (name) => {
  loadComponent(name);
  if (props.hasDark) {
    loadDarkComponent(name);
  }
}

const loadComponent = async (name) => {
  const componentPath = `./${name}.vue`;
  if (components[componentPath]) {
    dynamicIcon.value = markRaw((await components[componentPath]()).default);
  } else {
    console.error(`Dynamic Dark Icon ${componentPath} not found`);
  }
};

const loadDarkComponent = async (name) => {
  name = `DarkIcons/${name}`;
  const componentPath = `./${name}.vue`;
  if (components[componentPath]) {
    dynamicDarkIcon.value = markRaw((await components[componentPath]()).default);
  } else {
    console.error(`Dynamic Dark Icon ${componentPath} not found`);
  }
};

const props = defineProps({
  name: {
    type: String,
    required: true
  },
  hasDark: {
    type: Boolean,
    default: false
  }
})

watch(() => props.name, (newName) => {
  loadIcon(newName);
}, { immediate: true }); 
</script>

<template>
  <div class="icon">
    <component :class="{'block dark:hidden':hasDark}" :is="dynamicIcon"/>
    <component class="hidden dark:block" v-if="hasDark" :is="dynamicDarkIcon"/>
  </div>
</template>