<script setup>
import { computed } from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import SummaryLoader from "../Components/SummaryLoader.vue";
import translate from "@/utils/translator/Translator";
import { formatNumber } from "../Utils/formatNumber";

// Subscription movement in the selected period, with compare; installment plans stay out
const props = defineProps({
  overview: {type: Object, default: null},
  loading: {type: Boolean, default: false},
  reportFilter: {type: Object, required: true},
});

const money = (value) => new DOMParser().parseFromString(props.reportFilter.formatAmount(value || 0), "text/html").documentElement.textContent;
// The sign first: +12, −$49.97 (not $-49.97)
const signed = (format, value) => {
  const number = Number(value) || 0;
  return (number > 0 ? "+" : (number < 0 ? "−" : "")) + format(Math.abs(number));
};
const percent = (value) => new Intl.NumberFormat(undefined, {maximumFractionDigits: 1}).format(Number(value) || 0) + "%";

// The Retention page counts churn of the starting base only, so its number can be lower
const churnedNewNote = (count) => {
  /* translators: %1$s - churned subscriptions that also started in this period */
  return translate('SubRpt_Includes_Started', formatNumber(count));
};

const mrrParts = (p) => {
  if (Number(p.expansion_mrr) || Number(p.contraction_mrr)) {
    /* translators: %1$s - MRR from new subscriptions, %2$s - added by upgrades, %3$s - lost to downgrades, %4$s - lost to churn */
    return translate("+%1$s new · +%2$s upgrades · −%3$s downgrades · −%4$s churned", money(p.new_mrr), money(p.expansion_mrr), money(p.contraction_mrr), money(p.churned_mrr));
  }
  /* translators: %1$s - MRR added by new subscriptions, %2$s - MRR lost to churn */
  return translate("+%1$s new · −%2$s churned", money(p.new_mrr), money(p.churned_mrr));
};

const cards = computed(() => {
  const p = (props.overview && props.overview.period) || {};

  return [
    {key: "new", title: translate("New Subscriptions"), value: formatNumber(p.new || 0),
      info: translate('SubRpt_Recurring_Subscriptions_Started')},
    {key: "churned", title: translate("Churned"), value: formatNumber(p.churned || 0), liability: true,
      info: translate('SubRpt_Recurring_Subscriptions')
        + (p.churned_new ? " " + churnedNewNote(p.churned_new) : ""),
      /* translators: %1$s - churn rate with its percent sign, %2$s - subscriptions active when the period began */
      sub: p.churn_rate === null || p.churn_rate === undefined ? "" : translate("%1$s churn of %2$s at the start", percent(p.churn_rate), formatNumber(p.active_at_start || 0))},
    {key: "net_change",
      title: translate("Net Change"), value: signed(formatNumber, p.net_change),
      info: translate("New subscriptions less churned ones.")},
    {key: "net_mrr", title: translate("Net MRR Change"), value: signed(money, p.net_mrr), money: true,
      info: translate('SubRpt_Mrr_New'),
      sub: mrrParts(p)},
    {key: "renewals",
      title: translate("Renewals"), value: formatNumber(p.renewals || 0),
      info: translate('SubRpt_Paid_Subscription'),
      sub: money(p.renewal_amount)},
    {key: "failed_renewals", title: translate("Failed Renewals"), value: formatNumber(p.failed_renewals || 0), liability: true,
      info: translate("Subscription renewal payments that failed in this period."),
      sub: money(p.failed_amount)},
  ].map(card => ({
    ...card,
    fluctuation: props.overview && props.overview.fluctuations ? props.overview.fluctuations[card.key] : null,
    previous: props.overview && props.overview.previousPeriod ? props.overview.previousPeriod[card.key] : null,
  }));
});
</script>

<template>
  <SummaryLoader v-if="loading" :count="6" :rows="2" />

  <div v-else class="fct-report-order-summary summary-col-6">
    <div class="summary-item" v-for="card in cards" :key="card.key">
      <div class="summary-item-inner">
        <div class="value">
          <div class="inner-value">
            <ReportSummary :amount="card.value" :title="card.title" />
          </div>
          <Fluctuation v-if="card.fluctuation !== null && card.fluctuation !== undefined && card.previous !== null"
                       :fluctuation="card.fluctuation" :amount="card.previous" :is-liability="!!card.liability" :has-currency="!!card.money" />
        </div>
        <div class="title">
          {{ card.title }}
          <el-tooltip :content="card.info" placement="top" popper-class="fct-tooltip" effect="dark" :trigger="['hover', 'focus']">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
              <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
            </button>
          </el-tooltip>
        </div>
        <div v-if="card.sub" class="text-xs text-system-light dark:text-gray-400 mt-1">{{ card.sub }}</div>
      </div>
    </div>
  </div>
</template>
