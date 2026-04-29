<template>
  <ul class="fct-filter-dropdown-list">
    <template v-if="filter.visibleFilters.length">
      <li class="fct-filter-dropdown-list-item"
          v-for="(filterOption, filterIndex) in filter.visibleFilters"
          :key="filterIndex"
      >
        <div class="fct-filter-dropdown-button" @click="filter.selectActiveFilter(filterOption)">
          <DynamicIcon :name="filterOption.icon"/>
          {{ filterOption.label }}
        </div>
      </li>
    </template>

    <li v-else class="fct-filter-dropdown-list-item filter-not-found">
      {{
        filter.data.searchQuery
            ? translate('No filter found')
            : translate('All filters selected')
      }}
    </li>
  </ul>
</template>

<script setup>
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  filterState: {
    type: [Object]
  },
});

const filter = props.filterState;
</script>

