<script setup>
import TransitionFade from "@/Bits/Components/TransitionFade.vue";
import TransitionAccordion from "@/Bits/Components/TransitionAccordion.vue";
import {computed} from "vue";

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  duration: {
    type: Number,
    default: 300
  },
  fade: Boolean,
  accordion: Boolean
});

// Computed component based on type
const TransitionComponent = computed(() => {
  if (props.fade) {
    return TransitionFade
  }
  if (props.accordion) {
    return TransitionAccordion
  }
});


</script>

<template>
  <component
      :is="TransitionComponent"
      :visible="visible"
      :duration="duration"
  >
    <slot></slot>
  </component>
</template>

<style scoped>

</style>
