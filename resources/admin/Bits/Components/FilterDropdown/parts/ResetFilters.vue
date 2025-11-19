<template>
  <button @click="filter.resetFilters" class="reset-filters-btn">
    {{$t('Reset Filters')}}
  </button>
</template>

<script setup>
import { defineProps } from 'vue';

const props = defineProps({
  filterState: {
    type: [Object]
  },
});

const filter = props.filterState;
</script>
