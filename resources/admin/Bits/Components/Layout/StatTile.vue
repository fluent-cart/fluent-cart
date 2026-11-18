<template>
  <div class="fct-stat" :aria-busy="loading ? 'true' : null">
    <dt class="fct-stat__label">
      <DynamicIcon v-if="icon" :name="icon" class="fct-stat__icon"/>
      {{ label }}
    </dt>
    <dd class="fct-stat__value">
      <span v-if="loading" class="fct-stat__skeleton"></span>
      <template v-else>
        {{ value }}
        <span v-if="slots.trend" class="fct-stat__trend"><slot name="trend"/></span>
      </template>
    </dd>
    <dd v-if="slots.footer && !loading" class="fct-stat__footer">
      <slot name="footer"/>
    </dd>
  </div>
</template>

<script setup>
import {useSlots} from 'vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';

defineProps({
  label: {
    type: String,
    required: true
  },
  value: {
    type: [String, Number],
    default: ''
  },
  icon: {
    type: String,
    default: ''
  },
  loading: {
    type: Boolean,
    default: false
  }
});

const slots = useSlots();
</script>
