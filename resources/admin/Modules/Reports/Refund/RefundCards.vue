<script setup>
import { computed } from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import SummaryLoader from "../Components/SummaryLoader.vue";
import translate from "@/utils/translator/Translator";
import { formatNumber } from "../Utils/formatNumber";
import { maybeLiability } from "../Utils/liability";

// Refund cards: money out on the refund date, rates on the order date. Every refund figure going up is bad news.
const props = defineProps({
  overview: {type: Object, default: null},
  loading: {type: Boolean, default: false},
  reportFilter: {type: Object, required: true},
});

/* translators: %1$s - left empty; the text follows a change in percentage points, e.g. "+1.2%" then " pts" */
const pointsSuffix = translate('%1$s pts', '');
const percent = (value) => new Intl.NumberFormat(undefined, {maximumFractionDigits: 1}).format(Number(value) || 0) + "%";

const cards = computed(() => {
  const s = (props.overview && props.overview.summary) || {};
  const full = s.full || {count: 0};
  const partial = s.partial || {count: 0};

  return [
    {
      key: "refunded_amount", title: translate("Refunded"), money: true,
      info: translate('RefundRpt_Money_Refunded'),
    },
    {
      key: "refunds", title: translate("Refunds"),
      info: translate("Refunds made in this period. An order can be refunded more than once."),
      /* translators: %1$s - refunded orders, %2$s - of them fully refunded, %3$s - partly refunded */
      sub: translate("on %1$s orders: %2$s full, %3$s partial", formatNumber(s.refunded_orders || 0), formatNumber(full.count), formatNumber(partial.count)),
    },
    {
      key: "average_refund", title: translate("Average Refund"), money: true,
      info: translate("Refunded amount divided by the number of refunds."),
    },
    {
      key: "refund_rate_cohort", title: translate("Refund Rate"), percent: true,
      info: translate('RefundRpt_Share_Period'),
      /* translators: %1$s - refunds made in the period per order placed in it, with its percent sign */
      sub: s.refund_rate_orders === null || s.refund_rate_orders === undefined ? "" : translate("Refunds per order placed: %1$s", percent(s.refund_rate_orders)),
    },
    {
      key: "median_days", title: translate("Days to Refund"),
      info: translate('RefundRpt_Median_Number'),
      sub: translate("median"),
      // Sooner or later refunds are neither good nor bad news
      noCompare: true,
    },
  ].map(card => ({
    ...card,
    // A rate is null when no orders were placed in the period
    value: card.percent ? s[card.key] : (s[card.key] || 0),
    fluctuation: props.overview && props.overview.fluctuations ? props.overview.fluctuations[card.key] : null,
    previous: props.overview && props.overview.previousSummary ? props.overview.previousSummary[card.key] : null,
  }));
});

const display = (card) => {
  if (card.money) {
    return maybeLiability(card.value, false, props.reportFilter.reportCurrency);
  }

  if (card.percent) {
    return card.value === null || card.value === undefined ? "n/a" : percent(card.value);
  }

  return formatNumber(card.value);
};
</script>

<template>
  <SummaryLoader v-if="loading" :count="5" :rows="2" />

  <div v-else class="fct-report-order-summary" :style="`--avg-grid-cols: ${cards.length}`">
    <div class="summary-item" v-for="card in cards" :key="card.key">
      <div class="summary-item-inner">
        <div class="value">
          <div class="inner-value">
            <ReportSummary :amount="display(card)" :title="card.title" />
          </div>
          <Fluctuation v-if="overview && overview.previousSummary && !card.noCompare && card.fluctuation !== null && card.fluctuation !== undefined && card.previous !== null" :fluctuation="card.fluctuation" :amount="card.previous"
                       :is-liability="true" :has-currency="!!card.money" :is-percentage="!!card.percent"
                       :fluctuation-suffix="card.percent ? pointsSuffix : ''" />
        </div>
        <div class="title">
          {{ card.title }}
          <fct-tooltip :content="card.info" placement="top" :trigger="['hover', 'focus']">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
              <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
            </button>
          </fct-tooltip>
        </div>
        <div v-if="card.sub" class="text-xs text-system-light dark:text-gray-400 mt-1">{{ card.sub }}</div>
      </div>
    </div>
  </div>
</template>
