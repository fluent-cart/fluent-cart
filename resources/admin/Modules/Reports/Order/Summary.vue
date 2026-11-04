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
  failedOrders: {
    type: Number,
    default: 0,
  },
});

// Order cards only: gross, refunds and revenue live on the Revenue page
const percent = (value) => new Intl.NumberFormat(undefined, {maximumFractionDigits: 1}).format(Number(value) || 0) + "%";

// Up to 90 days a daily rate reads naturally; longer ranges read per month
const perDay = computed(() => (props.reportFilter?.data?.dateDiff ?? 0) <= 90);

const summaryCards = computed(() => {
  const summary = props.orderReport.data.summary || {};
  const orders = Number(summary.order_count) || 0;
  const failed = Number(props.failedOrders) || 0;

  return [
    {
      title: translate("Orders"),
      key: "order_count",
      content: translate('OrderRpt_Paid_Partially'),
      checkable: true,
      hideCurrency: true
    },
    {
      title: translate("Average Order Value (Net)"),
      key: "average_net",
      hasCurrency: true,
      content: translate("Revenue divided by the number of orders counted in this period."),
    },
    {
      title: translate("Items per Order"),
      key: "average_order_items_count",
      content: translate("Items divided by the number of orders counted in this period."),
      hideCurrency: true,
      decimals: 2,
    },
    {
      title: translate("Refunded Orders"),
      key: "refunded_orders",
      content: translate("Orders in this period with a full or partial refund."),
      hideCurrency: true,
      liability: true,
      /* translators: %1$s - share of orders with its percent sign, e.g. 2.5% */
      sub: translate("%1$s of orders", percent(summary.refund_rate)),
    },
    {
      title: translate("Failed Orders"),
      key: "failed_orders",
      content: translate('OrderRpt_Orders_Created'),
      hideCurrency: true,
      noCompare: true,
      /* translators: %1$s - failed orders as a share of all attempts, with its percent sign, e.g. 1.2% */
      sub: translate("%1$s failure rate", percent(orders + failed ? failed / (orders + failed) * 100 : 0)),
    },
    perDay.value
      ? {
        title: translate("Orders per Day"),
        key: "orders_per_day",
        content: translate("Orders divided by the days in this period that have passed."),
        hideCurrency: true,
      }
      : {
        title: translate("Orders per Month"),
        key: "orders_per_month",
        content: translate("Orders divided by the months in this period."),
        hideCurrency: true,
      },
  ];
});

const summaryData = computed(() => {
  return summaryCards.value.map((card) => ({
    ...card,
    value: card.key === 'failed_orders' ? props.failedOrders : (props.orderReport.data.summary[card.key] || 0),
    fluctuation: props.orderReport.data.fluctuations[card.key] ?? null,
    liability: card.liability || false,
    hasCurrency: card.hasCurrency || false,
  }));
});

const allowedKeys = computed(() => summaryCards.value.filter((card) => !card.hideCurrency).map((card) => card.key));

const dataLoader = computed(() => {
  return props.orderReport.data.isBusy.orderChartData;
});

const onCheckBoxChange = (key) => {
  checkBoxFilter.onCheckBoxChange('order', key);
};

const formatAmount = (summary) => {
  return allowedKeys.value.includes(summary.key) 
    ? maybeLiability(summary.value, summary.liability, props.reportFilter.reportCurrency) 
    : (summary.decimals
      ? new Intl.NumberFormat(undefined, {minimumFractionDigits: summary.decimals, maximumFractionDigits: summary.decimals}).format(Number(summary.value) || 0)
      : formatNumber(summary.value));
};
</script>

<template>
  <SummaryLoader v-if="dataLoader" :count="6" :rows="2" />

  <div v-else class="fct-report-order-summary summary-col-6">
    <div class="summary-item" v-for="(card, index) in summaryData" :key="index">
      <div class="summary-item-inner">
        <div class="value">
          <div class="summary-checkbox" v-if="card.checkable">
            <el-checkbox
                :model-value="checkBoxFilter.data.order.includes(card.key)"
                @change="() => onCheckBoxChange(card.key)"
            />
          </div><!-- summary-checkbox -->

          <div class="inner-value">
            <ReportSummary :amount="formatAmount(card)" :title="card.title" />
          </div><!-- inner-value -->

          <Fluctuation
            v-if="!card.noCompare && card.fluctuation !== null"
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
        <div v-if="card.sub" class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ card.sub }}</div>

      </div>
    </div>
  </div>
</template>
