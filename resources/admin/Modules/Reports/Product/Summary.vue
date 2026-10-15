<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import checkBoxFilter from "@/Models/Reports/CheckBoxFilterModel";
import { computed } from "vue";
import { formatNumber } from "../Utils/formatNumber";
import productReport from "@/Models/Reports/ProductReportModel.js";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import translate from "@/utils/translator/Translator";
import { maybeLiability } from "../Utils/liability";
import SummaryLoader from "../Components/SummaryLoader.vue";

const props = defineProps({
  reportFilter: {
    type: Object,
  },
});

const reportFilter = props.reportFilter;

const summaryCards = [
  { 
    title: translate("Units Sold"), 
    key: "units_sold",
    info: translate("Quantity of items sold on paid, partially paid and refunded orders in this period.")
  },
  {
    title: translate("Customers"),
    key: "customer_count",
    info: translate("Distinct customers who bought these items in this period. Guest orders without a customer record are not counted.")
  },
  { 
    title: translate("Item Sales"), 
    key: "gross_sale", 
    hasCurrency: true,
    info: translate("Sum of item line totals, after discounts and before shipping, on paid, partially paid and refunded orders in this period. Refunds are not deducted. Differs from Gross Sales on the Revenue and Orders tabs, which use order totals.") 
  },
  {
    title: translate("Discounts"),
    key: "total_discounts",
    hasCurrency: true,
    liability: true,
    info: translate("Item subtotals less item sales: coupon, manual and order-level discounts on these items in this period.")
  },
  { 
    title: translate("Refunds"), 
    key: "total_refunds", 
    hasCurrency: true,
    liability: true,
    info: translate("Amount refunded on these items. Deducted from item sales to calculate revenue.")
  },
  { 
    title: translate("Revenue"), 
    key: "net_sale", 
    hasCurrency: true,
    info: translate("Item sales less item refunds and item tax.")
  },
];

const dataLoader = computed(() => productReport.data.productReportBusy);

const summaryData = computed(() => {
  return summaryCards.map((card) => ({
    key: card.key,
    title: card.title,
    value: productReport.data.summary[card.key] || 0,
    fluctuation: productReport.data.fluctuations[card.key] || 0,
    info: card.info,
    liability: card.liability || false,
    hasCurrency: card.hasCurrency || false,
  }));
});

const allowedKeys = computed(() => summaryCards.filter(card => card.hasCurrency).map((card) => card.key));

const getPercentageClass = (percentage) => {
  if (percentage > 0) {
    return 'text-success-500';
  } else if (percentage < 0) {
    return 'text-red-500';
  } else {
    return 'text-system-light';
  }
};

const formatAmount = (summary) => {
  return allowedKeys.value.includes(summary.key) 
    ? maybeLiability(summary.value, summary.liability, reportFilter.reportCurrency) 
    : formatNumber(summary.value);
};

</script>

<template>
  <SummaryLoader v-if="dataLoader" :loading="dataLoader" :count="summaryData.length" />

  <div v-else class="fct-report-order-summary summary-col-6">
    <div class="summary-item" v-for="(summary, index) in summaryData" :key="index">
      <div class="summary-item-inner">
        <div class="value">
          <div class="inner-value">
            <ReportSummary :amount="formatAmount(summary)" :title="summary.title" />
          </div>

          <Fluctuation 
            :fluctuation="summary.fluctuation" 
            :amount="productReport.data.previousSummary[summary.key]"
            :is-liability="summary.liability"
            :has-currency="summary.hasCurrency"
          />
        </div>

        <div class="title">
          {{ translate(summary.title) }}

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
