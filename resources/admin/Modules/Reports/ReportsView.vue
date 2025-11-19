<template>
  <div class="fct-reports-view">
    <div class="fct-reports-view-inner">
      <ReportFilterHeader :filterState="filter" />

      <div class="fct-report-body fct-layout-width" id="fct-report-body">
        <div v-if="!hideAllFilters || shouldRenderFilter" class="fct-report-filter-wrap">
          <AppliedFilters v-if="!hideAllFilters" :filter-state="filter" />

          <div v-if="!hideAllFilters && shouldRenderFilter" class="fct-report-filter-button">
            <FilterDropdown :filter-state="filter" />
          </div>
        </div>

        <div class="fct-report-body-inner">

          <AdminNotice/>

          <Alert
              class="mb-5 alert-yellow"
              v-if="
              filters.storeMode !== filters.filterMode &&
              filters.filterMode &&
              filters.filterMode !== ''
            "
              icon="InformationFill"
              :content="
              $t(
                `Your store is in ${filters.storeMode} mode but you are watching data in ${filters.filterMode} mode.`
              )
            "
          />

          <!-- Replace the dynamic component with router-view -->
          <router-view v-slot="{ Component }">
            <component :is="Component" :reportFilter="reportFilter" />
          </router-view>
        </div>

      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, watch } from "vue";
import { useRoute } from "vue-router";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import Alert from "@/Bits/Components/Alert.vue";
import useFilterState from "@/Bits/Components/FilterDropdown/FilterState";
import ReportFilterHeader from "./Components/ReportFilterHeader.vue";
import Storage from "@/utils/Storage";
import AppliedFilters from "@/Bits/Components/FilterDropdown/parts/AppliedFilters.vue";
import FilterDropdown from "@/Bits/Components/FilterDropdown/FilterDropdown.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import AppConfig from "@/utils/Config/AppConfig";


const filter = useFilterState();
const route = useRoute();

const filters = computed(() => {
  return reportFilter.data;
});

filter.onFilterChanged((filter) => {
  handleFilterChange(filter);
});

const shouldRenderFilter = computed(() => {
  return !["reports_overview", "reports_customer", "future_renewals", "reports_sources"].includes(route.name);
});

const hideAllFilters = computed(() => {
  return ['reports_overview', 'reports', "future_renewals"].includes(route.name);
});

const hasPro = computed(() =>
  AppConfig.get('app_config.isProActive')
);

const handleFilterChange = (filtersObj) => {

  // Loop through active filters and update reportFilter accordingly
  Object.entries(filtersObj).forEach(([key, filterItem]) => {

    let value = filterItem.value;
    if(filterItem.type === 'multi-list') {
      value = filterItem.value.map((v) => v.value);
    }

    switch (key) {
      case "orderStatus":
      case "paymentStatus":
      case "currency":
      case "compareType":
      case "compareDate":
      case "subscriptionType":
      case "orderTypes":
        reportFilter.data[key] = value;
        break;
      case "dateRange":
        reportFilter.data.dateRange = filterItem.value;
        reportFilter.data.rangeKey = filterItem.rangeKey;
        break;
      case "variation_ids":
        reportFilter.data[key] = Array.isArray(filterItem.value)
          ? filterItem.value.map((v) => v.id)
          : [];
        break;
    }
  });

  reportFilter.onFilterChange();
};

const retrieveFilters = () => {
  const savedFilters = filter.retrieveSavedFilters();

  if (savedFilters) {
    filter.data.selectedFilters = { ...savedFilters };

    if (savedFilters.dateRange?.value?.length === 2) {
      filter.data.dateRange = [
        savedFilters.dateRange.value[0],
        savedFilters.dateRange.value[1],
      ];
    }
  } else {
    filter.data.selectedFilters = {};
  }
};

onMounted(() => {
  reportFilter.fetchReportMeta();
  retrieveFilters();
});

onBeforeUnmount(() => {
  Storage.remove("product_variations");
});
</script>

<style scoped>
.alert-yellow {
  background-color: rgba(244, 166, 83, 0.1); /* Light yellow */
  border-color: rgba(255, 223, 0, 0.3); /* Slightly darker yellow for border */
  color: black; /* Yellow text color */
}
</style>
