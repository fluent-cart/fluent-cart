<script setup>
import {computed, onMounted, onUnmounted, ref} from "vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import SummaryLoader from "../Components/SummaryLoader.vue";
import CustomerTrendChart from "./CustomerTrendChart.vue";
import TopCustomers from "./TopCustomers.vue";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";
import ReportLoadError from "../Components/ReportLoadError.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
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
// A grouping change reloads only the chart's view
const chartLoading = ref(true);
const groupKey = ref('default');
const data = ref({summary: {}, previous: null, chart: []});
// A failed refresh hides the figures: they belong to the previous filters
const failed = ref(false);
let latestRequest = 0;
let lastFilters = null;

const fetchData = (filters, chartOnly = false) => {
  const request = ++latestRequest;
  lastFilters = filters || reportFilter.applicableFilters;
  failed.value = false;
  if (!chartOnly) {
    loading.value = true;
  }
  chartLoading.value = true;
  const call = Rest.get('reports/customer-overview', {params: {...lastFilters.params, groupKey: groupKey.value}});
  reportFilter.trackCompareRange(call);
  call.then(response => request === latestRequest && (data.value = response))
    .catch(error => request === latestRequest && (failed.value = true, Notify.error(error)))
    .finally(() => {
      if (request === latestRequest) {
        loading.value = false;
        chartLoading.value = false;
      }
    });
};

const retry = () => fetchData(lastFilters);

const setGroupKey = (value) => {
  groupKey.value = value;
  fetchData(null, true);
};

const money = (value) => reportFilter.formatAmount(value || 0, {cents: true});

const cards = computed(() => {
  const summary = data.value.summary || {};
  const previous = data.value.previous;
  const change = (key) => previous && previous[key] ? (summary[key] - previous[key]) / Math.abs(previous[key]) * 100 : null;
  const share = summary.net ? Math.round(((summary.returning_net || 0) + (summary.renewal_net || 0)) / summary.net * 1000) / 10 : 0;

  return [
    {key: 'customers', title: translate('Customers'), value: formatNumber(summary.customers || 0), change: change('customers'), previous: previous?.customers,
      info: translate('Customers who paid in this period, renewals included.')},
    {key: 'new_customers', title: translate('New customers'), value: formatNumber(summary.new_customers || 0), change: change('new_customers'), previous: previous?.new_customers,
      info: translate('CustomerRpt_Customers_Whose')},
    {key: 'returning_customers', title: translate('Returning customers'), value: formatNumber(summary.returning_customers || 0), change: change('returning_customers'), previous: previous?.returning_customers,
      info: translate('CustomerRpt_Customers_Bought')},
    {key: 'repeat_rate', title: translate('Repeat purchase rate'), value: (summary.repeat_rate || 0) + '%', change: null,
      info: translate('CustomerRpt_Customers_Paid')},
    {key: 'average_ltv', title: translate('Average lifetime value'), value: money(summary.average_ltv), change: null,
      info: translate('CustomerRpt_Customers_Paid_Period')},
    {key: 'existing_share', title: translate('Revenue from existing customers'), value: share + '%', change: null,
      info: translate('CustomerRpt_Share_Period')},
  ];
});

onMounted(() => {
  reportFilter.addListener('customer-overview', fetchData);
  fetchData();
});

onUnmounted(() => {
  reportFilter.removeListener('customer-overview');
  latestRequest++;
});
</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-customer-report-page">
      <PageHeading :title="translate('Customers')"/>

      <SummaryLoader v-if="loading" :loading="loading" :count="6"/>
      <Card.Container v-else-if="failed">
        <Card.Body>
          <ReportLoadError @retry="retry"/>
        </Card.Body>
      </Card.Container>
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
                  :amount="item.previous"
                  :has-currency="false"
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

      <CustomerTrendChart
          v-if="!failed"
          :points="data.chart || []"
          :loading="chartLoading"
          :group-key="groupKey"
          :report-filter="reportFilter"
          @update:group-key="setGroupKey"
      />

      <ExtensionComponent name="reports.customers.value" :report-filter="reportFilter">
        <ProReportPreview
            :title="translate('Know what a customer is worth over time')"
            :text="translate('CustomerRpt_See_Lifetime')"
            :bullets="[translate('Lifetime value by first purchase month'), translate('Revenue concentration'), translate('Value by first product')]"
            placement="feature_lock_report_customer_value"
        >
          <ProReportSample
              :title="translate('Lifetime Value by First Purchase Month')"
              :columns="[translate('First purchase'), translate('Customers'), translate('Month 1'), translate('Month 3'), translate('Month 6')]"
              :rows="[[translate('Jan'), '100', '50.00', '75.00', '90.00'], [translate('Feb'), '100', '50.00', '75.00', '90.00'], [translate('Mar'), '100', '50.00', '75.00', '90.00']]"
          />
        </ProReportPreview>
      </ExtensionComponent>

      <ExtensionComponent name="reports.customers.repeat" :report-filter="reportFilter">
        <ProReportPreview
            :title="translate('See how often customers come back')"
            :text="translate('CustomerRpt_See_Many')"
            :bullets="[translate('Purchases per customer'), translate('Time to second purchase')]"
            placement="feature_lock_report_customer_repeat"
        >
          <ProReportSample
              :title="translate('Purchases per Customer')"
              :columns="[translate('Purchases'), translate('Customers'), translate('Share')]"
              :rows="[['1', '100', '50%'], ['2', '60', '30%'], ['3+', '40', '20%']]"
          />
        </ProReportPreview>
      </ExtensionComponent>

      <ExtensionComponent name="reports.customers.segments" :report-filter="reportFilter">
        <ProReportPreview
            :title="translate('Find your best and at-risk customers')"
            :text="translate('CustomerRpt_Group_Customers')"
            :bullets="[translate('Champions, loyal and at-risk customers'), translate('Export each segment as CSV')]"
            placement="feature_lock_report_customer_segments"
        >
          <ProReportSample
              :title="translate('Customer Segments')"
              :columns="[translate('Segment'), translate('Customers'), translate('Net sales')]"
              :rows="[[translate('Champions'), '100', '5,000.00'], [translate('Loyal'), '100', '3,000.00'], [translate('At risk'), '100', '1,000.00']]"
          />
        </ProReportPreview>
      </ExtensionComponent>

      <!-- It lists customers by name and email: only for users with customer access -->
      <UserCan permission="customers/view">
        <TopCustomers :report-filter="reportFilter"/>
      </UserCan>
    </div>
  </UserCan>
</template>
