<template>
  <div :class="classes">
    <div v-if="icon" class="fct-alert-icon">
      <DynamicIcon :name="icon"/>
    </div>

    <template v-if="$slots['content']">
      <slot name="content"/>
    </template>

    <template v-else>
      {{content}}
    </template>

    <div v-if="closable" class="fct-alert-close" @click="close">
      <DynamicIcon name="Cross" />
    </div>

    <slot/>
  </div>
</template>
<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {computed} from "vue";

const props = defineProps({
  type: {
    type: String,
    default: ''
  },
  content: {
    type: String,
    default: ''
  },
  icon: {
    type: String,
    default: ''
  },
  closable: {
    type: Boolean,
    default: false
  }
});

const classes = computed(() => {
  return {
    'fct-alert': true,
    'fct-alert-info': props.type === 'info',
    'fct-alert-success': props.type === 'success',
    'fct-alert-warning': props.type === 'warning',
    'fct-alert-error': props.type === 'error'
  }
});

const emit = defineEmits(['close']);

const close = () => {
  emit('close');
}
</script>
