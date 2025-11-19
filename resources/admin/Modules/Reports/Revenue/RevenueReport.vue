<script setup>
import { onMounted, onUnmounted, ref, computed, toRaw } from "vue";
import RevenueLineChart from "./RevenueLineChart.vue";
import Revenue from "./Revenue.vue";
import RevenueGroupedBy from "./RevenueGroupedBy.vue";
import RevenueReportSummary from "./RevenueReportSummary.vue";
import revenueReport from "@/Models/Reports/RevenueReportModel";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import Comparison from "./Comparison.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const appliedGroupKey = ref('default');
const reportFilter = props.reportFilter;
const params = reportFilter.applicableFilters;

const getRevenueReport = (params, groupKey = 'default') => {
  revenueReport.getReportData({
    params: {
      ...params.params,
      groupKey: groupKey,
    },
  })
}

const fetchNetRevenue = (groupKey) => {
  revenueReport.getReportData({
    params: {
      ...params.params,
      groupKey: groupKey,
    },
  });
};

const filterChartData = (groupKey) => {
  if (groupKey === revenueReport.data.appliedGroupKey) {
    return
  }

  appliedGroupKey.value = groupKey;

  getRevenueReport(params, appliedGroupKey.value)
};

const fetchDataByGroup = (groupKey) => {
  revenueReport.getReportByGroup({
    params: {
      ...params.params,
      groupKey: groupKey,
    },
  });
};

const getData = (params) => {
  getRevenueReport(params, appliedGroupKey.value);
  
  revenueReport.getReportByGroup({
    params: {
      ...params.params,
      groupKey: "billing_country",
    },
  });
};

onMounted(() => {
  reportFilter.addListener("revenue-report", getData);
  getData(params);
});

onUnmounted(() => {
  reportFilter.removeListener("revenue-report");
});
</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-revenue-report-page">
      <div class="page-heading-wrap flex items-center justify-between">
        <h1 class="page-title">
          {{ translate('Revenue') }}
        </h1>
      </div>

      <RevenueReportSummary :reportFilter="reportFilter" />

      <RevenueLineChart
          :chartData="revenueReport.data.revenueReport"
          :compareData="revenueReport.data.previousMetrics"
          :reportFilter="reportFilter"
          :appliedGroupKey="appliedGroupKey"
          @filter-data="filterChartData"
          :is-empty="!revenueReport.data.summary.gross_sale"
          :loading="revenueReport.data.isBusy.revenueReport"
      />

      <!-- comparison -->
      <Comparison
          class="my-7.5"
          :currentMetrics="revenueReport.data.revenueReport"
          :previousMetrics="revenueReport.data.previousMetrics"
          :hasComparison="props.reportFilter.data.compareType !== 'no_comparison'"
          :reportFilter="reportFilter"
          :loading="revenueReport.data.isBusy.revenueReport"
      />

      <!-- will use this in different page later-->
      <!-- <el-row>
        <el-col :lg="24">
          <ProductBarRacer
            :productBarRacer="revenueReport.data.productBarRacer"
          />
        </el-col>
      </el-row> -->
      <Revenue
        :revenueReport="revenueReport.data.revenueReport"
        :appliedGroupKey="appliedGroupKey"
        @fetch-data="fetchNetRevenue"
        :loading="revenueReport.data.isBusy.revenueReport"
      />

      <RevenueGroupedBy
        :tableData="revenueReport.data.netRevenueByGroup"
        :reportFilter="reportFilter"
        :revenueReport="revenueReport"
        @fetch-data-by-group="fetchDataByGroup"
      />
    </div>
  </UserCan>
</template>
