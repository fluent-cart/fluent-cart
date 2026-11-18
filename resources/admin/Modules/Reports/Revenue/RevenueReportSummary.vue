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

// The sales breakdown, in Shopify's order: each line leads to the next
const summaryCards = [
  {
    title: translate("Gross Sales"),
    key: "gross_sales",
    info: translate('RevenueRpt_Product_Sales'),
  },
  {
    title: translate("Discounts"),
    key: "discounts",
    operator: "−",
    liability: true,
    info: translate("Coupon and manual discounts on these orders."),
  },
  {
    title: translate("Refunds"),
    key: "refunds",
    operator: "−",
    liability: true,
    info: translate('RevenueRpt_Refunds_Orders'),
  },
  {
    title: translate("Net Sales"),
    key: "net_sales",
    operator: "=",
    info: translate('RevenueRpt_Gross_Sales'),
  },
  {
    title: translate("Shipping"),
    key: "shipping_net",
    operator: "+",
    optional: true,
    info: translate("Shipping charged on these orders, less refunded shipping, before shipping tax."),
  },
  {
    title: translate("Fees"),
    key: "fees",
    operator: "+",
    optional: true,
    info: translate("Payment and other fees charged on these orders, less refunded fees."),
  },
  {
    title: translate("Taxes"),
    key: "taxes_net",
    operator: "+",
    optional: true,
    info: translate('RevenueRpt_Tax_Collected'),
  },
  {
    title: translate("Total Sales"),
    key: "total_collected",
    operator: "=",
    info: translate("Net sales plus shipping, fees and taxes: what customers paid, less refunds."),
  },
];

const summaryData = computed(() => {
  const summary = revenueReport.data.summary || {};
  const hasExtras = ["shipping_net", "fees", "taxes_net"].some(key => Number(summary[key]) !== 0);

  return summaryCards
    // Shipping, fees and tax show only when the store has them; without any, total sales equals net sales
    .filter(card => (card.optional ? Number(summary[card.key]) !== 0 : true))
    .filter(card => card.key !== "total_collected" || hasExtras)
    .map((card) => ({
      ...card,
      value: summary[card.key] || 0,
      fluctuation: revenueReport.data.fluctuations[card.key] ?? null,
      liability: card.liability || false,
    }));
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
            v-if="summary.fluctuation !== null"
            :fluctuation="summary.fluctuation" 
            :amount="revenueReport.data.previousSummary[summary.key]"
            :is-liability="summary.liability"
          />
        </div>

        <div class="title">
          <span v-if="summary.operator" class="text-system-light dark:text-gray-400" aria-hidden="true">{{ summary.operator }}</span>
          {{ summary.title }}

          <fct-tooltip :content="summary.info" placement="top" :trigger="['hover', 'focus']">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help focus-visible:outline-2 focus-visible:outline-offset-2" :aria-label="translate('About this metric')">
              <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
            </button>
          </fct-tooltip>
        </div>
      </div>
    </div>
  </div>
</template>
