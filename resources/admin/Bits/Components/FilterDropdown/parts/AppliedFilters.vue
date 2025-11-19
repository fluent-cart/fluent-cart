<template>
  <div class="fct-applied-filters-wrap">
    <ul class="fct-applied-filter-list">
      <li class="fct-applied-filter fct-applied-filter-date">
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

      <li class="fct-applied-filter fct-applied-filter-compare-to" v-if="shouldRenderCompareToFilter">
        <CompareToFilter
            :filterState="filter"
        />
      </li>

      <template v-if="Object.keys(selectedFilters).length">
        <template
            v-for="(filterItem, filterItemKey) in selectedFilters"
            :key="filterItemKey">
          <li
              :class="`fct-applied-filter ${
              filterItemKey === 'variation_ids' ? 'fct-applied-filter-variation' : ''
            } ${filterItemKey === 'orderTypes' ? 'fct-applied-filter-order-types' : ''}`"
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
              />

              <MultiselectFilter
                  v-if="filterItem.type === 'multi-list'"
                  :filter-state="filter"
                  :filter-item="filterItem"
                  :filter-item-key="filterItemKey"
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
                  :currencies="filter.currencies"
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


            <RemoveButton
                v-if="filterItemKey !== 'variation_ids'"
                :filterKey="filterItemKey"
                :itemId="filterItemKey === 'variation_ids' ? filterItem.value[0].id : null"
                @remove="handleRemoveFilter"
            />
          </li>
        </template>
      </template>

      <li class="fct-applied-filter-clear-wrap" v-if="Object.keys(selectedFilters).length">
        <ResetFilters :filter-state="filter" />
      </li>
    </ul>

  </div>
</template>

<script setup>
import RemoveButton from "@/Bits/Components/FilterDropdown/parts/RemoveButton.vue";
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
});

const filter = props.filterState;
const router = useRoute();

const handleRemoveFilter = ({ filterKey, itemId }) => {
  filter.removeFilter(filterKey, itemId);
};

const shouldRenderFilter = (key) => {
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
  .reduce((acc, key) => {
    acc[key] = filter.data.selectedFilters[key];
    return acc;
  }, {});
});

watch(filter.data.dateRange, (newValue) => {
  console.log("dateRange changed:", newValue);
});
</script>
