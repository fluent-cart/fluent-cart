<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-retention-report-page">
          <PageHeading>
            <template #title>
              <h2 class="page-title">
                {{ translate('Subscription Retention') }}

                <fct-tooltip
                            :content="translate('SubRpt_Retention_Compares')"
                            placement="top">
                  <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this report')">
                    <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
                  </button>
                </fct-tooltip>
              </h2>
            </template>
            <template #action>
              <div class="flex items-center gap-3">
                <!-- Mixed stores read each billing interval apart: a monthly plan churns monthly, a yearly one at renewal -->
                <fct-select v-if="intervals.length > 1" v-model="billingInterval" size="small" class="w-[160px]"
                           :aria-label="translate('Billing interval')" @change="refetch"
                           :options="[{label: translate('All intervals'), value: 'all'}, ...intervals.map(key => ({label: intervalLabel(key), value: key}))]"/>
                <fct-choice v-model="period" size="small" @change="refetch" type="radio" button :options="[{label: translate('Monthly'), value: 'month'}, {label: translate('Yearly'), value: 'year'}]"/>
              </div>
            </template>
          </PageHeading>

          <template v-if="!hasError">
            <Summary :data="data" :loading="loading" :yearly="period === 'year'" />

            <RetentionChart :data="data" :loading="loading" />

            <ExtensionComponent name="reports.retention.details" :data="data" :loading="loading" :yearly="period === 'year'" :report-filter="reportFilter">
              <ProReportPreview
                  :title="translate('Know what a subscriber is worth')"
                  :text="translate('SubRpt_See_Renewals')"
                  :bullets="[translate('Renewals paid and lost'), translate('Expected subscription lifetime'), translate('Lifetime value per subscriber')]"
                  placement="feature_lock_report_retention_lifetime"
              >
                <ProReportSample
                    :title="translate('Renewals')"
                    :columns="[translate('Period'), translate('Paid'), translate('Lost')]"
                    :rows="[[translate('Jan'), '90', '10'], [translate('Feb'), '85', '12'], [translate('Mar'), '88', '9']]"
                />
              </ProReportPreview>
            </ExtensionComponent>
          </template>

          <div v-else class="text-center pb-6">
            <Empty
                icon="Empty/ListView"
                :has-dark="true"
                :text="translate('This report could not be loaded.')"
                class="fct-report-empty-state"
            />
            <fct-button size="small" @click="retry">{{ translate('Retry') }}</fct-button>
          </div>
        </div>

    </UserCan>
</template>

<script setup>
/**
 * ----------------------------------------------------------------------------
 * Imports
 * ----------------------------------------------------------------------------
 */
import { ref, onMounted, onUnmounted } from "vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import ProReportPreview from "../../Components/ProReportPreview.vue";
import ProReportSample from "../../Components/ProReportSample.vue";
import Summary from "./Summary.vue";
import RetentionChart from "./RetentionChart.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";

/**
 * ----------------------------------------------------------------------------
 * Props and Data
 * ----------------------------------------------------------------------------
 */

const props = defineProps({
    reportFilter: {
        type: Object,
        required: true,
    },
});

const reportFilter = props.reportFilter;
const data = ref({
    retention_data: []
});
const loading = ref(true);
const billingInterval = ref("all");
const period = ref("month");
const intervals = ref([]);
const INTERVALS = {
    daily: translate("Daily"), weekly: translate("Weekly"), monthly: translate("Monthly"),
    quarterly: translate("Quarterly"), half_yearly: translate("Half-yearly"), yearly: translate("Yearly"),
};
const intervalLabel = (key) => INTERVALS[key] || key;
const hasError = ref(false);
const lastRequest = ref({ filters: null, groupKey: 'monthly' });
let requestSeq = 0;

/**
 * ----------------------------------------------------------------------------
 * Methods
 * ----------------------------------------------------------------------------
 */
const fetchData = (filters, groupKey = 'monthly') => {
    const seq = ++requestSeq;
    loading.value = true;
    hasError.value = false;
    lastRequest.value = { filters, groupKey };

    Rest.get('reports/subscription-retention', {
        params: {
            ...filters.params,
            groupKey: groupKey,
            billingInterval: billingInterval.value === "all" ? "" : billingInterval.value,
            period: period.value,
        }
    }).then(response => {
        if (seq !== requestSeq) return;
        data.value = response;
        intervals.value = response.intervals || [];
        // A currency or product with fewer intervals hides the switch; never keep filtering on a value it no longer offers
        if (billingInterval.value !== "all" && !intervals.value.includes(billingInterval.value)) {
            billingInterval.value = "all";
            refetch();
        }
    }).catch(() => {
        if (seq !== requestSeq) return;
        hasError.value = true;
    }).finally(() => {
        if (seq !== requestSeq) return;
        loading.value = false;
    })
}

const refetch = () => {
    if (lastRequest.value.filters) {
        fetchData(lastRequest.value.filters, lastRequest.value.groupKey);
    }
}

const retry = () => {
    fetchData(lastRequest.value.filters, lastRequest.value.groupKey);
}

/**
 * ----------------------------------------------------------------------------
 * Hooks
 * ----------------------------------------------------------------------------
 */
onMounted(() => {
    fetchData(reportFilter.applicableFilters);

    reportFilter.addListener("retention-report", fetchData);
});

onUnmounted(() => {
    reportFilter.removeListener("retention-report");
});
</script>
