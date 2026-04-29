<template>
  <div ref="dropdownRef" class="fct-filter-dropdown-container" @click.stop>
    <IconButton class="fct-filter-dropdown-toggle-button" size="small" tag="button" @click="filter.toggleDropdown">
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
                v-if="filter.data.activeFilter != null"
                :filter-state="filter"
            />

            <!-- Otherwise show top-level filters -->
            <FilterOptionList v-else :filter-state="filter"/>

          </div>
        </template>

        <div v-else>
          <ProFeatureNotice
              class="py-7.5"
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
});

const dropdownRef = ref(null);
const filter = props.filterState;
const isProActive = AppConfig.get('app_config.isProActive');

const filters = [
  // {
  //   label: translate("Order Status"),
  //   icon: "Cart",
  //   type: "multi-list",
  //   value: "orderStatus",
  //   disableRoutes:[
  //     "reports_products",
  //     "reports_customer",
  //     "reports_subscriptions",
  //     "reports_refunds",
  //   ],
  //   options: [
  //     {label: translate("All"), value: "all"},
  //     {label: translate("Processing"), value: "processing"},
  //     {label: translate("Completed"), value: "completed"},
  //     {label: translate("On Hold"), value: "on-hold"},
  //     {label: translate("Canceled"), value: "canceled"},
  //     {label: translate("Failed"), value: "failed"},
  //   ],
  // },
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
    ],
  },
  {
    label: translate("Subscription Type"),
    icon: "Reload",
    type: "list",
    value: "subscriptionType",
    disableRoutes:[
        "reports_revenue",
        "reports_products",
        "reports_customer",
        "reports_sales",
        "reports_refunds",
        "reports_orders",
        "reports_sales",
    ],
    options: [
      {label: translate("Active"), value: "subscription"},
      {label: translate("Renewal"), value: "renewal"},
      {label: translate("Expired"), value: "expired"},
      {label: translate("Canceled"), value: "canceled"},
    ],
  },
  // {
  //   label: translate("Payment Status"),
  //   icon: "BankCard",
  //   type: "multi-list",
  //   value: "paymentStatus",
  //   disableRoutes:[
  //     "reports_products",
  //     "reports_customer",
  //     "reports_subscriptions",
  //     "reports_refunds",
  //   ],
  //   options: [
  //     {label: translate("All"), value: "all"},
  //     {label: translate("Paid"), value: "paid"},
  //     {label: translate("Pending"), value: "pending"},
  //     {label: translate("Partially Paid"), value: "partially_paid"},
  //   ],
  // },
  // {
  //   label: translate("Date"),
  //   icon: "Calendar",
  //   type: "date",
  //   value: "dateRange",
  // },
  {
    label: translate("Currency"),
    icon: "Currency",
    type: "currency",
    value: "currency",
    disableRoutes:[
      "reports_products",
      "reports_customer",
      "reports_subscriptions",
    ],
  },
  {
    label: translate("Products"),
    icon: "ListView",
    type: "tree-select",
    value: "variation_ids",
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
