<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";
import RefundLineChart from "./RefundLineChart.vue";
import RefundCards from "./RefundCards.vue";
import RefundBreakdown from "./RefundBreakdown.vue";
import RefundReasons from "./RefundReasons.vue";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import ReportLoadError from "../Components/ReportLoadError.vue";
import * as Card from "@/Bits/Components/Card/Card.js";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const reportFilter = props.reportFilter;
const overview = ref({data: null, loading: true, error: false});
let overviewSeq = 0;
let lastRequest = null;

// The refund chart reads refunded_amount / refund_count per period; the overview's chart is by refund date
const chartRows = (rows) => (rows || []).map(row => ({...row, refunded_amount: row.amount, refund_count: row.count}));

const getOverview = (params, groupKey = "default") => {
  const seq = ++overviewSeq;
  lastRequest = [params, groupKey];
  overview.value = {...overview.value, loading: true, error: false};
  const request = Rest.get("reports/refunds-overview", {params: {...params.params, groupKey}});
  request.then((response) => {
    if (seq === overviewSeq) overview.value = {data: response, loading: false, error: false};
  }).catch(() => {
    if (seq === overviewSeq) overview.value = {data: null, loading: false, error: true};
  });

  return request;
};

const getData = (params) => {
  if (!params) return;
  reportFilter.trackCompareRange(getOverview(params));
};

const fetchChartData = (selectedGroupKey) => {
  const params = reportFilter.applicableFilters;
  if (params) {
    reportFilter.trackCompareRange(getOverview(params, selectedGroupKey));
  }
};

const retry = () => reportFilter.trackCompareRange(getOverview(...lastRequest));

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
      <!-- A failed request has no figures: no zero refunds, and no Pro preview that asks an up-to-date Pro to update -->
      <Card.Container v-if="overview.error">
        <Card.Body>
          <ReportLoadError @retry="retry" />
        </Card.Body>
      </Card.Container>
      <template v-else>
      <RefundCards :overview="overview.data" :loading="overview.loading" :reportFilter="reportFilter" />

      <RefundLineChart
          ref="refundChart"
          :chartData="chartRows(overview.data && overview.data.chart)"
          :compareData="chartRows(overview.data && overview.data.previousChart)"
          :reportFilter="reportFilter"
          @fetch-chart-data="fetchChartData"
          :is-empty="!(overview.data && overview.data.summary && overview.data.summary.refunds)"
          :loading="overview.loading"
      />

      <RefundBreakdown :overview="overview.data" :loading="overview.loading" />

      <template v-if="overview.loading || (overview.data && 'by_product' in overview.data)">
        <ExtensionComponent name="reports.refunds.products" :rows="(overview.data && overview.data.by_product) || []" :loading="overview.loading" :report-filter="reportFilter"/>
      </template>
      <ProReportPreview
          v-else
          :title="translate('Find the products customers send back')"
          :text="translate('RefundRpt_See_Products')"
          :bullets="[translate('Most refunded products'), translate('Refund amount and rate per product')]"
          placement="feature_lock_report_refund_products"
      >
        <ProReportSample
            :title="translate('Most refunded products')"
            :columns="[translate('Product'), translate('Refunds'), translate('Refund rate')]"
            :rows="[[translate('Product A'), '500.00', '4%'], [translate('Product B'), '200.00', '2%']]"
        />
      </ProReportPreview>
      </template>

      <ExtensionComponent name="reports.refunds.timing" :report-filter="reportFilter">
        <ProReportPreview
            :title="translate('See how soon customers ask for a refund')"
            :text="translate('RefundRpt_See_Many')"
            :bullets="[translate('Refunds by weeks after the order'), translate('First refunds in the period, whatever the order date')]"
            placement="feature_lock_report_refund_timing"
        >
          <ProReportSample
              :title="translate('Weeks Between Order and Refund')"
              :columns="[translate('Weeks'), translate('Refunds')]"
              :rows="[['1', '10'], ['2', '6'], ['4', '3']]"
          />
        </ProReportPreview>
      </ExtensionComponent>

      <RefundReasons v-if="!overview.error" :reasons="overview.data && overview.data.reasons" :loading="overview.loading" />
    </div>
  </UserCan>
</template>
