<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { formatNumber } from "../Utils/formatNumber";
import { computed } from "vue";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

const props = defineProps({
  orderReport: {
    type: Object,
    required: true,
  },
  reportFilter: {
    type: Object,
  },
});

const currencySign = computed(() => {
  return props.reportFilter.currentCurrencySign;
});

const summaryCards = [
  // {
  //   title: translate("Gross Sales"),
  //   key: "gross_sale",
  //   content: translate(
  //     "Basically the Subtotal of the order without any deductions."
  //   ),
  //   currencySign: currencySign.value,
  // },
  // {
  //   title: translate("Total Refunds"),
  //   key: "total_refund",
  //   content: translate("Total number of refunds (full or partial)"),
  //   currencySign: currencySign.value,
  // },
  {
    title: translate("Taxes"),
    content: translate("Total amount of taxes"),
    key: "tax_total",
    currencySign: currencySign.value,
  },
  {
    title: translate("Shipping"),
    content: translate("Total amount of shipping costs"),
    key: "shipping_total",
    currencySign: currencySign.value,
  },
];

const averageResults = computed(() => {
  return summaryCards.map((card) => ({
    ...card,
    value: props.orderReport.data.summary[card.key] || 0,
    fluctuation: props.orderReport.data.fluctuations[card.key] || 0,
  })).filter((card) => card.value > 0);
});
</script>

<template>
  <div class="fct-report-avg-wrap mb-7.5" :style="`--avg-grid-cols: ${averageResults.length}`">
    <div
      class="fct-report-result"
      v-for="avg in averageResults"
      :key="avg.title"
    >
      <div class="fct-report-result-content">
        <div class="value">
          <span class="inline-flex">
            {{ CurrencyFormatter.scaled(avg.value) }}
          </span>
          <div
            class="percentage"
            :style="{ color: avg.fluctuation > 0 ? 'green' : 'red' }"
            v-if="avg.fluctuation !== 0"
          >
            <DynamicIcon
              v-if="avg.fluctuation > 0"
              class="w-2"
              name="CaretUp"
            />
            <DynamicIcon
              v-if="avg.fluctuation < 0"
              class="w-2"
              name="CaretDown"
            />
            {{ formatNumber(avg.fluctuation) }}%
          </div>
        </div>
        <div class="title">
          {{ avg.title }}
          <el-tooltip
            v-if="avg.content"
            popper-class="fct-tooltip"
            :content="avg.content"
            placement="top"
          >
            <DynamicIcon name="InformationFill" class="w-4" />
          </el-tooltip>
        </div>
      </div>
    </div>
  </div>
</template>
