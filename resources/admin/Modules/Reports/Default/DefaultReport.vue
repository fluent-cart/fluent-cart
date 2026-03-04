<template>
  <div class="fct-default-report-page">

    <SummaryCards :default-report="defaultReport" />

    <!-- <div class="my-5">
      <AverageCards :default-report="defaultReport" />
    </div> -->

    <el-row :gutter="30" class="gap-y-4 my-5">
      <el-col :lg="12">
        <TopSoldProducts
          :data="defaultReport.data.topSoldProducts"
          :loading="defaultReport.data.isBusy.topSoldProducts"
        />
      </el-col>
      <el-col :lg="12">
        <TopSoldVariants :default-report="defaultReport" />
      </el-col>
      <el-col :lg="8" v-if="false">
        <FailedOrders :default-report="defaultReport" />
      </el-col>
    </el-row>

    <el-row :gutter="30" class="gap-y-4 my-5">
      <el-col :lg="12">
        <Sources :data="defaultReport.data.summaryData" />
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, computed, watch } from "vue";
import defaultReport from "@/Models/Reports/DefaultReportModel";
import SummaryCards from "@/Modules/Reports/Default/Components/SummaryCards.vue";
import FailedOrders from "@/Modules/Reports/Default/Components/FailedOrders.vue";
import TopSoldProducts from "@/Modules/Reports/Default/Components/TopSoldProducts.vue";
import TopSoldVariants from "@/Modules/Reports/Default/Components/TopSoldVariants.vue";
import Sources from "@/Modules/Reports/Default/Components/Sources.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const reportFilter = ref(props.reportFilter);


const getData = async (params) => {
  if (!params) return;

  await defaultReport.getSalesReport(params);

  // await defaultReport.getDefaultReport(params);
  // await defaultReport.getDefaultReportFluctuations(params);

  await defaultReport.getTopSoldProducts(params);
  // await defaultReport.getFailedOrders(params);

  //await defaultReport.getTopSoldVariants(params);

  // await defaultReport.getDefaultReportGraphs({
  //   params: {
  //     ...params.params,
  //     graph: "netRevenueGraph",
  //   },
  // });

  // await defaultReport.getDefaultReportGraphs({
  //   params: {
  //     ...params.params,
  //     graph: "orderGraph",
  //   },
  // });

  // await defaultReport.getDefaultReportGraphs({
  //   params: {
  //     ...params.params,
  //     graph: "subscriptionRenewalGraph",
  //   },
  // });

  // await defaultReport.getDefaultReportGraphs({
  //   params: {
  //     ...params.params,
  //     graph: "newCustomersGraph",
  //   },
  // });

  // await defaultReport.getDefaultReportGraphs({
  //   params: {
  //     ...params.params,
  //     graph: "itemsSoldGraph",
  //   },
  // });

  // await defaultReport.getDefaultReportGraphs({
  //   params: {
  //     ...params.params,
  //     graph: "refundsGraph",
  //   },
  // });

  // await defaultReport.getDefaultReportGraphs({
  //   params: {
  //     ...params.params,
  //     graph: "refundCountGraph",
  //   },
  // });

  // await defaultReport.getDefaultReportGraphs({
  //   params: {
  //     ...params.params,
  //     graph: "subscriptionRenewalGraph",
  //   },
  // });

  // await defaultReport.getDefaultReportGraphs({
  //   params: {
  //     ...params.params,
  //     graph: "subscriptionRevenueGraph",
  //   },
  // });

  await defaultReport.getTopSoldVariants(params);
  // defaultReport.getTopSoldVariants(params);
  // cartReportModel.getAbandonedCartItems();
};

onMounted(() => {
  if (reportFilter.value) {
    reportFilter.value.addListener("default-report", getData);
    //getData(reportFilter.value.applicableFilters);
  }
});

onUnmounted(() => {
  if (reportFilter.value) {
    reportFilter.value.removeListener("default-report");
  }
});

watch(
  () => props.reportFilter,
  (newFilter) => {
    if (reportFilter.value) {
      reportFilter.value.removeListener("default-report");
    }
    reportFilter.value = newFilter;
    if (reportFilter.value) {
      reportFilter.value.addListener("default-report", getData);
      getData(reportFilter.value.applicableFilters);
    }
  },
  { immediate: true }
);
</script>
