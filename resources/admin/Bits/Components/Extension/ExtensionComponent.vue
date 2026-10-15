<script setup>
import {getExtensionComponent} from "@/utils/extensionComponents";

defineOptions({inheritAttrs: false});

const props = defineProps({
  name: {
    type: String,
    required: true
  }
});

const component = getExtensionComponent(props.name);
</script>

<template>
  <component v-if="component" :is="component" v-bind="$attrs"/>
</template>
