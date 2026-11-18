<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-order-report-page">
      <Summary :orderReport="orderReport" :reportFilter="reportFilter" :failedOrders="failed.count" />

      <OrderLineChart
          :chartData="orderReport.data.orderChartData"
          :previousChartData="orderReport.data.previousChartData || []"
          :reportFilter="reportFilter"
          @fetch-chart-data="fetchChartData"
          :is-empty="!orderReport.data.summary.gross_sale"
      />

      <OrderMix :mix="mix.data" :loading="mix.loading" />

      <NewVsReturningCustomer
        :tableData="orderReport.data.newVsReturning"
        :reportFilter="reportFilter"
      />

      <div class="grid grid-cols-1 gap-x-[30px] min-[1200px]:grid-cols-2">
        <div class="min-w-0">
          <AverageOrderChart variant="gross" :chartData="orderReport.data.orderChartData" :is-empty="!orderReport.data.summary.gross_sale" />
        </div>

        <div class="min-w-0">
          <AverageOrderChart variant="items" :chartData="orderReport.data.orderChartData" :is-empty="!orderReport.data.summary.order_count" />
        </div>
      </div>

      <ExtensionComponent name="reports.orders.patterns" :report-filter="reportFilter">
        <ProReportPreview
            :title="translate('See when and how customers order')"
            :text="translate('OrderRpt_Find_Busiest')"
            :bullets="[translate('Orders by day and hour'), translate('Sales by weekday and by hour'), translate('Order value and item count spread'), translate('Time to complete an order')]"
            placement="feature_lock_report_order_patterns"
        >
          <ProReportSample
              :title="translate('Orders by Day and Hour')"
              :columns="[translate('Day'), '9:00', '12:00', '15:00', '18:00']"
              :rows="[[translate('Mon'), '4', '8', '6', '3'], [translate('Tue'), '5', '9', '7', '2'], [translate('Wed'), '3', '7', '8', '4']]"
          />
        </ProReportPreview>
      </ExtensionComponent>
    </div>
  </UserCan>
</template>

<script setup>
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";
import { onMounted, onUnmounted, ref, watch, computed } from "vue";
import orderReport from "@/Models/Reports/OrderReportModel";
import Summary from "@/Modules/Reports/Order/Summary.vue";
import OrderLineChart from "@/Modules/Reports/Order/OrderLineChart.vue";
import OrderMix from "./OrderMix.vue";
import NewVsReturningCustomer from "./NewVsReturningCustomer.vue";
import Rest from "@/utils/http/Rest";
import {createRequestQueue} from "../Utils/requestQueue";
import AverageOrderChart from "./AverageOrderChart.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const reportFilter = props.reportFilter;
const failed = ref({count: 0});
let failedSeq = 0;

const getFailedOrders = (params) => {
  const seq = ++failedSeq;
  return Rest.get('reports/failed-orders', {params: params.params}).then((response) => {
    if (seq === failedSeq) failed.value = {count: response.count || 0};
  });
};

const mix = ref({data: null, loading: true});
let mixSeq = 0;

const getOrderMix = (params) => {
  const seq = ++mixSeq;
  mix.value = {...mix.value, loading: true};
  return Rest.get('reports/order-mix', {params: params.params}).then((response) => {
    if (seq === mixSeq) mix.value = {data: response, loading: false};
  }).catch(() => {
    if (seq === mixSeq) mix.value = {data: null, loading: false};
  });
};

// At most three report requests run at once, in page order: a small host is not hit by them all together
const queue = createRequestQueue(3);

const getData = (params) => {
  if (!params) return;

  // A filter change drops the requests still waiting for the old filters
  queue.clear();
  queue.add(() => reportFilter.trackCompareRange(orderReport.getOrderChartData({
    params: {
      ...params.params,
      // "" is not whitelisted server-side and used to be rewritten to
      // 'payment_method', desyncing the zero-fill labels — request Auto
      // explicitly so the selector's "Auto (…)" label stays honest.
      groupKey: "default",
    },
  })));
  queue.add(() => getFailedOrders(params));
  queue.add(() => getOrderMix(params));
  queue.add(() => orderReport.getNewVsReturningCustomer(params));
};

const fetchChartData = (selectedGroupKey) => {
  const params = reportFilter.applicableFilters;
  if (params) {
    reportFilter.trackCompareRange(orderReport.getOrderChartData({
      params: {
        ...params.params,
        groupKey: selectedGroupKey,
      },
    }));
  }
};

onMounted(() => {
  reportFilter.addListener("order-report", getData);
  getData(reportFilter.applicableFilters);
  if (reportFilter.value) {
  }
});

onUnmounted(() => {
  queue.clear();
  reportFilter.removeListener("order-report", getData, false);
  if (reportFilter.value) {
  }
});

// Watch for changes in reportFilter to re-attach the listener if necessary
// watch(
//   () => props.reportFilter,
//   (newFilter) => {
//     if (reportFilter.value) {
//       reportFilter.value.removeListener("order-report");
//     }
//     reportFilter.value = newFilter;
//     if (reportFilter.value) {
//       reportFilter.value.addListener("order-report", getData);
//       getData(reportFilter.value.applicableFilters);
//     }
//   },
//   { immediate: true }
// );
</script>
