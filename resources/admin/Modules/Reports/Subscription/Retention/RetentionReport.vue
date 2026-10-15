<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-retention-report-page">
          <PageHeading>
            <template #title>
              <h1 class="page-title">
                {{ translate('Subscription Retention') }}

                <el-tooltip popper-class="fct-tooltip"
                            :content="translate('Retention is calculated by looking at the active subscriptions at the end of each month, compared to the active subscriptions at the end of the start of each month.')"
                            placement="top">
                  <DynamicIcon class="text-gray-500 w-4 h-4 cursor-pointer" name="InformationFill" />
                </el-tooltip>
              </h1>
            </template>
          </PageHeading>

          <template v-if="!hasError">
            <Summary :data="data" :loading="loading" />

            <RetentionChart :data="data" :loading="loading" />

            <Lifetime :data="data" :loading="loading" />

            <ChurnSummary :data="data" :loading="loading" />

            <ChurnChart :data="data" :loading="loading" />
          </template>

          <div v-else class="text-center pb-6">
            <Empty
                icon="Empty/ListView"
                :has-dark="true"
                :text="translate('This report could not be loaded.')"
                class="fct-report-empty-state"
            />
            <el-button size="small" @click="retry">{{ translate('Retry') }}</el-button>
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
import Lifetime from "./Lifetime.vue";
import Summary from "./Summary.vue";
import RetentionChart from "./RetentionChart.vue";
import ChurnSummary from "./ChurnSummary.vue";
import ChurnChart from "./ChurnChart.vue";
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
            groupKey: groupKey
        }
    }).then(response => {
        if (seq !== requestSeq) return;
        data.value = response;
    }).catch(() => {
        if (seq !== requestSeq) return;
        hasError.value = true;
    }).finally(() => {
        if (seq !== requestSeq) return;
        loading.value = false;
    })
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
