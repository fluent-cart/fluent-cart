<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-order-report-page">

      <div class="page-heading-wrap flex items-center justify-between">
        <h1 class="page-title">
          {{ $t('Orders') }}
        </h1>
      </div>

      <Summary :orderReport="orderReport" :reportFilter="reportFilter" />

      <OrderLineChart
          :chartData="orderReport.data.orderChartData"
          :reportFilter="reportFilter"
          @fetch-chart-data="fetchChartData"
          :is-empty="!orderReport.data.summary.gross_sale"
      />

      <MonthlySummary :orderReport="orderReport" />

      <AverageResults :orderReport="orderReport" :reportFilter="reportFilter" 
        v-if="orderReport.data.summary.tax_total || orderReport.data.summary.shipping_total"
      />

      <NewVsReturningCustomer
        :tableData="orderReport.data.newVsReturning"
        :reportFilter="reportFilter"
      />

      <OrderGroupedBy
        :tableData="orderReport.data.orderByGroup"
        :reportFilter="reportFilter"
      />
      <OrderHeatMap :chartData="orderReport.data.orderByDayAndHour" />
      <el-row :gutter="30">
        <el-col :lg="12">
          <SpendByDay :reportFilter="reportFilter" ref="chartRef" :chartData="orderReport.data.grossSaleByDay" />
        </el-col>
        <el-col :lg="12">
          <SpendByHour :chartData="orderReport.data.grossSaleByHour" />
        </el-col>
        <el-col :lg="12">
          <ItemCountDistribution
            ref="chartRef"
            :chartData="orderReport.data.itemCountDistribution"
          />
        </el-col>
        <el-col :lg="12">
          <OrderValueDistribution
            :chartData="orderReport.data.orderValueDistribution"
          />
        </el-col>

        <el-col :lg="12">
          <AverageOrderGross :chartData="orderReport.data.orderChartData" :is-empty="!orderReport.data.summary.gross_sale" />
        </el-col>

        <el-col :lg="12">
          <AverageOrderItems :chartData="orderReport.data.orderChartData" :is-empty="!orderReport.data.summary.order_count" />
        </el-col>

        <el-col :lg="12">
          <OrderCompletionTime
            :chartData="orderReport.data.orderCompletionTime"
          />
        </el-col>
      </el-row>
    </div>
  </UserCan>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch, computed } from "vue";
import orderReport from "@/Models/Reports/OrderReportModel";
import Summary from "@/Modules/Reports/Order/Summary.vue";
import OrderHeatMap from "@/Modules/Reports/Order/OrderHeatMap.vue";
import OrderLineChart from "@/Modules/Reports/Order/OrderLineChart.vue";
import MonthlySummary from "@/Modules/Reports/Order/MonthlySummary.vue";
import AverageResults from "@/Modules/Reports/Order/AverageResults.vue";
import NewVsReturningCustomer from "./NewVsReturningCustomer.vue";
import OrderGroupedBy from "./OrderGroupedBy.vue";
import SpendByDay from "./GrossSaleByDay.vue";
import ItemCountDistribution from "./ItemCountDistribution.vue";
import SpendByHour from "./GrossSaleByHour.vue";
import OrderCompletionTime from "./OrderCompletionTime.vue";
import OrderValueDistribution from "./OrderValueDistribution.vue";
import AverageOrderGross from "./AverageOrderGross.vue";
import AverageOrderItems from "./AverageOrderItems.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const reportFilter = props.reportFilter;
const getData = (params) => {
  if (!params) return;

  // orderReport.getOrderReportFluctuations(params);
  
  orderReport.getOrderChartData({
    params: {
      ...params.params,
      groupKey: "",
    },
  });

  orderReport.getNewVsReturningCustomer(params);
  orderReport.getReportByDayAndHour(params);
  orderReport.getItemCountDistribution(params);
  orderReport.getOrderCompletionTime(params);
  orderReport.getOrderValueDistribution(params);
};

const fetchChartData = (selectedGroupKey) => {
  const params = reportFilter.applicableFilters;
  if (params) {
    orderReport.getOrderChartData({
      params: {
        ...params.params,
        groupKey: selectedGroupKey,
      },
    });
  }
};

onMounted(() => {
  reportFilter.addListener("order-report", getData);
  getData(reportFilter.applicableFilters);
  if (reportFilter.value) {
  }
});

onUnmounted(() => {
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
