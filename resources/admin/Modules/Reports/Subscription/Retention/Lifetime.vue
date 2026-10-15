<template>
    <div class="fct-retention-lifetime">
        <Card.Container>
            <Card.Header title_size="small" border_bottom>
                <template #title>
                    <div class="flex items-center gap-2">
                        <h4 class="fct-card-header-title is-small">
                            {{ translate("Lifetime") }}
                        </h4>
                    </div>
                </template>
            </Card.Header>

            <Card.Body>
                <el-skeleton v-if="loading" animated :rows="1" />

                <template v-else>
                    <div v-if="lifetimeSummary">
                        <div>
                            {{ lifetimeSummary.expected_lifetime_text }}
                        </div>
                        <div class="mt-2">
                            {{ lifetimeSummary.ltv_text }}
                        </div>
                    </div>
                    <Empty v-else icon="Empty/ListView" :has-dark="true"
                        :text="translate('Not enough data to calculate lifetime metrics.')"
                        class="fct-report-empty-state" />
                </template>
            </Card.Body>
        </Card.Container>
    </div>
</template>

<script setup>
/**
 * ----------------------------------------------------------------------------
 * Imports
 * ----------------------------------------------------------------------------
 */
import { computed } from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate from "@/utils/translator/Translator";
import { monthNames } from "@/Modules/Reports/Utils/monthNames";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import { averageRate, expectedLifetimeMonths } from "@/Modules/Reports/Utils/retentionAverages";

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

/**
 * ----------------------------------------------------------------------------
 * Computed Properties
 * ----------------------------------------------------------------------------
 */
const retentionData = computed(() => {
  return props.data?.retention_data ?? [];
});

const lifetimeSummary = computed(() => {
    const items = retentionData.value;
    if (!items.length) return null;

    const avgRetentionPercent = averageRate(items, 'retention_rate', 'start_subscriptions');
    if (avgRetentionPercent === null) return null;
    const avgRetentionDecimal = avgRetentionPercent / 100;

    const lifetime = expectedLifetimeMonths(avgRetentionDecimal);
    const expectedLifetime = lifetime.months;

    // 3. ARPU (Average Revenue Per User) for the LAST month
    const lastItem = items[items.length - 1];
    const activeSubs = parseInt(lastItem.active_subscriptions);
    const mrr = parseFloat(lastItem.mrr);

    let arpu = 0;
    if (activeSubs > 0) {
        arpu = mrr / activeSubs;
    }

    // 4. LTV = ARPU * Lifetime
    const ltv = arpu * expectedLifetime;

    const currentMonthDate = new Date(lastItem.day);
    const monthName = monthNames[currentMonthDate.getMonth()] + " " + currentMonthDate.getFullYear().toString();

    const avgRetentionText = `${avgRetentionPercent.toFixed(1)}%`;

    return {
        expected_lifetime_text: lifetime.capped
            /* translators: %1$s: retention rate percentage */
            ? translate('Based on an average monthly subscription retention rate of %1$s, your expected subscription lifetime is over 10 years.', avgRetentionText)
            /* translators: %1$s: retention rate percentage; %2$s: number of months */
            : translate('Based on an average monthly subscription retention rate of %1$s, your expected subscription lifetime is %2$s months.', avgRetentionText, expectedLifetime.toFixed(1)),
        ltv_text: lifetime.capped
            /* translators: %1$s: month name; %2$s: formatted revenue amount; %3$s: formatted lifetime value */
            ? translate('In %1$s, your average monthly revenue per subscriber was %2$s, giving you an expected subscription lifetime value of at least %3$s.', monthName, reportFilter.formatAmount(arpu), reportFilter.formatAmount(ltv))
            /* translators: %1$s: month name; %2$s: formatted revenue amount; %3$s: formatted lifetime value */
            : translate('In %1$s, your average monthly revenue per subscriber was %2$s, giving you an expected subscription lifetime value of %3$s.', monthName, reportFilter.formatAmount(arpu), reportFilter.formatAmount(ltv))
    };
});
</script>
