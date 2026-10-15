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
    info: translate("Total paid on paid, partially paid and refunded orders created in this period, including tax and shipping. Discounts are already applied. Refunds are not deducted.") 
  },
  { 
    title: translate("Refunds"),
    key: "total_refunded_amount", 
    liability: true,
    info: translate("Amount refunded on orders created in this period. Deducted from gross sales to calculate revenue.") 
  },
  { 
    title: translate("Taxes"),
    key: "tax_total", 
    liability: true,
    info: translate("Tax collected on orders created in this period, including tax on shipping. Deducted from gross sales to calculate revenue.") 
  },
  { 
    title: translate("Shipping"),
    key: "shipping_total", 
    liability: true,
    info: translate("Shipping charged on orders created in this period, before shipping tax. Not deducted when calculating revenue.") 
  },
  { 
    title: translate("Revenue"),
    key: "net_revenue", 
    info: translate("Gross sales less refunds and taxes. Shipping charges and payment fees are not deducted.") 
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
            <ReportSummary :amount="maybeLiability(summary.value, summary.liability, reportFilter.reportCurrency)" :title="summary.title" />
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
                      popper-class="fct-tooltip" effect="dark" :trigger="['hover', 'focus']">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help focus-visible:outline-2 focus-visible:outline-offset-2" :aria-label="translate('About this metric')">
              <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
            </button>
          </el-tooltip>
        </div>
      </div>
    </div>
  </div>
</template>
