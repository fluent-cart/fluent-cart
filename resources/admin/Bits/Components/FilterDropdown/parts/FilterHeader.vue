<template>
  <div class="fct-filter-dropdown-header">
    <div v-if="filter.openFilter" class="fct-filter-back-wrapper">
      <button class="fct-filter-back-button" @click="filter.selectActiveFilter(null)">
        <DynamicIcon name="ArrowLeft"/>
      </button>

      <span class="fct-filter-active-label">
        {{ filter.activeFilterOptions.label }}
      </span>
    </div>

    <div v-else class="fct-filter-dropdown-search-input">
      <fct-input
          v-model="filter.data.searchQuery"
          :placeholder="$t('Search Filter...')"
          clearable
      />
    </div>
  </div>
</template>

<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  filterState: {
    type: [Object]
  },
});

const filter = props.filterState;
</script>
