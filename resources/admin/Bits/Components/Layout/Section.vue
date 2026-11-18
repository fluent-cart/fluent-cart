<template>
  <component
      :is="hasTitle ? 'section' : 'div'"
      :class="['fct-section', nested ? 'fct-section--nested' : 'fct-card', `fct-section--${size}`, {'fct-section--flush': flush, 'fct-section--divided': divided}]"
      :aria-labelledby="hasTitle ? titleId : null"
  >
    <div v-if="hasTitle || slots.actions" class="fct-section__header">
      <div class="fct-section__heading">
        <Title v-if="hasTitle" :id="titleId" :level="level" :size="titleSize">
          <slot name="title">{{ title }}</slot>
        </Title>
        <p v-if="description || slots.description" class="fct-section__description">
          <slot name="description">{{ description }}</slot>
        </p>
      </div>
      <div v-if="slots.actions" class="fct-section__actions">
        <slot name="actions"/>
      </div>
    </div>

    <div v-if="slots.default" class="fct-section__body">
      <slot/>
    </div>

    <div v-if="slots.footer && !nested" class="fct-section__footer">
      <slot name="footer"/>
    </div>
  </component>
</template>

<script setup>
import {computed, useId, useSlots} from 'vue';
import Title from './Title.vue';
import {provideLevel, useParentLevel} from './headingLevel';

const props = defineProps({
  title: {
    type: String,
    default: ''
  },
  description: {
    type: String,
    default: ''
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['md', 'sm'].includes(value)
  },
  flush: {
    type: Boolean,
    default: false
  },
  // A line between the header and the body.
  divided: {
    type: Boolean,
    default: false
  }
});

const slots = useSlots();
const titleId = 'fct-section-' + useId();
const parentLevel = useParentLevel();

// Headings stop at h4: deeper nesting reads as clutter, not structure.
const level = Math.min(parentLevel + 1, 4);
const nested = parentLevel >= 2;
provideLevel(level);

const hasTitle = computed(() => !!(props.title || slots.title));
const titleSize = computed(() => (nested || props.size === 'sm') ? 'sm' : 'md');
</script>
