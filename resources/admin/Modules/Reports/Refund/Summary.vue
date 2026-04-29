<template>
  <SummaryLoader v-if="dataLoader" :loading="dataLoader" :count="4" />

  <div v-else class="fct-report-order-summary summary-col-4">
    <div class="summary-item" v-for="(summary, summaryIndex) in summaryCards" :key="summaryIndex">
      <div class="summary-item-inner">
        <div class="value">
          <div class="inner-value">

            <span v-html="formatSummary(summary)"></span>

            
          </div><!-- inner-value -->

          <Fluctuation 
            :fluctuation="summary.percentage" 
            :amount="refundReport.data.previousSummary[summary.key]"
            :is-liability="true"
            :has-currency="!summary.no_currency"
            :is-percentage="summary.is_percentage"
          />
        </div><!-- value -->

        <div class="title">
          {{ summary.title }}
          <el-tooltip :content="summary.info" placement="top"
                      popper-class="fct-tooltip">
            <DynamicIcon class="text-gray-500 w-4 flex-shrink-0" name="InformationFill" />
          </el-tooltip>
        </div><!-- title -->
      </div>
    </div>
  </div>
</template>
<script setup>
import { formatNumber } from "../Utils/formatNumber";
import { computed } from "vue";
import checkBoxFilter from "@/Models/Reports/CheckBoxFilterModel";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import CurrencyFormatter from '@/utils/support/CurrencyFormatter';
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import refundReport from "@/Models/Reports/RefundReportModel";
import SummaryLoader from "../Components/SummaryLoader.vue";

const summaryCards = computed(() => [
  {
    title: translate("Refunds"),
    value: refundReport.data.summary.refund_count,
    key: 'refund_count',
    percentage: refundReport.data.fluctuations.refund_count,
    info: translate("Total refunds count including partially refunds."),
    no_currency: true
  },
  {
    title: translate("Refunded"),
    value: refundReport.data.summary.refunded_amount,
    key: 'refunded_amount',
    percentage: refundReport.data.fluctuations.refunded_amount,
    info: translate("Total Refunded Amount"),
  },
  {
    title: translate("Avgerage Amount"),
    value: refundReport.data.summary.average_refunded_amount,
    key: 'average_refunded_amount',
    percentage: refundReport.data.fluctuations.average_refunded_amount,
    info: translate(
      "Total Refunded Amount / Total Refund count"
    ),
  },
  {
    title: translate("Refund Rate"),
    value: refundReport.data.summary.refund_rate,
    key: 'refund_rate',
    percentage: refundReport.data.fluctuations.refund_rate,
    info: translate(
      "Total Refunded Amount / Total Order Amount"
    ),
    no_currency: true,
    is_percentage: true
  },
]);

const dataLoader = computed(() => {
  return refundReport.data.isBusy.chartData;
});

const formatSummary = (summary) => {
  const value = !summary.no_currency
      ? CurrencyFormatter.scaled(summary.value)
      : formatNumber(summary.value)

  return summary.is_percentage ? `${value}%` : value
}
</script>
