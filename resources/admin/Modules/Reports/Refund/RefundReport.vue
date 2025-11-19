<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import RefundLineChart from "./RefundLineChart.vue";
import Summary from "./Summary.vue";
import WeeksBetweenRefundChart from "./WeeksBetweenRefundChart.vue";
import RefundTable from "./RefundTable.vue";
import refundReport from "@/Models/Reports/RefundReportModel";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const reportFilter = props.reportFilter;

const getData = (params) => {
  refundReport.getRefundChartData({
    params: {
      ...params.params,
      groupKey: "default",
    },
  });
  refundReport.getWeeksBetweenRefund(params);
  // refundReport.getRefundSummary(params);
  getRefundDataByGroup(params);
  // refundReport.getRefundReportFluctuations(params);
};

const getRefundDataByGroup = (params) => {
  refundReport.getRefundDataByGroup({
    params: {
      ...params.params,
      groupKey: "billing_country",
    },
  });
};

const groupData = (groupKey) => {
  const params = reportFilter.applicableFilters;
  refundReport.getRefundDataByGroup({
    params: {
      ...params.params,
      groupKey: groupKey,
    },
  });
};

const fetchChartData = (selectedGroupKey) => {
  const params = reportFilter.applicableFilters;
  if (params) {
    refundReport.getRefundChartData({
      params: {
        ...params.params,
        groupKey: selectedGroupKey,
      },
    });
  }
};

onMounted(() => {
  reportFilter.addListener("refund-report", getData);
  getData(reportFilter.applicableFilters);
});

onUnmounted(() => {
  reportFilter.removeListener("refund-report");
});
</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-refund-report-page">
      <div class="page-heading-wrap flex items-center justify-between">
        <h1 class="page-title">
          {{ $t('Refunds') }}
        </h1>
      </div>

      <Summary />

      <RefundLineChart
          ref="refundChart"
          :chartData="refundReport.data.chartData"
          :compareData="refundReport.data.previousMetrics"
          :reportFilter="reportFilter"
          @fetch-chart-data="fetchChartData"
          :is-empty="!refundReport.data.summary.refund_count"
          :loading="refundReport.data.isBusy.chartData"
      />

      <WeeksBetweenRefundChart
        :chartData="refundReport.data.weeksBetweenRefund"
        :is-empty="!refundReport.data.weeksBetweenRefund.length"
        :loading="refundReport.data.isBusy.weeksBetweenRefund"
      />

      <RefundTable
        :tableData="refundReport.data.refundData"
        :currencySign="reportFilter.currentCurrencySign"
        @group-data="groupData"
        :loading="refundReport.data.isBusy.refundData"
      />
    </div>
  </UserCan>
</template>
