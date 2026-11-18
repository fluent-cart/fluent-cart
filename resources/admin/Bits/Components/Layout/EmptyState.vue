<template>
  <div :class="['fct-empty-state', `fct-empty-state--${variant}`, `fct-empty-state--${size}`]">
    <div v-if="iconName" class="fct-empty-state__icon">
      <DynamicIcon :name="iconName" :has-dark="true"/>
    </div>
    <Title v-if="shownTitle" class="fct-empty-state__title" :size="size === 'sm' ? 'xs' : 'sm'">{{ shownTitle }}</Title>
    <p v-if="shownText" class="fct-empty-state__text">{{ shownText }}</p>
    <div v-if="slots.actions" class="fct-empty-state__actions">
      <slot name="actions"/>
    </div>
  </div>
</template>

<script setup>
import {computed, useSlots} from 'vue';
import translate from '@/utils/translator/Translator';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import Title from './Title.vue';

const props = defineProps({
  // no-results: data exists but the current filter hides it.
  variant: {
    type: String,
    default: 'empty',
    validator: (value) => ['empty', 'no-results'].includes(value)
  },
  title: {
    type: String,
    default: ''
  },
  text: {
    type: String,
    default: ''
  },
  icon: {
    type: String,
    default: ''
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['md', 'sm'].includes(value)
  }
});

const slots = useSlots();
const noResults = computed(() => props.variant === 'no-results');

const shownTitle = computed(() => props.title || (noResults.value ? translate('No results found') : ''));
const shownText = computed(() => props.text || (noResults.value ? translate('Try changing or clearing your filters.') : ''));

// The compact size sits inside a section or table row, where an illustration is too big.
const iconName = computed(() => props.icon || (props.size === 'sm' ? '' : 'Empty/ListView'));
</script>
