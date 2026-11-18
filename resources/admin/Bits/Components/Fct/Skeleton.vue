<template>
  <ElSkeletonItem v-if="variant" :class="$attrs.class" :style="$attrs.style" :variant="variant" :aria-label="ariaLabel"/>
  <ElSkeleton v-else :class="$attrs.class" :style="$attrs.style" :animated="animated" :rows="rows" :aria-label="ariaLabel">
    <template v-if="$slots.default" #template>
      <slot/>
    </template>
  </ElSkeleton>
</template>

<script setup>
import {ElSkeleton, ElSkeletonItem} from 'element-plus';

// variant = one placeholder bar; otherwise rows of text, or the default slot as a custom layout.
defineOptions({name: 'FctSkeleton', inheritAttrs: false});

defineProps({
  animated: {type: Boolean, default: false},
  rows: {type: Number, default: 3},
  variant: {type: String, default: '', validator: (value) => ['', 'text', 'p', 'h1', 'h3', 'caption', 'button', 'image', 'circle', 'rect'].includes(value)},
  ariaLabel: {type: String, default: undefined},
});
</script>
