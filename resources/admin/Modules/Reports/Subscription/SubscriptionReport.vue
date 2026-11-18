<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-subscription-report-page">
      <!-- Health is a snapshot; it does not follow the date range -->
      <div class="flex items-center gap-2 mb-3">
        <h2 class="m-0 text-sm font-semibold text-system-dark dark:text-gray-50">{{ translate("Right now") }}</h2>
        <span class="text-xs text-system-light dark:text-gray-400">{{ translate("Not affected by the date range") }}</span>
      </div>
      <SubscriptionHealth :reportFilter="reportFilter" />

      <div class="flex items-center gap-2 mb-3">
        <h2 class="m-0 text-sm font-semibold text-system-dark dark:text-gray-50">{{ translate("In this period") }}</h2>
      </div>
      <!-- A failed request has no figures: no zero cards, no empty chart, no "No active subscriptions" -->
      <Card.Container v-if="overview.error">
        <Card.Body>
          <ReportLoadError @retry="retry" />
        </Card.Body>
      </Card.Container>
      <template v-else>
      <SubscriptionPeriodCards :overview="overview.data" :loading="overview.loading" :reportFilter="reportFilter" />

      <SubscriptionMovementChart class="mb-7.5" :rows="(overview.data && overview.data.chart) || []" :loading="overview.loading"
                                 :reportFilter="reportFilter" @group-change="onGroupChange" />

      <ExtensionComponent name="reports.subscriptions.mrr_breakdown" :overview="overview.data" :loading="overview.loading" :report-filter="reportFilter">
        <ProReportPreview
            :title="translate('See where your recurring revenue comes from')"
            :text="translate('SubRpt_Split_Mrr')"
            :bullets="[translate('MRR by plan'), translate('MRR by billing interval'), translate('Trial conversion')]"
            placement="feature_lock_report_mrr_breakdown"
        >
          <ProReportSample
              :title="translate('MRR by plan')"
              :columns="[translate('Plan'), translate('MRR'), translate('Share')]"
              :rows="[[translate('Plan A'), '2,000.00', '50%'], [translate('Plan B'), '1,200.00', '30%'], [translate('Plan C'), '800.00', '20%']]"
          />
        </ProReportPreview>
      </ExtensionComponent>
      </template>
    </div>
  </UserCan>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import Rest from "@/utils/http/Rest";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import translate from "@/utils/translator/Translator";
import SubscriptionHealth from "@/Modules/Reports/Subscription/SubscriptionHealth.vue";
import SubscriptionPeriodCards from "./SubscriptionPeriodCards.vue";
import SubscriptionMovementChart from "./SubscriptionMovementChart.vue";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";
import ReportLoadError from "../Components/ReportLoadError.vue";
import * as Card from "@/Bits/Components/Card/Card.js";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const reportFilter = props.reportFilter;
const overview = ref({data: null, loading: true, error: false});
const groupKey = ref("default");
let overviewSeq = 0;
let lastParams = null;

const getOverview = (params) => {
  if (!params) return;
  const seq = ++overviewSeq;
  lastParams = params;
  overview.value = {...overview.value, loading: true, error: false};
  const request = Rest.get("reports/subscriptions-overview", {params: {...params.params, groupKey: groupKey.value}});
  request.then((response) => {
    if (seq === overviewSeq) overview.value = {data: response, loading: false, error: false};
  }).catch(() => {
    if (seq === overviewSeq) overview.value = {data: null, loading: false, error: true};
  });
  reportFilter.trackCompareRange(request);
};
const retry = () => getOverview(lastParams);

const onGroupChange = (value) => {
  groupKey.value = value;
  getOverview(reportFilter.applicableFilters);
};

onMounted(() => {
  reportFilter.addListener("subscription-report", getOverview);
  getOverview(reportFilter.applicableFilters);
});

onUnmounted(() => {
  reportFilter.removeListener("subscription-report");
});
</script>
