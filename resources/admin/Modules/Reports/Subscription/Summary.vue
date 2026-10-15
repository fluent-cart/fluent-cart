<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {formatNumber} from "../Utils/formatNumber";
import {computed} from "vue";
import translate from "@/utils/translator/Translator";

import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import SummaryLoader from "@/Modules/Reports/Components/SummaryLoader.vue";

const props = defineProps({
  summaryData: {
    type: Object,
    required: true,
  },
  fluctuations: {
    type: Object,
    required: true,
  },
  reportFilter: {
    type: Object,
  },
  subscriptionReport: {
    type: Object
  },
  loading: {
    type: Boolean,
    default: true
  }
});

const currencySign = computed(() => {
  return props.reportFilter?.currentCurrencySign || "";
});

// Define which metrics to show and in what order
const summaryCardKeys = [
  // "total_subscriptions",
  // //"net_new_mrr",
  // "subscriber_churn_rate",
  // //"revenue_churn_rate_net",
  // "arpu",
  // "ltv",
  // "total_activations",
  // "total_payments",
  // "total_refunds",
  // "failed_payments_count",
  
  {
    title: translate("Installments Still Due"),
    key: "future_installments",
    info: translate("Remaining balance on installment plans started in the selected period."),
    hasCurrency: true,
  },
];

const summaryData = computed(() => {
  if (!props.summaryData || !props.fluctuations) return [];

  return summaryCardKeys.map((card) => {
    // const item = props.summaryData[key] || {
    //   title: key,
    //   value: 0,
    //   isCurrency: false,
    // };

    // // Add null check for fluctuations data
    // const fluctuation = props.fluctuations[key] || {
    //   value: "0%",
    //   trend: "neutral",
    // };

    return {
      ...card,
      title: card.title,
      value: props.summaryData[card.key] || 0,
      fluctuation: props.fluctuations[card.key] || 0,
      hasCurrency: card.hasCurrency,
    };
  });
});
</script>

<template>
  <SummaryLoader v-if="loading" :loading="loading" />
  
  <div v-else class="fct-report-order-summary summary-col-4">
    <div class="summary-item" v-for="(card, index) in summaryData" :key="index">
      <div class="summary-item-inner">
        <div class="value">
          <div class="inner-value">
            <ReportSummary :amount="reportFilter.formatAmount(card.value)" :title="card.title" />
          </div><!-- inner-value -->

          <!-- <Fluctuation 
            :fluctuation="card.fluctuation" 
            :amount="card.fluctuation"
          /> -->
        </div><!-- value -->

        <div class="title">
          {{ card.title }}

          <el-tooltip :content="card.info" placement="top"
                      popper-class="fct-tooltip" effect="dark">
            <DynamicIcon
                class="text-gray-500 w-4 h-4 cursor-help"
                name="InformationFill"
            />
          </el-tooltip>
        </div><!-- title -->
      </div>
    </div>

  </div>
</template>
