<template>
  <div class="fct-reports-view" :class="{
    'is-collapsed': menuState.isMenuCollapsed,
    'is-expanded': menuState.isMenuExpanded && menuState.isDesktopView
  }">
    <div class="fct-reports-view-inner">
      <ReportNavLinks @update:menuState="onMenuStateUpdate"/>

      <div class="fct-report-body" id="fct-report-body">
        <!-- setting header -->
        <SettingsHeader :heading="translate(routeTitle)" :show-save-button="false"/>

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
import { computed, onMounted, onUnmounted, onBeforeUnmount, ref, watch } from "vue";
import { useRoute } from "vue-router";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import Alert from "@/Bits/Components/Alert.vue";
import useFilterState from "@/Bits/Components/FilterDropdown/FilterState";
import Storage from "@/utils/Storage";
import AppliedFilters from "@/Bits/Components/FilterDropdown/parts/AppliedFilters.vue";
import FilterDropdown from "@/Bits/Components/FilterDropdown/FilterDropdown.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import ReportNavLinks from "@/Modules/Reports/Components/ReportNavLinks.vue";
import translate from "@/utils/translator/Translator";
import SettingsHeader from "../Settings/Parts/SettingsHeader.vue";


const menuState = ref({
  isMenuCollapsed: true,
  isMenuExpanded: false,
  isDesktopView: true
});
const filter = useFilterState();
const route = useRoute();
const routeTitle = ref(route.meta?.title);

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

let pluginAppWrap = null

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

const onMenuStateUpdate = (state) => {
  menuState.value = state;
};

// add watch on route.name
watch(() => route.name, (newVal, oldVal) => {
  routeTitle.value = route.meta?.title || 'Reports';
});

onMounted(() => {
  reportFilter.fetchReportMeta();
  retrieveFilters();
  pluginAppWrap = document.getElementById('fluent_cart_plugin_app');
  if (pluginAppWrap) {
    pluginAppWrap.classList.add('fct_report_page_plugin_app_wrap');
  }
});

onUnmounted(() => {
  if (pluginAppWrap) {
    pluginAppWrap.classList.remove('fct_report_page_plugin_app_wrap');
  }
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
