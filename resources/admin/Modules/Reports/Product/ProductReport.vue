<script setup>
import {onMounted, onUnmounted, ref, computed} from "vue";
import ProductReportChart from "@/Modules/Reports/Product/ProductReportChart.vue";
import Summary from "@/Modules/Reports/Product/Summary.vue";
import productReport from "@/Models/Reports/ProductReportModel";
import ProductTopChart from "@/Modules/Reports/Product/ProductTopChart.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const getData = (params) => {
  //   revenueReport.getReportData(params);
  // productReport.getSummary(params);
  productReport.getProductReportData({
    params: {
      ...params.params,
      groupKey: chartGroupKey.value,
    },
  });
};

const filterChartData = (groupKey) => {
  if (chartGroupKey.value !== groupKey) {
    chartGroupKey.value = groupKey;
    
    getData(params)
  }
};

const reportFilter = props.reportFilter;
const params = reportFilter.applicableFilters;
const chartGroupKey = ref("default");

onMounted(() => {
  reportFilter.addListener("product-report", getData);
  getData(params);
});

onUnmounted(() => {
  reportFilter.removeListener("product-report", false);
});
</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-revenue-report-page">
      <div class="page-heading-wrap flex items-center justify-between">
        <h1 class="page-title">
          {{ $t('Products') }}
        </h1>
      </div>
      
      <Summary :reportFilter="reportFilter"/>

      <ProductReportChart
          :chartData="productReport.data.currentMetrics"
          :compareData="productReport.data.previousMetrics"
          :appliedGroupKey="chartGroupKey"
          :reportFilter="props.reportFilter"
          @filter-data="filterChartData"
          :is-empty="!productReport.data.summary.gross_sale"
          :loading="productReport.data.isBusy"
      />

      <ProductTopChart :reportFilter="reportFilter" />

      <!-- <el-row :gutter="30">
        <el-col :lg="24">
          <SalesForecast
            :volatilityData="productReport.data.chartData.volatilityChart"
          />
        </el-col>
      </el-row> -->
    </div>
  </UserCan>
</template>
