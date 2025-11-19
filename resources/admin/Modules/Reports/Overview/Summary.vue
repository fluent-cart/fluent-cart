<script setup>
import translate from "@/utils/translator/Translator";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

const props = defineProps({
  title: {
    type: String
  },

  current: {
    type: [String, Number],
    required: true,
  },

  previous: {
    type: [String, Number],
    required: true,
  },

  fluctuation: {
    type: [String, Number],
    required: true,
  }
});
</script>

<template>
    <div class="overview-summary-report-inline">
        <div class="inner-value">
            <ReportSummary 
                :amount="CurrencyFormatter.formatScaled(current)"
                :title="translate(title)" 
            />
        </div>

        <Fluctuation 
            :fluctuation="fluctuation" 
            :amount="previous / 100"
            :fluctuationSuffix="translate('YoY')"
        />
    </div>
</template>

<style scoped lang="scss">
.overview-summary-report-inline {
  display: flex;
  align-items: center;
  gap: 0.5rem;

  .percentage {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-weight: 600;
  }
}
</style>
