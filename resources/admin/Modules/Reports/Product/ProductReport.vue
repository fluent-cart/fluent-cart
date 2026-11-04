<script setup>
import {computed, onMounted, onUnmounted, ref} from "vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import SummaryLoader from "../Components/SummaryLoader.vue";
import ProductSalesChart from "./ProductSalesChart.vue";
import ProductSalesTable from "./ProductSalesTable.vue";
import ProductDetailPreview from "./ProductDetailPreview.vue";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import {formatNumber} from "../Utils/formatNumber";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const reportFilter = props.reportFilter;
const loading = ref(true);
// A grouping change reloads only the chart's view; the cards and the table keep their figures
const chartLoading = ref(true);
const groupKey = ref('default');
// The products table row shown in the detail drawer
const selectedProduct = ref(null);
const data = ref({summary: {}, previous: null, products: [], chart: []});
let latestRequest = 0;

const fetchData = (filters, chartOnly = false) => {
  const request = ++latestRequest;
  // A chart-only request leaves a full load's loader on
  if (!chartOnly) {
    loading.value = true;
    // The drawer's figures belong to the old filters
    selectedProduct.value = null;
  }
  chartLoading.value = true;
  const call = Rest.get('reports/product-sales', {params: {...(filters || reportFilter.applicableFilters).params, groupKey: groupKey.value}});
  reportFilter.trackCompareRange(call);
  call.then(response => {
    if (request === latestRequest) {
      data.value = response;
    }
  }).catch(error => {
    if (request === latestRequest) {
      Notify.error(error);
    }
  }).finally(() => {
    if (request === latestRequest) {
      loading.value = false;
      chartLoading.value = false;
    }
  });
};

const setGroupKey = (value) => {
  groupKey.value = value;
  fetchData(null, true);
};

const money = (value) => reportFilter.formatAmount(value || 0, {cents: true});
/* translators: %1$s - left empty; the text follows a change in percentage points, e.g. "+1.2%" then " pts" */
const pointsSuffix = translate('%1$s pts', '');

const cards = computed(() => {
  const summary = data.value.summary || {};
  const previous = data.value.previous;
  // null: no previous figure to compare with. A rate changes in points, not as a share of itself
  const change = (key, points = false) => {
    if (!previous || (!points && !previous[key])) {
      return null;
    }
    return points ? (summary[key] || 0) - (previous[key] || 0) : (summary[key] - previous[key]) / Math.abs(previous[key]) * 100;
  };
  const card = (key, title, value, info, extra = {}) => ({
    key, title, value, info, change: change(key, !!extra.percentage), previous: previous ? previous[key] : null, ...extra,
  });

  return [
    card('net', translate('Net sales'), money(summary.net),
      translate('ProductRpt_Customers_Paid'),
      {money: true}),
    card('units', translate('Units sold'), formatNumber(summary.units || 0),
      translate('Quantity sold, including renewals. A signup fee does not count as a unit.')),
    card('orders', translate('Orders'), formatNumber(summary.orders || 0),
      translate('Paid orders in this period, renewals included.')),
    card('new_customers', translate('New customers'), formatNumber(summary.new_customers || 0),
      translate('CustomerRpt_Customers_Whose')),
    card('refund_rate', translate('Refund rate'), (summary.refund_rate || 0) + '%',
      translate('Refunds as a share of what customers paid, for orders placed in this period.'),
      {liability: true, percentage: true}),
    card('average_order', translate('Average order value'), money(summary.average_order),
      translate('Net sales divided by orders.'),
      {money: true}),
  ];
});

onMounted(() => {
  reportFilter.addListener('product-sales', fetchData);
  fetchData();
});

onUnmounted(() => {
  reportFilter.removeListener('product-sales');
  latestRequest++;
});
</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-product-report-page">
      <PageHeading :title="translate('Products')"/>

      <SummaryLoader v-if="loading" :loading="loading" :count="6"/>
      <div v-else class="fct-report-order-summary summary-col-6">
        <div class="summary-item" v-for="item in cards" :key="item.key">
          <div class="summary-item-inner">
            <div class="value">
              <div class="inner-value">
                <ReportSummary :amount="item.value" :title="item.title"/>
              </div>
              <Fluctuation
                  v-if="item.change !== null"
                  :fluctuation="Math.round(item.change * 10) / 10"
                  :amount="item.money ? item.previous / 100 : item.previous"
                  :has-currency="!!item.money"
                  :is-percentage="!!item.percentage"
                  :is-liability="!!item.liability"
                  :fluctuation-suffix="item.percentage ? pointsSuffix : ''"
              />
            </div>
            <div class="title">
              {{ item.title }}
              <el-tooltip :content="item.info" placement="top" popper-class="fct-tooltip" effect="dark" :trigger="['hover', 'focus']">
                <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
                  <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true"/>
                </button>
              </el-tooltip>
            </div>
          </div>
        </div>
      </div>

      <ProductSalesChart
          :points="data.chart || []"
          :loading="chartLoading"
          :group-key="groupKey"
          :report-filter="reportFilter"
          @update:group-key="setGroupKey"
      />

      <ProductSalesTable
          :products="data.products || []"
          :total-net="(data.summary || {}).net || 0"
          :loading="loading"
          :report-filter="reportFilter"
          @open="(product) => selectedProduct = product"
      />

      <ExtensionComponent
          name="reports.products.detail"
          :product="selectedProduct"
          :total-net="(data.summary || {}).net || 0"
          :report-filter="reportFilter"
          @close="selectedProduct = null"
      >
        <ProductDetailPreview :product="selectedProduct" @close="selectedProduct = null"/>
      </ExtensionComponent>
    </div>
  </UserCan>
</template>
