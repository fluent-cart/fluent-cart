<template>
  <component :is="`h${headingLevel}`" :class="['fct-title', `fct-title--${titleSize}`]">
    <slot/>
  </component>
</template>

<script setup>
import {computed} from 'vue';
import {useParentLevel} from './headingLevel';

defineOptions({name: 'LayoutTitle'});

const props = defineProps({
  level: {
    type: Number,
    default: null,
    validator: (value) => value >= 1 && value <= 6
  },
  size: {
    type: String,
    default: null,
    validator: (value) => ['lg', 'md', 'sm', 'xs'].includes(value)
  }
});

const parentLevel = useParentLevel();

const headingLevel = computed(() => props.level || Math.min(Math.max(parentLevel + 1, 2), 6));

const titleSize = computed(() => props.size || ({1: 'lg', 2: 'md', 3: 'sm'}[headingLevel.value] || 'xs'));
</script>
