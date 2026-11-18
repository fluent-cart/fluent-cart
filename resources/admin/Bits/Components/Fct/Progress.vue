<template>
  <ElProgress
      :class="$attrs.class"
      :style="$attrs.style"
      :percentage="percentage"
      :show-text="showText"
      :stroke-width="strokeWidth"
      :text-inside="textInside"
      :status="status"
      :striped="striped"
      :striped-flow="stripedFlow"
      :duration="duration"
  >
    <template v-if="$slots.default" #default="scope"><slot v-bind="scope"/></template>
  </ElProgress>
</template>

<script setup>
import {ElProgress} from 'element-plus';

defineOptions({name: 'FctProgress', inheritAttrs: false});

defineProps({
  percentage: {type: Number, required: true},
  showText: {type: Boolean, default: true},
  strokeWidth: {type: Number, default: 6},
  textInside: {type: Boolean, default: false},
  status: {type: String, default: '', validator: (value) => ['', 'success', 'exception', 'warning'].includes(value)},
  striped: {type: Boolean, default: false},
  stripedFlow: {type: Boolean, default: false},
  duration: {type: Number, default: 3},
});
</script>
