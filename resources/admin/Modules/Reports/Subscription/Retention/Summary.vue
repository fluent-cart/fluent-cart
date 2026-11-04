<template>
    <SummaryLoader v-if="loading" :loading="loading" :count="3" />

    <div v-else :class="['fct-report-order-summary', cards.length ? 'summary-col-3' : '']">
        <template v-if="cards.length">
          <div class="summary-item" v-for="card in cards" :key="card.key">
            <div class="summary-item-inner">
              <div class="title text-[16px] mb-2.5 flex items-center gap-1.5">
                {{ card.title }}
                <el-tooltip :content="card.info" placement="top" popper-class="fct-tooltip" effect="dark" :trigger="['hover', 'focus']">
                  <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
                    <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
                  </button>
                </el-tooltip>
              </div>

              <div class="grid grid-cols-2 items-center">
                <div>
                  <div class="value">{{ card.value }}</div>
                  <div class="sub-value mt-1.5">{{ period }}</div>
                </div>
                <div>
                  <div class="value">{{ card.average }}</div>
                  <div class="sub-value mt-1.5">{{ translate("Average of closed periods") }}</div>
                </div>
              </div>

              <div class="description leading-[1.6] mt-3.5">{{ card.description }}</div>
              <div v-if="card.note" class="text-xs text-system-light dark:text-gray-400 mt-1">{{ card.note }}</div>
            </div>
          </div>
        </template>

        <Empty v-else icon="Empty/ListView" :has-dark="true"
            :text="yearly ? translate('SubRpt_Full_Year') : translate('SubRpt_Full_Month')"
            class="fct-report-empty-state" />
    </div>
</template>

<script setup>
import { computed } from "vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import { averageRate, formatAverage } from "@/Modules/Reports/Utils/retentionAverages";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import SummaryLoader from "@/Modules/Reports/Components/SummaryLoader.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import { completeRows, headlineRow, periodLabel, renewalsDue } from "./retentionRows";

// The last closed period: a month (or year) still running has had no time to churn
const props = defineProps({
    data: {type: Object, required: true},
    loading: {type: Boolean, default: true},
    yearly: {type: Boolean, default: false},
});

const money = (value) => new DOMParser().parseFromString(reportFilter.formatAmount(value || 0), "text/html").documentElement.textContent;
const percent = (value) => (value === null || value === undefined ? "—" : `${Number(value).toFixed(1)}%`);

const rows = computed(() => props.data?.retention_data ?? []);
const row = computed(() => headlineRow(rows.value));
const period = computed(() => (row.value ? periodLabel(row.value) : ""));

const cards = computed(() => {
    const r = row.value;
    if (!r) return [];

    const closed = completeRows(rows.value);
    const due = renewalsDue(r);
    const retrying = Number(r.renewals_retrying) || 0;

    return [
        {
            key: "retention",
            title: translate("Subscription Retention"),
            info: translate('SubRpt_Subscriptions_Active_Period'),
            value: percent(r.start_subscriptions > 0 ? r.retention_rate : null),
            average: formatAverage(averageRate(closed, "retention_rate", "start_subscriptions")),
            /* translators: %1$s - retained subscriptions, %2$s - subscriptions active when the period began, %3$s - the period */
            description: translate('SubRpt_Subscriptions_Active',
                formatNumber(r.retained_subscriptions), formatNumber(r.start_subscriptions), period.value),
            /* translators: %1$s - churn rate with its percent sign */
            note: r.start_subscriptions > 0 ? translate("%1$s churned", percent(100 - r.retention_rate)) : "",
        },
        {
            key: "mrr",
            title: translate("MRR Retention"),
            info: translate('SubRpt_Monthly_Recurring_Revenue'),
            value: percent(Number(r.start_mrr) > 0 ? r.retention_rate_money : null),
            average: formatAverage(averageRate(closed, "retention_rate_money", "start_mrr")),
            /* translators: %1$s - MRR kept, %2$s - MRR at the start of the period, %3$s - the period */
            description: translate("%1$s of the %2$s MRR at the start of %3$s was kept.", money(r.retained_mrr), money(r.start_mrr), period.value),
            /* translators: %1$s - MRR churn rate with its percent sign */
            note: Number(r.start_mrr) > 0 ? translate("%1$s MRR churned", percent(100 - r.retention_rate_money)) : "",
        },
        {
            key: "renewals",
            title: translate("Renewal Rate"),
            info: translate('SubRpt_Renewals_Fell'),
            value: percent(r.renewal_rate),
            average: formatAverage(averageRate(closed.map((item) => ({...item, due: renewalsDue(item)})), "renewal_rate", "due")),
            /* translators: %1$s - renewals paid, %2$s - renewals due, %3$s - the period */
            description: translate("%1$s of %2$s renewals due in %3$s were paid.", formatNumber(r.renewals_paid), formatNumber(due), period.value),
            /* translators: %1$s - renewals still retrying payment */
            note: retrying ? translate("%1$s more are still retrying payment.", formatNumber(retrying)) : "",
        },
    ];
});
</script>
