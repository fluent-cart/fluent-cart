<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { onMounted, computed } from "vue";
import { formatNumber } from "../Utils/formatNumber";
import revenueReport from "@/Models/Reports/RevenueReportModel.js";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import { maybeLiability } from "../Utils/liability";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import translate from "@/utils/translator/Translator";
import SummaryLoader from "../Components/SummaryLoader.vue";

const props = defineProps({
  reportFilter: {
    type: Object,
  },
});

const summaryCards = [
  { 
    title: translate("Gross Sales"),
    key: "gross_sale", 
    info: translate("The total sales you had in this period.") 
  },
  { 
    title: translate("Refunds"),
    key: "total_refunded_amount", 
    liability: true,
    info: translate("Refunds made in this period. This is deducted from gross sales to calculate revenue.") 
  },
  { 
    title: translate("Taxes"),
    key: "tax_total", 
    liability: true,
    info: translate("Taxes paid in orders. This is deducted from gross sales to calculate revenue.") 
  },
  { 
    title: translate("Shipping"),
    key: "shipping_total", 
    liability: true,
    info: translate("Shipping paid in orders. This is deducted from gross sales to calculate revenue.") 
  },
  { 
    title: translate("Revenue"),
    key: "net_revenue", 
    info: translate("Gross sales less refunds, taxes, shipping, fees.") 
  },
];

const summaryData = computed(() => {
  return summaryCards.map((card) => ({
    key: card.key,
    title: card.title,
    value: revenueReport.data.summary[card.key] || 0,
    fluctuation: revenueReport.data.fluctuations[card.key] || 0,
    liability: card.liability || false,
    info: card.info,
  }))
  .filter(item => {
    if (['tax_total', 'shipping_total'].includes(item.key)) {
      return item.value !== 0;
    }

    return true;
  });
});

const dataLoader = computed(() => {
  return revenueReport.data.isBusy.revenueReport;
});
</script>

<template>
  <SummaryLoader v-if="dataLoader" :loading="dataLoader" :count="summaryData.length" />

  <div v-else class="fct-report-order-summary" :style="`--avg-grid-cols: ${summaryData.length}`">
    <div class="summary-item" v-for="(summary, index) in summaryData" :key="index">
      <div class="summary-item-inner">
        <div class="value">

          <div class="inner-value">
            <ReportSummary :amount="maybeLiability(summary.value, summary.liability)" :title="summary.title" />
          </div>

          <Fluctuation 
            :fluctuation="summary.fluctuation" 
            :amount="revenueReport.data.previousSummary[summary.key]"
            :is-liability="summary.liability"
          />
        </div>

        <div class="title">
          {{ summary.title }}

          <el-tooltip :content="summary.info" placement="top"
                      popper-class="fct-tooltip" effect="dark"
          >
            <DynamicIcon class="text-gray-500 w-4 flex-shrink-0" name="InformationFill" />
          </el-tooltip>
        </div>
      </div>
    </div>
  </div>
</template>
