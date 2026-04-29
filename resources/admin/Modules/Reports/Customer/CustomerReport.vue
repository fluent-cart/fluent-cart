<script setup>
import { onMounted, onUnmounted, ref, computed, toRaw } from "vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import Summary from "./Summary.vue";
import CustomerReportChart from "@/Modules/Reports/Customer/CustomerReportChart.vue";
import Comparison from "@/Modules/Reports/Customer/Comparison.vue";
import Rest from "@/utils/http/Rest";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const customerReport = ref({
  summary: {},
  previousSummary: {},
  fluctuations: {},
  currentMetrics: [],
  previousMetrics: [],
  loading: true,
});
const appliedGroupKey = ref('default');
const reportFilter = props.reportFilter;
const params = reportFilter.applicableFilters.params;

const getData = (params, groupKey = 'default') => {
  customerReport.value.loading = true;

  Rest.get('reports/customer-report', {
    params: {
      ...params,
      groupKey: groupKey
    }
  })
  .then(response => {
    customerReport.value = response;
  })
  .finally(() => {
    customerReport.value.loading = false;
  });
}

const filterData = (groupKey) => {
  if (appliedGroupKey.value !== groupKey) {
    appliedGroupKey.value = groupKey;
    
    getData(params, groupKey);
  }
}

onMounted(() => {
  getData(params);

  props.reportFilter.addListener("customer-report", (filters) => {
    getData(filters.params, appliedGroupKey.value);
  });
});

</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-customer-report-page">
      <PageHeading :title="translate('Customers')"></PageHeading>

      <Summary :reportFilter="reportFilter" :customerReport="customerReport" />

      <CustomerReportChart
          :chartData="customerReport.currentMetrics"
          :compareData="customerReport.previousMetrics"
          :reportFilter="reportFilter"
          :appliedGroupKey="appliedGroupKey"
          @filter-data="filterData"
          :is-empty="!customerReport.summary.customer_count"
          :loading="customerReport.loading"
      />

      <!-- comparison -->
      <Comparison
          class="my-7.5"
          :currentMetrics="customerReport.currentMetrics"
          :previousMetrics="customerReport.previousMetrics"
          :hasComparison="props.reportFilter.data.compareType !== 'no_comparison'"
          :reportFilter="reportFilter"
          :loading="customerReport.loading"
      />
    </div>
  </UserCan>
</template>
