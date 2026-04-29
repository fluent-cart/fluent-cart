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
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

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

    // 1. Calculate Average Retention Rate (Decimal)
    const totalRetention = items.reduce((sum, item) => sum + parseFloat(item.retention_rate), 0);
    const avgRetentionPercent = totalRetention / items.length;
    const avgRetentionDecimal = avgRetentionPercent / 100;

    // 2. Expected Lifetime = 1 / (1 - Retention Rate)
    // If retention is 100%, lifetime is infinite, so cap or handle it.
    // If retention is 0%, lifetime is 1 month (or 0 depending on model, usually 1/Churn where Churn=1 -> 1 month)
    let expectedLifetime = 0;
    if (avgRetentionDecimal < 1) {
        const churnRate = 1 - avgRetentionDecimal;
        expectedLifetime = 1 / churnRate;
    } else {
        expectedLifetime = items.length; // Fallback for perfect retention
    }

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

    return {
        expected_lifetime_text: translate(`Based on an average monthly subscription retention rate of ${avgRetentionPercent.toFixed(1)}%, your expected subscription lifetime is ${expectedLifetime.toFixed(1)} months.`),
        ltv_text: translate(`In ${monthName}, your average monthly revenue per subscriber was ${CurrencyFormatter.scaled(arpu)}, giving you an expected subscription lifetime value of ${CurrencyFormatter.scaled(ltv)}.`)
    };
});
</script>
