<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { formatNumber } from "../Utils/formatNumber";
import { computed } from "vue";
import checkBoxFilter from "@/Models/Reports/CheckBoxFilterModel";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

import { maybeLiability } from "../Utils/liability";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import SummaryLoader from "../Components/SummaryLoader.vue";

const props = defineProps({
  orderReport: {
    type: Object,
    required: true,
  },
  reportFilter: {
    type: Object,
  },
});

const summaryCards = [
  {
    title: translate("Gross Sales"),
    key: "gross_sale",
    content: translate("The total sales you had in this period."),
    checkable: true,
    hasCurrency: true,
  },
  {
    title: translate("Refunds"),
    key: "total_refund",
    liability: true,
    hasCurrency: true,
    content: translate("Refunds made in this period. This is deducted from gross sales to calculate revenue."),
  },
  {
    title: translate("Revenue"),
    key: "net_revenue",
    hasCurrency: true,
    content: translate("Gross sales less refunds, taxes, shipping, fees."),
    checkable: true
  },
  {
    title: translate("Orders"),
    key: "order_count",
    content: translate("The total number of orders placed in this period."),
    checkable: true,
    hideCurrency: true
  },
  {
    title: translate("Items"),
    key: "total_item_count",
    content: translate("The total number of items sold in this period."),
    hideCurrency: true
  },
  {
    title: translate("Average Gross"),
    key: "average_gross",
    hasCurrency: true,
    content: translate("Average gross sales per order in this period."),
  },
  {
    title: translate("Average Revenue"),
    key: "average_net",
    hasCurrency: true,
    content: translate("Average revenue per order in this period."),
  },
  {
    title: translate("Average Order Items"),
    key: "average_order_items_count",
    content: translate("Average number of items per order in this period."),
    hideCurrency: true
  },
];

const summaryData = computed(() => {
  return summaryCards.map((card) => ({
    ...card,
    value: props.orderReport.data.summary[card.key] || 0,
    fluctuation: props.orderReport.data.fluctuations[card.key] || 0,
    liability: card.liability || false,
    hasCurrency: card.hasCurrency || false,
  }));
});

const allowedKeys = computed(() => summaryCards.filter((card) => !card.hideCurrency).map((card) => card.key));

const dataLoader = computed(() => {
  return props.orderReport.data.isBusy.orderChartData;
});

const onCheckBoxChange = (key) => {
  checkBoxFilter.onCheckBoxChange('order', key);
};

const formatAmount = (summary) => {
  return allowedKeys.value.includes(summary.key) 
    ? maybeLiability(summary.value, summary.liability) 
    : formatNumber(summary.value);
};
</script>

<template>
  <SummaryLoader v-if="dataLoader" :count="4" :rows="2" />

  <div v-else class="fct-report-order-summary summary-col-4">
    <div class="summary-item" v-for="(card, index) in summaryData" :key="index">
      <div class="summary-item-inner">
        <div class="value">
          <div class="summary-checkbox" v-if="card.checkable">
            <el-checkbox
                :true-value="true"
                :false-value="false"
                :model-value="checkBoxFilter.data.order.includes(card.key)"
                @change="() => onCheckBoxChange(card.key)"
            />
          </div><!-- summary-checkbox -->

          <div class="inner-value">
            <ReportSummary :amount="formatAmount(card)" :title="card.title" />
          </div><!-- inner-value -->

          <Fluctuation 
            :fluctuation="card.fluctuation" 
            :amount="orderReport.data.previousSummary[card.key]"
            :is-liability="card.liability"
            :has-currency="card.hasCurrency"
          />
        </div><!-- value -->

        <div class="title">
          {{ card.title }}
          <el-tooltip :content="card.content" placement="top"
                      popper-class="fct-tooltip" effect="dark">
            <DynamicIcon
                class="text-gray-500 w-4 h-4 cursor-help"
                name="InformationFill"
            />
          </el-tooltip>
        </div><!-- title -->

      </div>
    </div>
  </div>
</template>
