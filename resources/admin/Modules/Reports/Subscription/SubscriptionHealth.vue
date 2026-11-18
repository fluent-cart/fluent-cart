<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import SummaryLoader from "@/Modules/Reports/Components/SummaryLoader.vue";
import ReportLoadError from "@/Modules/Reports/Components/ReportLoadError.vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import { formatNumber } from "../Utils/formatNumber";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const health = ref(null);
const loading = ref(true);
const failed = ref(false);
let requestSeq = 0;

// Only the latest request fills the cards: a slow answer for the previous filters never lands under the new ones.
// A failed one clears them and says so, rather than keep the previous filters' figures.
const fetchHealth = (filters) => {
  const seq = ++requestSeq;
  loading.value = true;
  failed.value = false;
  Rest.get("reports/subscription-health", { params: filters.params })
    .then((response) => {
      if (seq === requestSeq) health.value = response.health;
    })
    .catch(() => {
      if (seq === requestSeq) {
        health.value = null;
        failed.value = true;
      }
    })
    .finally(() => {
      if (seq === requestSeq) loading.value = false;
    });
};

const cards = computed(() => {
  const data = health.value;
  if (!data) {
    return [];
  }

  return [
    {
      title: translate("MRR"),
      value: data.mrr,
      money: true,
      info: translate('SubRpt_Monthly_Recurring'),
    },
    {
      title: translate("Paying Subscriptions"),
      value: data.subscriptions,
      info: translate('OverviewRpt_Active_Subscriptions'),
    },
    {
      title: translate("ARPU"),
      value: data.arpu,
      money: true,
      info: translate('SubRpt_Average_Revenue'),
    },
    {
      title: translate("Discounted First Years"),
      value: data.discounted.mrr,
      money: true,
      /* translators: %1$s: number of subscriptions */
      info: translate('SubRpt_Mrr_Subscriptions', data.discounted.count),
    },
    {
      title: translate("Uplift When Discounts Renew"),
      value: data.discounted.uplift,
      money: true,
      info: translate("What MRR gains when the discounted first cycles renew at their full price."),
    },
    {
      title: translate("Free Trials"),
      value: data.trial.count,
      /* translators: %1$s: monthly amount after the trial */
      info: translate("Subscriptions in a free trial. Not in MRR. Their MRR after the trial: %1$s.", props.reportFilter.formatAmount(data.trial.renewal_mrr)),
    },
    {
      title: translate("MRR at Risk"),
      value: data.failing.mrr,
      money: true,
      /* translators: %1$s: number of subscriptions */
      info: translate('SubRpt_Mrr_Subscriptions_Whose', data.failing.count),
    },
    {
      title: translate("Canceled, Ends Soon"),
      value: data.canceling ? data.canceling.mrr : 0,
      money: true,
      /* translators: %1$s: number of subscriptions */
      info: translate('SubRpt_Mrr_Canceled', data.canceling ? data.canceling.count : 0),
    },
    {
      title: translate("Paused MRR"),
      value: data.paused.mrr,
      money: true,
      /* translators: %1$s: number of subscriptions */
      info: translate("MRR of paused subscriptions. Not in MRR. Subscriptions: %1$s.", data.paused.count),
    },
  ];
});

onMounted(() => {
  props.reportFilter.addListener("subscription-health", fetchHealth);
  fetchHealth(props.reportFilter.applicableFilters);
});

onUnmounted(() => {
  props.reportFilter.removeListener("subscription-health");
  requestSeq++;
});
</script>

<template>
  <SummaryLoader v-if="loading" :loading="loading" :count="8" />

  <ReportLoadError v-else-if="failed" @retry="fetchHealth(reportFilter.applicableFilters)" />

  <div v-else-if="cards.length" class="fct-report-order-summary summary-col-3">
    <div class="summary-item" v-for="card in cards" :key="card.title">
      <div class="summary-item-inner">
        <div class="value">
          <div class="inner-value">
            <ReportSummary
              :amount="card.money ? reportFilter.formatAmount(card.value) : formatNumber(card.value)"
              :title="card.title"
            />
          </div>
        </div>

        <div class="title">
          {{ card.title }}

          <fct-tooltip :content="card.info" placement="top">
            <DynamicIcon class="text-gray-500 w-4 h-4 cursor-help" name="InformationFill" />
          </fct-tooltip>
        </div>
      </div>
    </div>
  </div>
</template>
