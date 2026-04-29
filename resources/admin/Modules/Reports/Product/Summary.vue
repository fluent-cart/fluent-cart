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
    title: "Units Sold", 
    key: "units_sold",
    info: translate("The total number of units sold in this period.")
  },
  { 
    title: "Gross Sale", 
    key: "gross_sale", 
    hasCurrency: true,
    info: translate("The total sales you had in this period.") 
  },
  { 
    title: "Refunds", 
    key: "total_refunds", 
    hasCurrency: true,
    liability: true,
    info: translate("Refunds made in this period. This is deducted from gross sales to calculate revenue.")
  },
  { 
    title: "Revenue", 
    key: "net_sale", 
    hasCurrency: true,
    info: translate("Gross sales less refunds, taxes, shipping, fees.")
  },
  // { 
  //   title: "Customer Count", 
  //   key: "customer_count" 
  // },
  // { 
  //   title: "Avg. Selling Price", 
  //   key: "average_selling_price", 
  //   hasCurrency: true 
  // },
];

const dataLoader = computed(() => productReport.data.isBusy);

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
    ? maybeLiability(summary.value, summary.liability) 
    : formatNumber(summary.value);
};

</script>

<template>
  <SummaryLoader v-if="dataLoader" :loading="dataLoader" :count="summaryData.length" />

  <div v-else class="fct-report-order-summary" :class="dataLoader ? '' : 'summary-col-4'">
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
                      popper-class="fct-tooltip" effect="dark"
          >
            <DynamicIcon class="text-gray-500 w-4 flex-shrink-0" name="InformationFill" />
          </el-tooltip>
        </div>

      </div>
    </div>
  </div>
</template>
