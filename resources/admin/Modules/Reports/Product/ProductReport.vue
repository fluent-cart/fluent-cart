<script setup>
import {onMounted, onUnmounted, ref, computed} from "vue";
import ProductReportChart from "@/Modules/Reports/Product/ProductReportChart.vue";
import Summary from "@/Modules/Reports/Product/Summary.vue";
import productReport from "@/Models/Reports/ProductReportModel";
import ProductTopChart from "@/Modules/Reports/Product/ProductTopChart.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import TopSoldProducts from "@/Modules/Reports/Default/Components/TopSoldProducts.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import translate from "@/utils/translator/Translator";

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

  productReport.getTopSoldProducts(params);
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
      <PageHeading :title="translate('Products')"></PageHeading>
      
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

      <TopSoldProducts
        :data="productReport.data.topSoldProducts"
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

<style lang="scss">
.fct-revenue-report-page {
  .top-sold-products {
    margin-bottom: 30px;
  }
}
</style>
