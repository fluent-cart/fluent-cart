<script setup>
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import { ref, computed } from "vue";
import defaultReport from "@/Models/Reports/DefaultReportModel";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import translate from "@/utils/translator/Translator";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";

const props = defineProps({
  defaultReport: {
    type: Object,
    required: true,
  },
});

const reportData = defaultReport.data;
const currencySign = computed(() => {
  return reportFilter.currentCurrencySign;
});

const averageResults = ref([
  {
    title: translate("Average Order Net"),
    key: "average_order_net",
    content: translate("Net sales in this period divided by orders made."),
  },
  {
    title: translate("Average Order Items"),
    key: "average_order_items",
    content: translate("Total items sold divided by orders made."),
  },
  {
    title: translate("Average Customer Orders"),
    key: "average_customer_orders",
    content: translate(
      "Total orders divided by the number of unique customers."
    ),
  },
  {
    title: translate("Average Customer LTV"),
    key: "average_customer_ltv",
    content: translate(
      "Average total spend by customers that first ordered in this period, over their lifetime (not just from orders the customers made in this period)."
    ),
  },
]);
</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-report-avg-wrap">
      <div
        class="fct-report-result"
        v-for="(card, index) in averageResults"
        :key="index"
      >
        <div class="fct-report-result-content">
          <div class="value">
            <span
              v-html="currencySign"
              v-if="
                card.key !== 'average_customer_orders' &&
                card.key !== 'average_order_items'
              "
            >
            </span>
            {{ formatNumber(reportData.summaryData[card.key]) }}
            <div
              :class="`percentage ${
                reportData.fluctuations[card.key] > 0
                  ? 'text-success-500'
                  : 'text-red-500'
              }`"
              v-if="reportData.fluctuations[card.key] !== 0"
            >
              <DynamicIcon
                v-if="reportData.fluctuations[card.key] >= 0"
                class="w-2"
                name="CaretUp"
              />
              <DynamicIcon
                v-if="reportData.fluctuations[card.key] < 0"
                class="w-2"
                name="CaretDown"
              />
              {{ formatNumber(reportData.fluctuations[card.key]) }}%
            </div>
          </div>
          <div class="title">
            {{ card.title }}
            <el-tooltip
              v-if="card.content"
              popper-class="fct-tooltip"
              :content="card.content"
              placement="top"
            >
              <DynamicIcon name="InformationFill" class="w-4"  />
            </el-tooltip>
          </div>
        </div>
      </div>
    </div>
  </UserCan>
</template>
