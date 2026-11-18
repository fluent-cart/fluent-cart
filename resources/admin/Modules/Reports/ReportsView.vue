<template>
  <div class="fct-reports-view" :class="{
    'is-collapsed': menuState.isMenuCollapsed,
    'is-expanded': menuState.isMenuExpanded && menuState.isDesktopView
  }">
    <div class="fct-reports-view-inner">
      <ReportNavLinks @update:menu-state="onMenuStateUpdate"/>

      <div class="fct-report-body" id="fct-report-body">
        <!-- setting header -->
        <SettingsHeader :heading="translate(routeTitle)" :show-save-button="false">
          <template v-if="!hideAllFilters" #action>
            <ReportFilterBar
                :filter-state="filter"
                :compare-ranges="reportFilter.data.compareRanges"
                :show-compare="showCompare"
                :show-filters="shouldRenderFilter"
                :show-date="!['subscriptions-cohorts', 'future_renewals'].includes(route.name)"
                :active-filter-count="extraFilterKeys.length"
            >
              <SubscriptionProductSelect v-if="subscriptionProductPage"/>
            </ReportFilterBar>
          </template>
          <!-- Installments keeps its own filters (plan start range, products); the page renders them here -->
          <template v-else-if="route.name === 'reports_installments'" #action>
            <div id="fct-report-header-actions" class="fct-report-filter-bar"></div>
          </template>
        </SettingsHeader>

        <div v-if="showFutureRenewalsCurrency" class="fct-report-filter-wrap">
          <AppliedFilters :filter-state="filter" :only="['currency']" />
        </div>

        <!-- Extra filters show only while set -->
        <div v-if="!hideAllFilters && extraFilterKeys.length" class="fct-report-filter-wrap fct-report-filter-chips">
          <AppliedFilters :filter-state="filter" :only="extraFilterKeys" />
          <fct-button size="small" text type="primary" @click="clearExtraFilters">{{ translate('Clear all') }}</fct-button>
        </div>

        <div class="fct-report-body-inner">

          <AdminNotice/>

          <!-- Replace the dynamic component with router-view -->
          <!-- Reports mount once the currency is resolved, so none requests a stale one -->
          <router-view v-if="metaReady && !reportFilter.data.currencyScopeLoading" v-slot="{ Component }">
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
import reportFilter, { SUBSCRIPTION_PRODUCT_ROUTES } from "@/Models/Reports/ReportFilterModel";
import { presetRange } from "@/Modules/Reports/Utils/datePresets";
import useFilterState from "@/Bits/Components/FilterDropdown/FilterState";
import Storage from "@/utils/Storage";
import AppliedFilters from "@/Bits/Components/FilterDropdown/parts/AppliedFilters.vue";
import ReportFilterBar from "@/Modules/Reports/Components/ReportFilterBar.vue";
import SubscriptionProductSelect from "@/Modules/Reports/Components/SubscriptionProductSelect.vue";
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
// Snapshot reports ignore the date and the order filters; they offer currency only, and only on a multi-currency store
const showFutureRenewalsCurrency = computed(() =>
  route.name === 'reports_installments' && filter.currencies.filter(currency => currency.value !== '__all__').length > 1
);

const filters = computed(() => {
  return reportFilter.data;
});

filter.onFilterChanged((filter) => {
  handleFilterChange(filter);
});

const subscriptionProductPage = computed(() => SUBSCRIPTION_PRODUCT_ROUTES.includes(route.name));

const shouldRenderFilter = computed(() => {
  // Compare picks its products on the page
  return !subscriptionProductPage.value && !["reports_overview", "reports_customer", "reports_installments", "reports_export", "reports_products_compare"].includes(route.name);
});

const hideAllFilters = computed(() => {
  return ['reports', "reports_installments", "reports_export"].includes(route.name);
});

const showCompare = computed(() => [
  "reports_revenue", "reports_subscriptions", "reports_products", "reports_products_compare", "reports_customer",
  "reports_orders", "reports_refunds", "reports_sources", "reports_coupons", "reports_payment_methods", "reports_locations", "reports_overview",
].includes(route.name));

// Filters beyond date, compare and currency that are set and apply on this page (the per-page rules of the chip row)
const extraFilterKeys = computed(() => {
  if (!shouldRenderFilter.value) {
    return [];
  }

  return Object.keys(filter.data.selectedFilters).filter((key) => {
    const item = filter.data.selectedFilters[key];
    if (["dateRange", "compareType", "compareDate", "currency"].includes(key)) {
      return false;
    }
    const allowed = route.meta?.report_filters;
    if (Array.isArray(allowed) && !allowed.includes(key)) {
      return false;
    }
    if (key === "orderTypes" && route.name === "reports_subscriptions") {
      return false;
    }
    if (key === "subscriptionType" && ["reports_sources", "reports_coupons", "reports_payment_methods", "reports_locations"].includes(route.name)) {
      return false;
    }

    return Array.isArray(item?.value) ? item.value.length > 0 : !!item?.value;
  });
});

const clearExtraFilters = () => {
  extraFilterKeys.value.forEach((key) => {
    delete filter.data.selectedFilters[key];
    if (key === "variation_ids") {
      filter.data.variation_ids = [];
    }
    reportFilter.resetFilterToDefault(key);
  });
  // One refresh for all of them
  filter.triggerFilterChanged();
};

let pluginAppWrap = null

const handleFilterChange = (filtersObj) => {

  // Loop through active filters and update reportFilter accordingly
  Object.entries(filtersObj).forEach(([key, filterItem]) => {

    let value = filterItem.value;
    if(filterItem.type === 'multi-list') {
      value = filterItem.value.map((v) => v.value);
    }

    switch (key) {
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

    // A saved preset shows today's days, not the days it covered when it was picked
    const preset = presetRange(reportFilter.data.rangeKey);
    if (preset && savedFilters.dateRange) {
      filter.data.selectedFilters.dateRange = {...savedFilters.dateRange, value: preset, rangeKey: reportFilter.data.rangeKey};
      filter.data.rangeKey = reportFilter.data.rangeKey;
      filter.data.dateRange = [preset[0].toDate(), preset[1].toDate()];
    } else if (savedFilters.dateRange?.value?.length === 2) {
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
  if (filter.dropdownOpen) {
    filter.toggleDropdown();
  }
});

// Before the page fetches: "All currencies" only on reports that convert
watch(() => route.name, (name) => reportFilter.setReportRoute(name), {immediate: true});

const metaReady = ref(false);

onMounted(() => {
  filter.data.showDropdown = false;
  filter.data.activeFilter = null;
  filter.data.searchQuery = '';
  reportFilter.fetchReportMeta().finally(() => {
    metaReady.value = true;
  });
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
