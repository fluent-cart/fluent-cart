<script setup>
import {onMounted, onUnmounted, ref, computed} from "vue";
import ProductReportChart from "@/Modules/Reports/Product/ProductReportChart.vue";
import ProductPeriodTable from "@/Modules/Reports/Product/ProductPeriodTable.vue";
import Summary from "@/Modules/Reports/Product/Summary.vue";
import productReport from "@/Models/Reports/ProductReportModel";
import ProductTopChart from "@/Modules/Reports/Product/ProductTopChart.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import TopSoldProducts from "@/Modules/Reports/Default/Components/TopSoldProducts.vue";
import TopSoldVariants from "@/Modules/Reports/Default/Components/TopSoldVariants.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const loadProductReport = (params) => {
  reportFilter.trackCompareRange(productReport.getProductReportData({
    params: {
      ...params.params,
      groupKey: chartGroupKey.value,
    },
  }));
};

const getData = (params) => {
  //   revenueReport.getReportData(params);
  // productReport.getSummary(params);
  loadProductReport(params);

  productReport.getTopSoldProducts(params);
  productReport.getTopSoldVariants(params);
};

const retryProductReport = () => {
  loadProductReport(reportFilter.applicableFilters);
};

const retryTopSoldVariants = () => {
  productReport.getTopSoldVariants(reportFilter.applicableFilters);
};

const filterChartData = (groupKey) => {
  if (chartGroupKey.value !== groupKey) {
    chartGroupKey.value = groupKey;
    
    getData(reportFilter.applicableFilters)
  }
};

const reportFilter = props.reportFilter;
const chartGroupKey = ref("default");

onMounted(() => {
  reportFilter.addListener("product-report", getData);
  getData(reportFilter.applicableFilters);
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
          :loading="productReport.data.productReportBusy"
      />

      <ProductPeriodTable
          :rows="productReport.data.currentMetrics"
          :reportFilter="props.reportFilter"
          :loading="productReport.data.productReportBusy"
          :error="productReport.data.error || ''"
          @retry="retryProductReport"
      />

      <el-row :gutter="30">
        <el-col :lg="12">
          <TopSoldProducts
            :data="productReport.data.topSoldProducts"
            :loading="productReport.data.isBusy"
          />
        </el-col>
        <el-col :lg="12">
          <TopSoldVariants
            :data="productReport.data.topSoldVariants"
            :loading="productReport.data.topSoldVariantsBusy"
            :error="productReport.data.topSoldVariantsError || ''"
            @retry="retryTopSoldVariants"
          />
        </el-col>
      </el-row>

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
  .top-sold-products,
  .top-sold-variants {
    margin-bottom: 30px;
  }
}
</style>
