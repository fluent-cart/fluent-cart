<template>
  <ElTooltip
      :class="$attrs.class"
      :style="$attrs.style"
      :content="content"
      :placement="placement"
      :trigger="trigger"
      :disabled="disabled"
      :show-after="showAfter"
      :hide-after="hideAfter"
      :popper-class="popperClass ? 'fct-tooltip ' + popperClass : 'fct-tooltip'"
  >
    <slot/>
    <template v-if="$slots.content" #content><slot name="content"/></template>
  </ElTooltip>
</template>

<script setup>
import {ElTooltip} from 'element-plus';

defineOptions({name: 'FctTooltip', inheritAttrs: false});

defineProps({
  content: {type: String, default: ''},
  placement: {type: String, default: 'top', validator: (value) => ['top', 'top-start', 'top-end', 'bottom', 'bottom-start', 'bottom-end', 'left', 'right'].includes(value)},
  // One trigger, or several (e.g. ['hover', 'focus'] so keyboard users get it too).
  trigger: {type: [String, Array], default: 'hover', validator: (value) => [].concat(value).every((one) => ['hover', 'click', 'focus'].includes(one))},
  disabled: {type: Boolean, default: false},
  showAfter: {type: Number, default: 0},
  hideAfter: {type: Number, default: 200},
  // Extra classes next to core's fct-tooltip (e.g. fct-tooltip-long).
  popperClass: {type: String, default: ''},
});
</script>
