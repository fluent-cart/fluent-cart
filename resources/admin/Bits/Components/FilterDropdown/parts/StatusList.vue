<template>
  <ul class="fct-filter-dropdown-active-filter-list">
    <li
        v-for="(filterOption, index) in filter.activeFilterOptions.options"
        :key="index"
        class="fct-filter-active-option-item"
        :class="{ 'is-selected': filter.isFilterOptionSelected(filterOption) }"
    >
      <div
          class="fct-filter-dropdown-active-button"
          @click="filter.selectFilterOption(filterOption)"
      >
        {{ filterOption.label }}
      </div>
    </li>
  </ul>
</template>

<script setup>
const props = defineProps({
  filterState: {
    type: [Object]
  },
});

const filter = props.filterState;
</script>
