<script setup>
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import AppConfig from "@/utils/Config/AppConfig";
import reportFilter from "@/Models/Reports/ReportFilterModel";

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
    type: [String, Number, null],
    required: true,
  },

  info: {
    type: String,
    default: ''
  }
});
</script>

<template>
    <div class="overview-summary-report-inline">
        <div class="inner-value">
            <ReportSummary 
                :amount="CurrencyFormatter.formatScaled(current, true, false, AppConfig.get('shop.currency'))"
                :title="translate(title)" 
            />
        </div>

        <template v-if="fluctuation === null">
            <span v-if="reportFilter.data.compareType !== 'no_comparison'" class="percentage">
                {{ Number(current) > 0 ? translate('New') : '—' }}
            </span>
        </template>
        <Fluctuation
            v-else
            :fluctuation="fluctuation" 
            :amount="previous / 100"
            :currency="AppConfig.get('shop.currency')"
            :fluctuationSuffix="translate('YoY')"
        />

        <el-tooltip v-if="info" :content="info" placement="top"
                    popper-class="fct-tooltip" effect="dark" :trigger="['hover', 'focus']">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help focus-visible:outline-2 focus-visible:outline-offset-2" :aria-label="translate('About this metric')">
              <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
            </button>
        </el-tooltip>
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
