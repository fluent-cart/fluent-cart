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

const churnSummary = computed(() => {
    const items = retentionData.value;
    if (!items.length) return [];

    // Calculate averages for churn rate
    const churnRates = items.map((item, index) => {
        // Start count is the previous month's active subscriptions
        const startSubs = index > 0 
            ? parseInt(items[index - 1].active_subscriptions) || 0 
            : parseInt(item.active_subscriptions) - parseInt(item.new_subscriptions) || 0;
        const churned = parseInt(item.churned_subscriptions) || 0;
        
        return startSubs > 0 ? (churned / startSubs) * 100 : 0;
    });

    const mrrChurnRates = items.map((item, index) => {
        // Start MRR is the previous month's MRR
        const startMrr = index > 0 
            ? parseFloat(items[index - 1].mrr) || 0 
            : parseFloat(item.mrr) - parseFloat(item.new_subscriptions_mrr) || 0;
        const churnedMrr = parseFloat(item.churned_subscriptions_mrr) || 0;
        
        return startMrr > 0 ? (churnedMrr / startMrr) * 100 : 0;
    });

    const avgChurnRate = (churnRates.reduce((sum, rate) => sum + rate, 0) / churnRates.length).toFixed(1);
    const avgMrrChurnRate = (mrrChurnRates.reduce((sum, rate) => sum + rate, 0) / mrrChurnRates.length).toFixed(1);

    // Get last item for detailed text
    const lastItem = items[items.length - 1];
    const lastIndex = items.length - 1;
    
    // Calculate start values from previous month
    const startCount = lastIndex > 0 
        ? parseInt(items[lastIndex - 1].active_subscriptions) || 0
        : parseInt(lastItem.active_subscriptions) - parseInt(lastItem.new_subscriptions) || 0;
        
    const startMrr = lastIndex > 0 
        ? parseFloat(items[lastIndex - 1].mrr) || 0
        : parseFloat(lastItem.mrr) - parseFloat(lastItem.new_subscriptions_mrr) || 0;
        
    const churnedCount = parseInt(lastItem.churned_subscriptions) || 0;
    const churnedMrr = parseFloat(lastItem.churned_subscriptions_mrr) || 0;

    const churnRate = startCount > 0 ? ((churnedCount / startCount) * 100).toFixed(1) : 0;
    const mrrChurnRate = startMrr > 0 ? ((churnedMrr / startMrr) * 100).toFixed(1) : 0;

    const currentMonthDate = new Date(lastItem.day);
    const monthName = monthNames[currentMonthDate.getMonth()] + " " + currentMonthDate.getFullYear().toString();

    // Previous month name for description
    let prevMonthName = '';
    if (items.length > 1) {
        const prevItem = items[items.length - 2];
        const prevMonthDate = new Date(prevItem.day);
        prevMonthName = monthNames[prevMonthDate.getMonth()] + " " + prevMonthDate.getFullYear().toString();
    } else {
        const prevMonthDate = new Date(lastItem.day);
        prevMonthDate.setMonth(prevMonthDate.getMonth() - 1);
        prevMonthName = monthNames[prevMonthDate.getMonth()] + " " + prevMonthDate.getFullYear().toString();
    }

    return [
        {
            title: translate("Subscription Churn"),
            value: `${churnRate}%`,
            average_value: `${avgChurnRate}%`,
            month_name: monthName,
            sub_title: translate("Average in period"),
            description: translate(`You lost ${churnRate}% (${formatNumber(churnedCount)}) of your ${prevMonthName} subscriptions (${formatNumber(startCount)}).`),
            color: isDarkTheme.value ? colors.dark_cyan_blue_16 : colors.light_gray,
        },
        {
            title: translate("MRR Churn"),
            value: `${mrrChurnRate}%`,
            average_value: `${avgMrrChurnRate}%`,
            month_name: monthName,
            sub_title: translate("Average in period"),
            description: translate(`You lost ${mrrChurnRate}% (${CurrencyFormatter.scaled(churnedMrr)}) of your ${prevMonthName} subscription MRR (${CurrencyFormatter.scaled(startMrr)}).`),
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
