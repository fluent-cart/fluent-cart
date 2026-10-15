<template>
    <SummaryLoader v-if="loading" :loading="loading" :count="2" />

    <div v-else :class="`fct-report-order-summary ${churnSummary?.length ? 'summary-col-2' : ''}`">
        <template v-if="churnSummary?.length">
          <div class="summary-item" v-for="(summary, summaryIndex) in churnSummary" :key="summaryIndex">
            <div class="summary-item-inner">
              <div class="title text-[16px] mb-2.5">
                {{ summary.title }}
              </div>

              <div class="grid grid-cols-2 items-center">
                <div>
                  <div class="value">
                    {{ summary.value }}
                  </div>

                  <div class="sub-value mt-1.5">
                    {{ summary.month_name }}
                  </div>
                </div>
                <div>
                  <div class="value">
                    {{ summary.average_value }}
                  </div>

                  <div class="sub-value mt-1.5">
                    {{ summary.sub_title }}
                  </div>
                </div>
              </div>

              <div class="description leading-[1.6] mt-3.5">
                {{ summary.description }}
              </div>
            </div>
          </div>
        </template>
      <Empty v-else icon="Empty/ListView" :has-dark="true"
             :text="translate('Currently there is no data!')"
             class="fct-report-empty-state" />
    </div>
</template>

<script setup>
/**
 * ----------------------------------------------------------------------------
 * Imports
 * ----------------------------------------------------------------------------
 */
import Theme from "@/utils/Theme";
import { ref, onMounted, computed, onUnmounted } from "vue";
import translate from "@/utils/translator/Translator";
import { monthNames } from "@/Modules/Reports/Utils/monthNames";
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import { averageRate, formatAverage } from "@/Modules/Reports/Utils/retentionAverages";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import SummaryLoader from "@/Modules/Reports/Components/SummaryLoader.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";

/**
 * ----------------------------------------------------------------------------
 * Props and Data
 * ----------------------------------------------------------------------------
 */
const props = defineProps({
    data: {
        type: Object,
        required: true
    },

    loading: {
        type: Boolean,
        default: true,
    },
});

const colors = Theme.colors.report;
const isDarkTheme = ref(Theme.isDark());

/**
 * ----------------------------------------------------------------------------
 * Computed Properties
 * ----------------------------------------------------------------------------
 */
const retentionData = computed(() => {
  return props.data?.retention_data ?? [];
});

const churnSummary = computed(() => {
    const items = retentionData.value;
    if (!items.length) return [];

    const churnRates = items.map((item) => {
        const startSubs = parseInt(item.start_subscriptions) || 0;
        const startMrr = parseFloat(item.start_mrr) || 0;
        return {
            start_subscriptions: startSubs,
            start_mrr: startMrr,
            churn_rate: startSubs > 0 ? ((parseInt(item.churned_subscriptions) || 0) / startSubs) * 100 : 0,
            mrr_churn_rate: startMrr > 0 ? ((parseFloat(item.churned_subscriptions_mrr) || 0) / startMrr) * 100 : 0,
        };
    });

    const avgChurnRate = averageRate(churnRates, 'churn_rate', 'start_subscriptions');
    const avgMrrChurnRate = averageRate(churnRates, 'mrr_churn_rate', 'start_mrr');

    const lastItem = items[items.length - 1];
    const lastRates = churnRates[churnRates.length - 1];
    const startCount = lastRates.start_subscriptions;
    const startMrr = lastRates.start_mrr;

    const churnedCount = parseInt(lastItem.churned_subscriptions) || 0;
    const churnedMrr = parseFloat(lastItem.churned_subscriptions_mrr) || 0;

    const churnRate = startCount > 0 ? lastRates.churn_rate.toFixed(1) : 0;
    const mrrChurnRate = startMrr > 0 ? lastRates.mrr_churn_rate.toFixed(1) : 0;

    const currentMonthDate = new Date(lastItem.day);
    const monthName = monthNames[currentMonthDate.getMonth()] + " " + currentMonthDate.getFullYear().toString();

    const prevMonthDate = new Date(lastItem.day);
    prevMonthDate.setDate(1);
    prevMonthDate.setMonth(prevMonthDate.getMonth() - 1);
    const prevMonthName = monthNames[prevMonthDate.getMonth()] + " " + prevMonthDate.getFullYear().toString();

    return [
        {
            title: translate("Subscription Churn"),
            value: `${churnRate}%`,
            average_value: formatAverage(avgChurnRate),
            month_name: monthName,
            sub_title: translate("Average in period"),
            /* translators: %1$s: churn rate percentage; %2$s: churned count; %3$s: month name; %4$s: starting count */
            description: translate('You lost %1$s (%2$s) of your %3$s subscriptions (%4$s).', `${churnRate}%`, formatNumber(churnedCount), prevMonthName, formatNumber(startCount)),
            color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
        },
        {
            title: translate("MRR Churn"),
            value: `${mrrChurnRate}%`,
            average_value: formatAverage(avgMrrChurnRate),
            month_name: monthName,
            sub_title: translate("Average in period"),
            /* translators: %1$s: churn rate percentage; %2$s: churned MRR amount; %3$s: month name; %4$s: starting MRR amount */
            description: translate('You lost %1$s (%2$s) of your %3$s subscription MRR (%4$s).', `${mrrChurnRate}%`, reportFilter.formatAmount(churnedMrr), prevMonthName, reportFilter.formatAmount(startMrr)),
            color: isDarkTheme.value ? colors.dark_cyan_blue_36 : colors.light_gray_cyan_blue,
        }
    ];
});

/**
 * ----------------------------------------------------------------------------
 * Methods
 * ----------------------------------------------------------------------------
 */
const handleThemeChange = () => {
    isDarkTheme.value = Theme.isDark();
};

/**
 * ----------------------------------------------------------------------------
 * Hooks
 * ----------------------------------------------------------------------------
 */
onMounted(() => {
    window.addEventListener("onFluentCartThemeChange", handleThemeChange);
});

onUnmounted(() => {
    window.removeEventListener("onFluentCartThemeChange", handleThemeChange, false);
});
</script>
