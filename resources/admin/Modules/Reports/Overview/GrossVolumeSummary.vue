<script setup>
import translate from "@/utils/translator/Translator";

defineProps({
  summary: {
    type: Object,
    required: true,
  },
});

const directionLabel = {
  /* translators: screen-reader word read before a rising percentage */
  up: translate('Up'),
  /* translators: screen-reader word read before a falling percentage */
  down: translate('Down'),
};

// Light shades are darker than Fluctuation's 500s, which fall below 4.5:1 on white.
const changeClass = {
  up: 'text-success-700 dark:text-success-500',
  down: 'text-red-600 dark:text-red-500',
  flat: '',
};
</script>

<template>
  <template v-if="summary.message">{{ summary.message }}</template>
  <template v-for="(period, index) in summary.periods" :key="index">
    <span v-if="index" aria-hidden="true"> &middot; </span>
    <span>{{ period.label }}</span>{{ ' ' }}
    <span class="fct-custom-tooltip font-bold text-system-dark dark:text-gray-50">
      {{ period.amount.short }}
      <span class="fct-custom-tooltip-content">{{ period.amount.exact }}</span>
    </span>
    <template v-if="period.change?.text">{{ ' ' }}<span>{{ period.change.text }}</span></template>
    <template v-else-if="period.change">
      {{ ' ' }}
      <span class="font-semibold" :class="changeClass[period.change.direction]">
        <template v-if="period.change.direction !== 'flat'">
          <span class="text-inherit" aria-hidden="true">{{ period.change.direction === 'up' ? '▲' : '▼' }}</span>
          <span class="sr-only">{{ directionLabel[period.change.direction] + ' ' }}</span>
        </template>{{ period.change.percentage }}
      </span>
      {{ ' ' }}<span>{{ period.change.label }}</span>
    </template>
    <template v-if="period.note">, <span>{{ period.note }}</span></template>
  </template>
</template>
