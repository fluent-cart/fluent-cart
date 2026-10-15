<template>
  <div class="fct-applied-filters-wrap">
    <ul class="fct-applied-filter-list">
      <li class="fct-applied-filter fct-applied-filter-date" v-if="!only">
        <div class="fct-applied-filter-label">{{$t('Date:')}}</div>
        <div class="fct-applied-filter-value">
          <DateRangeFilter
              v-model="filter.data.dateRange"
              placement="bottom-start"
              :reRenderDate="filter.data.reRenderDate"
              :showArrow="true"
              size="small"
              @change="filter.setDateRange"
              clearable
              :filterState="filter"
          />
        </div>
      </li>

      <li class="fct-applied-filter fct-applied-filter-compare-to" v-if="!only && shouldRenderCompareToFilter">
        <CompareToFilter
            :filterState="filter"
            :compareRange="compareRange"
            :compareRanges="compareRanges"
        />
      </li>

      <template v-if="Object.keys(selectedFilters).length">
        <template
            v-for="(filterItem, filterItemKey) in selectedFilters"
            :key="filterItemKey">
          <li
              class="fct-applied-filter fct-applied-filter-select"
              v-if="shouldRenderFilter(filterItemKey)"
          >
            <div class="fct-applied-filter-label">{{ filterItem.label }}:</div>

            <div class="fct-applied-filter-value">
              <!-- Status Filter -->
              <StatusFilter
                  v-if="filterItem.type === 'list'"
                  :filter-state="filter"
                  :filter-item="filterItem"
                  :filter-item-key="filterItemKey"
                  @clear="handleRemoveFilter({ filterKey: filterItemKey, itemId: null })"
              />

              <MultiselectFilter
                  v-if="filterItem.type === 'multi-list'"
                  :filter-state="filter"
                  :filter-item="filterItem"
                  :filter-item-key="filterItemKey"
                  @clear="handleRemoveFilter({ filterKey: filterItemKey, itemId: null })"
              />

              <!-- Tree Select (variation_ids) -->
               <TreeSelectFilter
                  v-else-if="filterItemKey === 'variation_ids'"
                  :filter-state="filter"
              />
              
              <!-- <PopoverFilter
                  v-else-if="filterItemKey === 'variation_ids'"
                  :filter-state="filter"
                  :filter-item="filterItem"
                  :filter-item-key="filterItemKey"
                  clearable
              /> -->

              <CurrencyFilter
                  v-else-if="filterItemKey === 'currency'"
                  v-model="filter.data.currency"
                  :aria-label="filterItem.label"
                  :currencies="filter.currencies"
                  @clear="handleRemoveFilter({ filterKey: filterItemKey, itemId: null })"
                  @change="
                  (value) => {
                    filter.setSelectChange(value, {
                      ...filterItem,
                      value: filterItemKey,
                    });
                  }
                "
              />

              <!-- Other Filter value string -->
<!--              <template v-else>-->
<!--                {{filterItem.value}}-->
<!--              </template>-->
            </div>


          </li>
        </template>
      </template>

      <li class="fct-applied-filter-clear-wrap" v-if="!only && Object.keys(selectedFilters).length">
        <ResetFilters :filter-state="filter" />
      </li>
    </ul>

  </div>
</template>

<script setup>
import StatusFilter from "@/Bits/Components/FilterDropdown/parts/StatusFilter.vue";
import DateRangeFilter from "@/Bits/Components/FilterDropdown/parts/DateRangeFilter.vue";
import PopoverFilter from "@/Bits/Components/FilterDropdown/parts/PopoverFilter.vue";
import CurrencyFilter from "@/Bits/Components/FilterDropdown/parts/CurrencyFilter.vue";
import ResetFilters from "@/Bits/Components/FilterDropdown/parts/ResetFilters.vue";
import { onMounted, watch, computed } from "vue";
import MultiselectFilter from "@/Bits/Components/FilterDropdown/parts/MultiselectFilter.vue";
import CompareToFilter from "@/Bits/Components/FilterDropdown/parts/CompareToFilter.vue";
import TreeSelectFilter from "@/Bits/Components/FilterDropdown/parts/TreeSelectFilter.vue";
import {useRoute} from "vue-router";

const props = defineProps({
  filterState: {
    type: [Object],
  },
  only: {
    type: Array,
    default: null,
  },
  compareRange: {
    type: Object,
    default: null,
  },
  compareRanges: {
    type: Object,
    default: null,
  },
});

const filter = props.filterState;
const router = useRoute();

const handleRemoveFilter = ({ filterKey, itemId }) => {
  filter.removeFilter(filterKey, itemId);
};

const shouldRenderFilter = (key) => {
  if (props.only && !props.only.includes(key)) {
    return false;
  }

  // always skip internal filters
  if (["dateRange", "compareType", "compareDate"].includes(key)) {
    return false;
  }

  // skip specific filters on customer report
  if (router.name === "reports_customer") {
    return false;
  }

  return true;
};

const shouldRenderCompareToFilter = computed(() => {
  return [
    "reports_revenue", 
    "reports_subscriptions", 
    "reports_products", 
    "reports_products_compare",
    "reports_customer", 
    "reports_sales",
    'reports_orders',
    "reports_refunds",
    "reports_sources",
  ].includes(router.name);
});

const selectedFilters = computed(() => {
  return Object.keys(filter.data.selectedFilters)
  .filter(key => !(key === "orderTypes" && router.name === 'reports_subscriptions'))
  .filter(key => !(key === "subscriptionType" && router.name === 'reports_sources'))
  .reduce((acc, key) => {
    acc[key] = filter.data.selectedFilters[key];
    return acc;
  }, {});
});

watch(filter.data.dateRange, (newValue) => {
});
</script>
