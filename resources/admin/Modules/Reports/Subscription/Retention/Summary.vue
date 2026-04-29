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
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
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

    // Calculate averages
    const totalRetention = items.reduce((sum, item) => sum + parseFloat(item.retention_rate), 0);
    const avgRetention = (totalRetention / items.length).toFixed(1);

    const totalMrrRetention = items.reduce((sum, item) => sum + parseFloat(item.retention_rate_money), 0);
    const avgMrrRetention = (totalMrrRetention / items.length).toFixed(1);

    // Get last item for detailed text
    const lastItem = items[items.length - 1];

    // Previous month logic
    let prevMonthName = '';
    let startCount = 0;
    let startMrr = 0;

    if (items.length > 1) {
        const prevItem = items[items.length - 2];
        const prevMonthDate = new Date(prevItem.day);
        prevMonthName = monthNames[prevMonthDate.getMonth()] + " " + prevMonthDate.getFullYear().toString();

        startCount = parseInt(prevItem.active_subscriptions);
        startMrr = parseFloat(prevItem.mrr);
    } else {
        // Fallback if only 1 month of data (less accurate but prevents crash)
        const prevMonthDate = new Date(lastItem.day);
        prevMonthDate.setMonth(prevMonthDate.getMonth() - 1);
        prevMonthName = monthNames[prevMonthDate.getMonth()] + " " + prevMonthDate.getFullYear().toString();

        // If we only have 1 month, we can't know the previous active count from the array.
        // We can either fallback to the reverse calc or show 0/unknown.
        // Let's keep the reverse calc ONLY for this edge case.
        const retainedCount = parseInt(lastItem.active_subscriptions) - parseInt(lastItem.new_subscriptions);
        startCount = lastItem.retention_rate > 0 ? Math.round(retainedCount / (lastItem.retention_rate / 100)) : 0;

        const retainedMrr = parseFloat(lastItem.mrr) - parseFloat(lastItem.new_subscriptions_mrr);
        startMrr = lastItem.retention_rate_money > 0 ? (retainedMrr / (lastItem.retention_rate_money / 100)) : 0;
    }

    const retainedCount = parseInt(lastItem.active_subscriptions) - parseInt(lastItem.new_subscriptions);
    const retainedMrr = parseFloat(lastItem.mrr) - parseFloat(lastItem.new_subscriptions_mrr);

    const retentionRate = parseFloat(lastItem.retention_rate);
    const retentionRateMoney = parseFloat(lastItem.retention_rate_money);
    const currentMonthDate = new Date(lastItem.day);
    const monthName = monthNames[currentMonthDate.getMonth()] + " " + currentMonthDate.getFullYear().toString();

    return [
        {
            title: translate("Subscription Retention"),
            value: `${retentionRate}%`,
            average_value: `${avgRetention}%`,
            month_name: monthName,
            sub_title: translate("Average in period"),
            description: translate(`You retained ${lastItem.retention_rate}% (${formatNumber(retainedCount)}) of your ${prevMonthName} subscriptions (${formatNumber(startCount)}).`),
            color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
        },
        {
            title: translate("MRR Retention"),
            value: `${retentionRateMoney}%`,
            average_value: `${avgMrrRetention}%`,
            month_name: monthName,
            sub_title: translate("Average in period"),
            description: translate(`You retained ${lastItem.retention_rate_money}% (${CurrencyFormatter.scaled(retainedMrr)}) of your ${prevMonthName} subscription MRR (${CurrencyFormatter.scaled(startMrr)}).`),
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
