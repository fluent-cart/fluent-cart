<script setup>
import { computed } from "vue";
import { formatNumber } from "../Utils/formatNumber";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import translate from "@/utils/translator/Translator";
import SummaryLoader from "../Components/SummaryLoader.vue";

const props = defineProps({
  reportFilter: {
    type: Object,
  },
  customerReport: {
    type: Object
  }
});

const summaryCards = [
  { title: translate("Customer Growth"), key: "customer_count" },
];

const dataLoader = computed(() => {
  return props.customerReport.loading;
});

const summaryData = computed(() => {
  return summaryCards.map((card) => ({
    key: card.key,
    title: card.title,
    value: props.customerReport.summary[card.key],
    fluctuation: props.customerReport.fluctuations[card.key],
  }));
});

const getPercentageClass = (percentage) => {
  if (percentage > 0) {
    return 'text-success-500';
  } else if (percentage < 0) {
    return 'text-red-500';
  } else {
    return 'text-system-light';
  }
};
</script>

<template>
  <SummaryLoader v-if="dataLoader" :count="summaryData.length" />

  <div v-else class="fct-report-order-summary">
    <div class="summary-item" v-for="(summary, index) in summaryData" :key="index">
      <div class="summary-item-inner">
        <div class="value">

          <div class="inner-value">
            {{ formatNumber(summary.value) }}
          </div>

          <Fluctuation 
            :fluctuation="summary.fluctuation"
            :has-currency="false"
            :amount="props.customerReport.previousSummary[summary.key]"
          />
        </div>

        <div class="title">
          {{ summary.title }}
        </div>
      </div>
    </div>
  </div>
</template>
