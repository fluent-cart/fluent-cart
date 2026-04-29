<script setup>
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import LineChart from "@/Modules/Reports/Default/lineChart.vue";
import { ref, computed } from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ReportCard from "@/Modules/Reports/Components/ReportCard.vue";
import translateNumber from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import Theme from "@/utils/Theme";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";

import { maybeLiability } from "@/Modules/Reports/Utils/liability";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";

const props = defineProps({
  defaultReport: {
    type: Object,
    required: true,
  },
});

const currencySign = computed(() => {
  return reportFilter.currentCurrencySign;
});

const dataLoader = computed(() => {
  return defaultReport.data.isBusy.salesReport;
});

const hexToRgba = (hex, alpha) => {
  const bigint = parseInt(hex.replace("#", ""), 16);
  const r = (bigint >> 16) & 255;
  const g = (bigint >> 8) & 255;
  const b = bigint & 255;
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

const colors = Theme.colors.report;

const summaryCards = ref([
  {
    title: translate("Gross Sales"),
    key: "gross_sale",
    graph: "grossSaleGraph",
    lineColor: colors.sea_green,
    backgroundGradient: [
      { offset: 0, color: hexToRgba(colors.sea_green, 0.5) },
      { offset: 1, color: "rgba(255, 255, 255, 0)" },
    ],
    info: translate(
      "The total sales you had in this period."
    ),
  },
  {
    title: translate("Refunds"),
    key: "total_refunded_amount",
    graph: "refundsGraph",
    lineColor: colors.medium_slate_blue,
    backgroundGradient: [
      { offset: 0, color: hexToRgba(colors.medium_slate_blue, 0.5) },
      { offset: 1, color: "rgba(255, 255, 255, 0)" },
    ],
    info: translate("Refunds made in this period. This is deducted from gross sales to calculate revenue."),
    liability: true,
  },

  {
    title: translate("Revenue"),
    key: "net_revenue",
    graph: "netRevenueGraph",
    lineColor: colors.royal_blue,
    backgroundGradient: [
      { offset: 0, color: hexToRgba(colors.royal_blue, 0.5) },
      { offset: 1, color: "rgba(255, 255, 255, 0)" },
    ],
    info: translate(
      "Gross sales less refunds, taxes, shipping, fees."
    ),
  },

  {
    title: translate("Orders"),
    key: "order_count",
    graph: "orderGraph",
    lineColor: colors.medium_turquoise,
    backgroundGradient: [
      { offset: 0, color: hexToRgba(colors.medium_turquoise, 0.5) },
      { offset: 1, color: "rgba(255, 255, 255, 0)" },
    ],
    info: translate(
      "The total number of orders placed in this period."
    ),
  },
  // {
  //   title: translate("New Customers"),
  //   key: "new_customers",
  //   graph: "newCustomersGraph",
  //   lineColor: colors.hot_pink,
  //   backgroundGradient: [
  //     { offset: 0, color: hexToRgba(colors.hot_pink, 0.5) },
  //     { offset: 1, color: "rgba(255, 255, 255, 0)" },
  //   ],
  //   info: translate(
  //     "New customer who didn't have any previous purchase record within this timeline"
  //   ),
  // },
  {
    title: translate("Items"),
    key: "total_item_count",
    graph: "itemsSoldGraph",
    lineColor: colors.deep_sky_blue,
    backgroundGradient: [
      { offset: 0, color: hexToRgba(colors.deep_sky_blue, 0.5) },
      { offset: 1, color: "rgba(255, 255, 255, 0)" },
    ],
    info: translate("The total number of items sold in this period."),
  },
  
  {
    title: translate("Total Refunds"),
    key: "total_refunded",
    graph: "refundCountGraph",
    lineColor: colors.golden_rod,
    backgroundGradient: [
      { offset: 0, color: hexToRgba(colors.golden_rod, 0.5) },
      { offset: 1, color: "rgba(255, 255, 255, 0)" },
    ],
    info: translate(
      "Total refunds count including partially refunds."
    ),
  },  
  
]);

const summaryData = computed(() => {
  return summaryCards.value.map((card) => ({
    ...card,
    value: props.defaultReport.summaryData[card.key] || 0,
    fluctuation: props.defaultReport.fluctuations[card.key] || 0,
    graph: props.defaultReport.data.graphs[card.graph] || {},
    dataBusy: props.defaultReport.data.isBusy.salesReport || false,
    graphBusy: props.defaultReport.data.isBusy.graphs[card.graph] || false,
    liability: card.liability || false,
  }));
});

const hasCurrency = (key) => {
  return ['net_revenue', 'total_refunded_amount', 'gross_sale'].includes(key);
}

const formatAmount = (summary) => {
  return hasCurrency(summary.key) 
    ? maybeLiability(summary.value, summary.liability) 
    : formatNumber(summary.value);
};
</script>

<template>
  <UserCan :permission="'reports/view'">
    <el-row :gutter="16" class="gap-y-4">
      <el-col
        :lg="8"
        :md="12"
        v-for="(card, index) in summaryData"
        :key="index"
      >
        <ReportCard class="fct-report-summary-card h-[100px]">
          <div class="fct-report-result">
            <div v-if="card.dataBusy" class="fct-report-result-content">
              <el-skeleton animated :rows="1" />
            </div>
            <div v-else class="fct-report-result-content">
              <div class="title flex items-center gap-1">
                {{ card.title }}
                <el-tooltip
                  popper-class="fct-tooltip"
                  :content="card.info"
                  placement="top"
                >
                  <DynamicIcon class="text-gray-500 w-4 cursor-pointer" name="InformationFill" />
                </el-tooltip>
              </div>
              <div class="value">
                <div class="inline-flex">
                  <ReportSummary :amount="formatAmount(card)" :title="card.title" />
                </div>

                <Fluctuation 
                  :fluctuation="card.fluctuation" 
                  :amount="defaultReport.data.previousSummary[card.key]"
                  :is-liability="card.liability"
                  :has-currency="hasCurrency(card.key)"
                />
              </div>
            </div>

            <div v-if="card.dataBusy" class="fct-report-result-chart">
              <el-skeleton animated class="flex items-center justify-end">
                <template #template>
                  <el-skeleton-item
                    variant="circle"
                    class="w-12.5 h-12.5 flex-none"
                  />
                </template>
              </el-skeleton>
            </div>
            <div
              class="fct-report-result-chart"
              v-if="!card.dataBusy && Object.keys(card.graph).length > 0"
            >
              <LineChart
                ref="lineChart"
                :xAxis="Object.keys(card.graph)"
                :yAxis="Object.values(card.graph)"
                :lineColor="card.lineColor"
                :backgroundGradient="card.backgroundGradient"
                :has-currency="hasCurrency(card.key)"
              />
            </div>
          </div>
        </ReportCard>
      </el-col>
    </el-row>
  </UserCan>
</template>
