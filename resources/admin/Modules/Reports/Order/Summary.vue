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
    content: translate("Total paid on paid, partially paid and refunded orders created in this period, including tax and shipping. Discounts are already applied. Refunds are not deducted."),
    checkable: true,
    hasCurrency: true,
  },
  {
    title: translate("Refunds"),
    key: "total_refund",
    liability: true,
    hasCurrency: true,
    content: translate("Amount refunded on orders created in this period. Deducted from gross sales to calculate revenue."),
  },
  {
    title: translate("Revenue"),
    key: "net_revenue",
    hasCurrency: true,
    content: translate("Gross sales less refunds and taxes. Shipping charges and payment fees are not deducted."),
    checkable: true
  },
  {
    title: translate("Orders"),
    key: "order_count",
    content: translate("Paid, partially paid and refunded orders created in this period, in the report currency. Renewal orders are included; unpaid orders are not."),
    checkable: true,
    hideCurrency: true
  },
  {
    title: translate("Items"),
    key: "total_item_count",
    content: translate("Total quantity of items on the orders counted in this period. With a product filter, only the matching items are counted."),
    hideCurrency: true
  },
  {
    title: translate("Average Gross"),
    key: "average_gross",
    hasCurrency: true,
    content: translate("Gross sales divided by the number of orders counted in this period."),
  },
  {
    title: translate("Average Revenue"),
    key: "average_net",
    hasCurrency: true,
    content: translate("Revenue divided by the number of orders counted in this period."),
  },
  {
    title: translate("Average Order Items"),
    key: "average_order_items_count",
    content: translate("Items divided by the number of orders counted in this period."),
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
    ? maybeLiability(summary.value, summary.liability, props.reportFilter.reportCurrency) 
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
                      popper-class="fct-tooltip" effect="dark" :trigger="['hover', 'focus']">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help focus-visible:outline-2 focus-visible:outline-offset-2" :aria-label="translate('About this metric')">
              <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
            </button>
          </el-tooltip>
        </div><!-- title -->

      </div>
    </div>
  </div>
</template>
