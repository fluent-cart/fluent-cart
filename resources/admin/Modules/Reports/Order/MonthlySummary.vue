<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { formatNumber } from "../Utils/formatNumber";
import { computed } from "vue";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";

const props = defineProps({
  orderReport: {
    type: Object,
    required: true,
  },
});

const currencySign = computed(() => {
  return reportFilter.currentCurrencySign;
});

const summaryCards = [
  {
    title: translate("Average Monthly Gross"),
    key: "monthly_gross",
    currencySign: currencySign,
    hasCurrency: true,
  },
  {
    title: translate("Average Monthly Revenue"),
    key: "monthly_net",
    currencySign: currencySign,
    hasCurrency: true,
  },
  {
    title: translate("Average Monthly Orders"),
    key: "monthly_orders",
  },
  {
    title: translate("Average Monthly Items"),
    key: "monthly_items",
  },
];

const summaryData = computed(() => {
  return summaryCards.map((card) => ({
    ...card,
    value: props.orderReport.data.summary[card.key] || 0,
    fluctuation: props.orderReport.data.fluctuations[card.key] || 0,
    hasCurrency: card.hasCurrency || false,
  }));
});

const formatAmount = (summary) => {
  return ['monthly_net', 'monthly_gross'].includes(summary.key) 
    ? CurrencyFormatter.scaled(summary.value)
    : formatNumber(summary.value);
};
</script>

<template>
  <ul class="fct-report-monthly-list">
    <li v-for="(card, index) in summaryData" :key="index">
      <div class="list-left-content">
        <div class="list-title">
          {{ card.title }}
        </div>
      </div>
      <div class="list-right-content">
        <div class="list-value">
           <ReportSummary :amount="formatAmount(card)" :title="card.title" />

           <Fluctuation 
            :fluctuation="card.fluctuation" 
            :amount="orderReport.data.previousSummary[card.key]"
            :is-liability="false"
            :has-currency="card.hasCurrency"
          />
        </div>
      </div>
    </li>
  </ul>
</template>
