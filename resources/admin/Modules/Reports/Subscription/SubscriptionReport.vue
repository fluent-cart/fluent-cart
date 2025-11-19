<script setup>
import { onMounted, onUnmounted, ref, computed } from "vue";
import RetentionChart from "./RetentionChart.vue";
import SubscriptionChartNew from "./SubscriptionChartNew.vue";
import subscriptionReport from "@/Models/Reports/SubscriptionReportModel.js";
import { formatNumber } from "../Utils/formatNumber";
import * as Card from "@/Bits/Components/Card/Card.js";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import Summary from "./Summary.vue";
import translate from "@/utils/translator/Translator";
import ReportTableLoader from "../ReportTableLoader.vue";
import DailySignupChart from "@/Modules/Reports/Subscription/DailySignupChart.vue";
import SubscriptionMRRTrend from "@/Modules/Reports/Subscription/SubscriptionMRRTrend.vue";
import ChurnAnalytics from "@/Modules/Reports/Subscription/ChurnAnalytics.vue";
import Comparison from "@/Modules/Reports/Subscription/Comparison.vue";
import Rest from "@/utils/http/Rest";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const subscriptionReportData = ref({
  summary: {},
  fluctuations: {},
  currentMetrics: [],
  compareMetrics: [],
  loading: true,
});

const reportFilter = props.reportFilter;
const appliedGroupKey = ref('default');

const currencySign = computed(() => {
  return reportFilter.currentCurrencySign;
});
const params = reportFilter.applicableFilters;
const productSearchQuery = ref("");
const customerSearchQuery = ref("");

const filteredProductList = computed(() => {
  const list = subscriptionReport.data?.productList || [];
  const query = productSearchQuery.value.toLowerCase();
  return list.filter((item) => item.product_name.toLowerCase().includes(query));
});

const filteredCustomerList = computed(() => {
  const list = subscriptionReport.data?.customerList || [];
  const query = customerSearchQuery.value.toLowerCase();
  return list.filter(
    (item) =>
      item.customer_name.toLowerCase().includes(query) ||
      item.email.toLowerCase().includes(query)
  );
});

const getData = (filters) => {
 // subscriptionReport.getSubscriptionReportFluctuations(params);
 // subscriptionReport.listSubscriptionsByProduct(params);
  //subscriptionReport.listSubscriptionsByCustomer(params);
  // subscriptionReport.getSubscriptionOverview(params);

  fetchSubscriptionChart(filters);
  fetchRetentionChart(filters);
};

const fetchSubscriptionChart = (filters, groupKey = 'default') => {
  subscriptionReportData.value.loading = true;

  Rest.get('reports/subscription-chart', {
    params: {
      ...filters.params,
      groupKey: groupKey
    }
  })
  .then(response => {
    subscriptionReportData.value = response
  })
  .finally(() => subscriptionReportData.value.loading = false)
}

const onFilterData = (groupKey) => {
  if (appliedGroupKey.value !== groupKey) {
    appliedGroupKey.value = groupKey;
    
    fetchSubscriptionChart(reportFilter.applicableFilters.params, groupKey);
  }
};

const fetchRetentionChart = (filters, customDays = null) => {
  subscriptionReport.getRetentionChart({
    params: {
      ...filters.params,
      customDays,
    },
  });
};

const onCustomRetentionChart = (value) => {
  fetchRetentionChart(reportFilter.applicableFilters, value)
}

onMounted(() => {
  reportFilter.addListener("subscription-report", (filters) => {
    getData(filters);
    // onFilterData(reportFilter.applicableFilters);
  });
  
  getData(reportFilter.applicableFilters);
});

onUnmounted(() => {
  reportFilter.removeListener("subscription-report");
});
</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-refund-report-page">

      <div class="page-heading-wrap flex items-center justify-between">
        <h1 class="page-title">
          {{ translate('Subscriptions') }}
        </h1>
      </div>

      <Summary
          :summaryData="subscriptionReportData.summary"
          :fluctuations="subscriptionReportData.fluctuations"
          :subscriptionReport="subscriptionReport"
          :reportFilter="reportFilter"
          :loading="subscriptionReportData.loading"
      />

      <SubscriptionChartNew
          ref="subscriptionChart"
          :chartData="subscriptionReportData.currentMetrics"
          :compareData="subscriptionReportData.compareMetrics"
          :reportFilter="reportFilter"
          :appliedGroupKey="appliedGroupKey"
          @filter-data="onFilterData"
          :is-empty="!subscriptionReportData.summary.total_subscriptions"
          :loading="subscriptionReportData.loading"
      />
      
      <!-- comparison -->
      <Comparison
        class="my-7.5"
        :currentMetrics="subscriptionReportData.currentMetrics"
        :previousMetrics="subscriptionReportData.compareMetrics"
        :hasComparison="props.reportFilter.data.compareType !== 'no_comparison'"
        :reportFilter="reportFilter"
        :loading="subscriptionReportData.loading"
      />

      <el-row :gutter="30">
        <el-col :lg="12">
          <RetentionChart
            ref="subscriptionChart"
            :chartData="subscriptionReport.data.chartData"
            @fetch-custom-chart-data="onCustomRetentionChart"
            :is-empty="!subscriptionReportData.summary.total_subscriptions"
            :loading="subscriptionReport.data.isBusy.chartData"
          />
        </el-col>

        <el-col :lg="12">
          <DailySignupChart :reportFilter="reportFilter" :is-empty="!subscriptionReportData.summary.total_subscriptions" />    
        </el-col>
      </el-row>

      <el-row :gutter="30" v-if="false">
        <el-col :lg="12">
        </el-col>

        <el-col :lg="12">
          <SubscriptionMRRTrend :reportFilter="reportFilter"/>
        </el-col>
      </el-row>







      <ChurnAnalytics v-if="false" :reportFilter="reportFilter"/>

      <!-- Product List Section -->
      <Card.Container v-if="false" class="overflow-hidden">
        <Card.Header
          :title="translate('Subscription List By Product')"
          title_size="small"
        >
          <template #action>
            <el-input
                v-model="productSearchQuery"
                :placeholder="translate('Search by product name')"
                clearable
                style="width: 300px"
                size="small"
            />
          </template>
        </Card.Header>
        <Card.Body class="px-0 pb-0">
          <ReportTableLoader :loading="subscriptionReport.data.isBusy.productList" />

          <el-table v-if="!subscriptionReport.data.isBusy.productList" :data="filteredProductList" class="w-full h-max-[400px]">
            <el-table-column :label="translate('Product Name')" width="200">
              <template #default="scope">
                {{ scope.row.product_name }}
              </template>
            </el-table-column>
            <el-table-column :label="translate('Total Subscriptions')" width="200">
              <template #default="scope">
                {{ scope.row.total_subscription }}
              </template>
            </el-table-column>
            <el-table-column :label="translate('Total Revenue')" width="200">
              <template #default="scope">
                <span v-html="currencySign" />
                {{ formatNumber(scope.row.total_revenue) }}
              </template>
            </el-table-column>
            <template #empty>
              <el-empty :text="translate('No data available')" />
            </template>
          </el-table>
        </Card.Body>
      </Card.Container>

      <!-- Customer List Section -->
      <Card.Container v-if="false" class="overflow-hidden">
        <Card.Header
          :title="translate('Subscription List By Customer')"
          title_size="small"
        >
          <template #action>
            <el-input
                v-model="customerSearchQuery"
                :placeholder="translate('Search customer name or email')"
                clearable
                style="width: 300px"
                size="small"
            />
          </template>
        </Card.Header>
        <Card.Body class="px-0 pb-0">
          <ReportTableLoader :loading="subscriptionReport.data.isBusy.customerList" />

          <el-table v-if="!subscriptionReport.data.isBusy.customerList" :data="filteredCustomerList" class="w-full max-h-[400px]">
            <el-table-column :label="translate('Customer Name')" width="200">
              <template #default="scope">
                {{ scope.row.customer_name }}
              </template>
            </el-table-column>
            <el-table-column :label="translate('Email')" width="200">
              <template #default="scope">
                {{ scope.row.email }}
              </template>
            </el-table-column>
            <el-table-column :label="translate('Total Subscriptions')" width="200">
              <template #default="scope">
                {{ scope.row.total_subscription }}
              </template>
            </el-table-column>
            <el-table-column :label="translate('Total Revenue')" width="200">
              <template #default="scope">
                <span v-html="currencySign" />
                {{ formatNumber(scope.row.total_revenue) }}
              </template>
            </el-table-column>
            <template #empty>
              <el-empty :text="translate('No data available')" />
            </template>
          </el-table>
        </Card.Body>
      </Card.Container>
    </div>
  </UserCan>
</template>
