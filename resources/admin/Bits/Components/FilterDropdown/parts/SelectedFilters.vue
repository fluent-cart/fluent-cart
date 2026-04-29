<template>
  <div class="fct-filter-dropdown-active-filter">
    <StatusList
        v-if="filter.activeFilterOptions.type === 'list'"
        :filter-state="filter"
    />

    <MultiList
        v-if="filter.activeFilterOptions.type === 'multi-list'"
        :filter-state="filter"
    />

    <DateRangeFilter
        v-if="filter.activeFilterOptions.type === 'date'"
        v-model="filter.data.dateRange"
        @change="filter.setDateRange"
    />

    <CurrencyFilter
        v-if="filter.activeFilterOptions.type === 'currency'"
        v-model="filter.data.currency"
        :currencies="filter.currencies"
        @change="filter.setSelectChange"
    />
    <TreeSelectFilter
        v-if="filter.activeFilterOptions.type === 'tree-select' "
        :filter-state="filter"
    />

  </div>
</template>

<script setup>
import TreeSelectFilter from "@/Bits/Components/FilterDropdown/parts/TreeSelectFilter.vue";
import DateRangeFilter from "@/Bits/Components/FilterDropdown/parts/DateRangeFilter.vue";
import CurrencyFilter from "@/Bits/Components/FilterDropdown/parts/CurrencyFilter.vue";
import StatusList from "@/Bits/Components/FilterDropdown/parts/StatusList.vue";
import MultiList from "@/Bits/Components/FilterDropdown/parts/MultiList.vue";

const props = defineProps({
  filterState: {
    type: [Object]
  },
});

const filter = props.filterState;

</script>

