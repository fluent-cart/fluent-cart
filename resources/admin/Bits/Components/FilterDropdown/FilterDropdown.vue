<template>
  <div ref="dropdownRef" class="fct-filter-dropdown-container" @click.stop>
    <fct-button v-if="label" size="small" class="fct-filter-dropdown-text-button" @click="filter.toggleDropdown">
      <DynamicIcon name="Filter" class="w-4 h-4"/>
      <span>{{ label }}</span>
      <span v-if="count" class="fct-filter-dropdown-count">{{ count }}</span>
    </fct-button>
    <IconButton v-else class="fct-filter-dropdown-toggle-button" size="small" tag="button" @click="filter.toggleDropdown">
      <DynamicIcon name="Filter"/>
    </IconButton>

    <transition
        name="fade-scale"
        enter-active-class="transition ease-out duration-200"
        leave-active-class="transition ease-in duration-150"
        enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100"
        leave-from-class="opacity-100 scale-100"
        leave-to-class="opacity-0 scale-95"
    >

      <div v-if="filter.dropdownOpen" class="fct-filter-dropdown">
        <template v-if="isProActive">
          <FilterHeader :filter-state="filter"/>

          <div class="fct-filter-dropdown-body">
            <!-- Show filter options if a filter is selected -->
            <SelectedFilters
                v-if="filter.openFilter"
                :filter-state="filter"
            />

            <!-- Otherwise show top-level filters -->
            <FilterOptionList v-else :filter-state="filter"/>

          </div>
        </template>

        <div v-else>
          <ProFeatureNotice
              class="py-7.5"
              placement="feature_lock_report_advanced_filtering"
              :title="translate('Upgrade to Pro for filtering')"
              :text="translate('This feature is only available in FluentCart Pro.')"
          />
        </div>
      </div>

    </transition>
  </div>

</template>

<script setup>
import {onBeforeUnmount, onMounted, ref, computed} from "vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import FilterHeader from "@/Bits/Components/FilterDropdown/parts/FilterHeader.vue";
import SelectedFilters from "@/Bits/Components/FilterDropdown/parts/SelectedFilters.vue";
import FilterOptionList from "@/Bits/Components/FilterDropdown/parts/FilterOptionList.vue";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import AppConfig from "@/utils/Config/AppConfig";


const props = defineProps({
  filterState: {
    type: [Object]
  },
  // A text button with a count of active filters, as in the report title bar; the icon button otherwise
  label: {
    type: String,
    default: '',
  },
  count: {
    type: Number,
    default: 0,
  },
});

const dropdownRef = ref(null);
const filter = props.filterState;
const isProActive = AppConfig.get('app_config.isProActive');

const filters = [
  {
    label: translate("Order Type"),
    icon: "AppsLine",
    type: "multi-list",
    value: "orderTypes",
    disableRoutes:[
      "reports_customer",
      "reports_subscriptions",
    ],
    options: [
      {label: translate("All"), value: "all"},
      {label: translate("One time"), value: "payment"},
      {label: translate("Subscription"), value: "subscription"},
      {label: translate("Renewal"), value: "renewal"},
      {label: translate("Installment sign-up"), value: "installment"},
      {label: translate("Installment payment"), value: "installment_payment"},
    ],
  },
  {
    label: translate("Subscription Type"),
    icon: "Reload",
    type: "list",
    value: "subscriptionType",
    disableRoutes:[
        "reports_subscriptions",
        "reports_revenue",
        "reports_products",
        "reports_customer",
        "reports_refunds",
        "reports_orders",
        "reports_sources",
        "reports_coupons",
        "reports_payment_methods",
        "reports_locations",
    ],
    options: [
      {label: translate("New"), value: "subscription"},
      {label: translate("Renewal"), value: "renewal"},
      {label: translate("Expired"), value: "expired"},
      {label: translate("Canceled"), value: "canceled"},
    ],
  },
  {
    label: translate("Currency"),
    icon: "Currency",
    type: "currency",
    value: "currency",
    disableRoutes:[],
  },
  {
    label: translate("Products"),
    icon: "ListView",
    type: "tree-select",
    value: "variation_ids",
    // Sources offers it too: without Pro's advanced filter it is the only way to pick products there
    disableRoutes:[
      "reports_customer",
    ],
  },

];

filter.setFilterOption(filters);


const handleClickOutside = (event) => {
  const dropdownEl = dropdownRef.value;
  const clickedEl = event.target;
  const pickerPanels = document.querySelectorAll('.el-picker-panel');

  const clickedInsideAnyDatePicker = Array.from(pickerPanels).some(panel =>
      panel.contains(clickedEl)
  );

  // Allow clicks inside the dropdown or the date picker panel
  if (
      dropdownEl?.contains(clickedEl) ||
      clickedInsideAnyDatePicker
  ) {
    return;
  }

  // Close the filter dropdown
  //filter.selectActiveFilter(null);
  filter.data.searchQuery = '';
  if (filter.dropdownOpen) {
    filter.toggleDropdown();
  }
}


onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
