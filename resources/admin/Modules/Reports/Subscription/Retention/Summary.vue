<template>
    <SummaryLoader v-if="loading" :loading="loading" :count="2" />

    <div v-else :class="`fct-report-order-summary ${retentionSummary?.length ? 'summary-col-2' : ''}`">
        <template v-if="retentionSummary?.length">
          <div class="summary-item" v-for="(summary, summaryIndex) in retentionSummary" :key="summaryIndex">
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

const retentionSummary = computed(() => {
    const items = retentionData.value;
    if (!items.length) return [];

    const avgRetention = averageRate(items, 'retention_rate', 'start_subscriptions');
    const avgMrrRetention = averageRate(items, 'retention_rate_money', 'start_mrr');

    const lastItem = items[items.length - 1];

    const prevMonthDate = new Date(lastItem.day);
    prevMonthDate.setDate(1);
    prevMonthDate.setMonth(prevMonthDate.getMonth() - 1);
    const prevMonthName = monthNames[prevMonthDate.getMonth()] + " " + prevMonthDate.getFullYear().toString();

    const startCount = parseInt(lastItem.start_subscriptions) || 0;
    const startMrr = parseFloat(lastItem.start_mrr) || 0;

    const retainedCount = parseInt(lastItem.retained_subscriptions) || 0;
    const retainedMrr = parseFloat(lastItem.retained_mrr) || 0;

    const retentionRate = parseFloat(lastItem.retention_rate);
    const retentionRateMoney = parseFloat(lastItem.retention_rate_money);
    const currentMonthDate = new Date(lastItem.day);
    const monthName = monthNames[currentMonthDate.getMonth()] + " " + currentMonthDate.getFullYear().toString();

    return [
        {
            title: translate("Subscription Retention"),
            value: `${retentionRate}%`,
            average_value: formatAverage(avgRetention),
            month_name: monthName,
            sub_title: translate("Average in period"),
            /* translators: %1$s: retention rate percentage; %2$s: retained count; %3$s: month name; %4$s: starting count */
            description: translate('You retained %1$s (%2$s) of your %3$s subscriptions (%4$s).', `${lastItem.retention_rate}%`, formatNumber(retainedCount), prevMonthName, formatNumber(startCount)),
            color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
        },
        {
            title: translate("MRR Retention"),
            value: `${retentionRateMoney}%`,
            average_value: formatAverage(avgMrrRetention),
            month_name: monthName,
            sub_title: translate("Average in period"),
            /* translators: %1$s: retention rate percentage; %2$s: retained MRR amount; %3$s: month name; %4$s: starting MRR amount */
            description: translate('You retained %1$s (%2$s) of your %3$s subscription MRR (%4$s).', `${lastItem.retention_rate_money}%`, reportFilter.formatAmount(retainedMrr), prevMonthName, reportFilter.formatAmount(startMrr)),
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
