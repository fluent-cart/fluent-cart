<script setup>
/**
 * ----------------------------------------------------------------------------
 * Imports
 * ----------------------------------------------------------------------------
 */
import { computed } from "vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import { formatNumber, formatCompactNumber } from "@/Modules/Reports/Utils/formatNumber";
import reportFilter from "@/Models/Reports/ReportFilterModel";

/**
 * ----------------------------------------------------------------------------
 * Props
 * ----------------------------------------------------------------------------
 */
const props = defineProps({
    fluctuation: {
        type: [String, Number],
        required: true
    },

    isLiability: {
        type: Boolean,
        default: false
    },

    placement: {
        type: String,
        default: 'top'
    },

    amount: {
        type: [String, Number],
        required: false
    },

    title: {
        type: String,
        default: ''
    },

    hasCurrency: {
        type: Boolean,
        default: true
    },

    isPercentage: {
        type: Boolean,
        default: false
    },

    fluctuationSuffix: {
        type: String,
        default: ''
    },

    // Currency of `amount`; defaults to the currency the report is shown in.
    currency: {
        type: String,
        default: ''
    }
});

/**
 * ----------------------------------------------------------------------------
 * Computed Properties
 * ----------------------------------------------------------------------------
 */

// Check if the current report has a comparison type other than 'no_comparison'
const hasComparison = computed(() => {
    return reportFilter.data.compareType !== 'no_comparison';
});

const fluctuationDisplay = computed(() => {
    return formatCompactNumber(props.fluctuation);
});

const amount = computed(() => {
    if (!props.hasCurrency) {
        return formatNumber(props.amount);
    }

    return CurrencyFormatter.scaled(props.amount, true, false, props.currency || reportFilter.reportCurrency);
});

const tooltip = computed(() => {
    const extension = props.title ? props.title : translate("Previously");

    return extension + ' ' + amount.value + (props.isPercentage ? '%' : '');
});

const percentageClass = computed(() => {
    if (props.fluctuation === 0) return 'text-system-light';

    const isPositive = props.fluctuation > 0;

    /**
     * If it's a liability, a negative fluctuation is good (less liability),
     */
    const isSuccess = props.isLiability ? !isPositive : isPositive;

    return isSuccess ? 'text-success-500' : 'text-red-500';
});

const icon = computed(() => props.fluctuation < 0 ? 'CaretDown' : 'CaretUp');
</script>

<template>
    <div v-if="hasComparison" class="percentage" :class="percentageClass">
        <DynamicIcon class="w-2" :name="icon" v-if="fluctuation !== 0" />

        <el-tooltip effect="dark" :placement="placement" popper-class="fct-tooltip">
            <template #content>
                <span>{{ tooltip }}</span>
            </template>
            <span class="cursor-pointer" :class="percentageClass">
                <span dir="ltr">{{ fluctuationDisplay }}%</span>
                <template v-if="fluctuationSuffix">{{ fluctuationSuffix }}</template>
            </span>
        </el-tooltip>
    </div>
</template>
