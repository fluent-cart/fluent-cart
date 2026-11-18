<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-cohort-report-page">
          <PageHeading>
            <template #title>
              <h2 class="page-title">
                {{ translate('Subscription Cohorts') }}

                <fct-tooltip
                            :content="translate('SubRpt_Row_Customers')"
                            placement="top">
                  <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this report')">
                    <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
                  </button>
                </fct-tooltip>
              </h2>
            </template>
            <template #action>
              <div class="flex items-center gap-3">
                <!-- The data source sits with the page, not with one table -->
                <span v-if="cohortData.generated_at" class="text-xs text-gray-500 dark:text-gray-400">{{ generatedText }}</span>
                <fct-button size="small" @click="generateSnapshots" :loading="generating">
                  <template v-if="generating && buildProgress">
                    <!-- translators: %1$s is the finished step count, %2$s is the total step count -->
                    {{ translate('Building snapshots %1$s/%2$s', buildProgress.step, buildProgress.steps) }}
                  </template>
                  <template v-else>{{ translate('Generate Snapshots') }}</template>
                </fct-button>
                <!-- Mixed stores read each billing interval apart: a monthly plan churns monthly, a yearly one at renewal -->
                <fct-select v-if="isFull && intervals.length > 1" v-model="billingInterval" size="small" class="w-[160px]"
                           :aria-label="translate('Billing interval')" @change="fetchData()"
                           :options="[{label: translate('All intervals'), value: 'all'}, ...intervals.map(key => ({label: intervalLabel(key), value: key}))]"/>
              </div>
            </template>
          </PageHeading>

          <el-alert v-if="!loading && isStale" type="warning" :closable="false" show-icon class="mb-4">
            <template #title>
              {{ staleText }}
              <fct-button link type="primary" class="ms-1" @click="generateSnapshots">{{ translate('Generate now') }}</fct-button>
            </template>
          </el-alert>

          <Card.Container class="overflow-hidden">
            <Card.Header>
              <div class="fct-cohort-settings">
                <div class="fct-cohort-settings-group">
                  <fct-choice v-model="groupBy" size="small" @change="fetchData()" type="radio" button :options="[{label: translate('Yearly'), value: 'year'}, {label: translate('Monthly'), value: 'month'}]"/>

                  <fct-choice v-if="isFull" v-model="metric" size="small" type="radio" button :options="[{label: translate('Customers'), value: 'subscribers'}, {label: translate('MRR'), value: 'mrr'}]"/>

                  <fct-choice v-model="displayMode" size="small" type="radio" button :options="[{label: translate('% of start'), value: 'percent'}, {label: translate('Values'), value: 'value'}]"/>

                  <div v-if="isFull" class="flex items-center gap-1.5">
                    <fct-switch v-model="rebuyAdjusted" size="small" :aria-label="translate('Count rebuys as retained')" @change="fetchData()" />
                    <span class="text-sm text-system-dark dark:text-gray-300">{{ translate('Count rebuys as retained') }}</span>
                    <fct-tooltip placement="top"
                                :content="translate('SubRpt_Customer_Whose')">
                      <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this setting')">
                        <DynamicIcon name="InformationFill" class="w-4 text-gray-500" aria-hidden="true" />
                      </button>
                    </fct-tooltip>
                  </div>
                </div>

                <ExportReport :data="exportData" :filename="'subscription_cohorts.csv'" />
              </div>
            </Card.Header>
            <Card.Body class="px-0 pb-0">
              <div v-if="loading" class="px-4 pb-4">
                <fct-skeleton animated :rows="10" />
              </div>

              <div v-if="!loading && hasError" class="text-center pb-6">
                <Empty icon="Empty/ListView" :has-dark="true" :text="translate('This report could not be loaded.')" class="fct-report-empty-state" />
                <fct-button size="small" @click="fetchData()">{{ translate('Retry') }}</fct-button>
              </div>

              <fct-table v-if="!loading && !hasError && !isEmpty" :data="tableData" border class="fct-cohort-el-table text-sm"
                        :cell-style="cellStyle" :span-method="spanMethod">
                <fct-table-column prop="cohort_label" :label="translate('First paid')" fixed="left" width="130">
                  <template #default="scope">
                    <span :class="{ 'font-bold': scope.row.is_average }">{{ scope.row.cohort_label }}</span>
                  </template>
                </fct-table-column>

                <fct-table-column prop="start_value" :label="_x('Start', 'Cohort column: starting count')" align="right" width="110" />

                <fct-table-column v-for="i in maxPeriods" :key="i" :label="periodLabel(i)" min-width="96" align="center">
                  <template #default="scope">
                    <fct-tooltip v-if="scope.row['tip_' + i]" placement="top" :content="scope.row['tip_' + i]">
                      <span :class="{ 'font-bold': scope.row.is_average, 'text-white': isDark(scope.row['rate_' + i]) }">{{ scope.row['cell_' + i] }}</span>
                    </fct-tooltip>
                    <span v-else :class="{ 'font-bold': scope.row.is_average, 'text-white': isDark(scope.row['rate_' + i]) }">{{ scope.row['cell_' + i] }}</span>
                  </template>
                </fct-table-column>
              </fct-table>

              <Empty v-if="!loading && !hasError && isEmpty" icon="Empty/ListView" :has-dark="true"
                     :text="cohortData.generated_at ? translate('No cohorts to show.') : translate('Click Generate Snapshots above to view cohort data.')"
                     class="fct-report-empty-state" />
            </Card.Body>
          </Card.Container>

          <CohortCurve v-if="loading || isFull" class="mt-6" :averages="cohortData.weighted_averages || []" :metric="metric" :group-by="groupBy" :loading="loading" />
          <ProReportPreview
              v-else
              class="mt-6"
              :title="translate('See how revenue holds up, not just customers')"
              :text="translate('SubRpt_Switch_Cohorts')"
              :bullets="[translate('MRR cohorts'), translate('Retention curve'), translate('Monthly and yearly plans apart'), translate('Count rebuys as retained')]"
              placement="feature_lock_report_cohorts"
          >
            <ProReportSample
                :title="translate('Retention curve')"
                :columns="[translate('Period'), translate('Customers'), translate('MRR')]"
                :rows="[['1', '90%', '92%'], ['2', '80%', '85%'], ['3', '72%', '78%']]"
            />
          </ProReportPreview>

          <ExtensionComponent name="reports.subscriptions.rebuys" class="mt-6" :report-filter="reportFilter" :billing-interval="billingInterval === 'all' ? '' : billingInterval">
            <ProReportPreview
                class="mt-6"
                :title="translate('Spot customers who cancel and buy again')"
                :text="translate('SubRpt_See_Let')"
                :bullets="[translate('Rebuys per period'), translate('Discount given on the new purchase')]"
                placement="feature_lock_report_rebuys"
            >
              <ProReportSample
                  :title="translate('Cancel and rebuy')"
                  :columns="[translate('Period'), translate('Ended'), translate('Rebought')]"
                  :rows="[[translate('Jan'), '100', '10'], [translate('Feb'), '100', '12'], [translate('Mar'), '100', '8']]"
              />
            </ProReportPreview>
          </ExtensionComponent>
        </div>
    </UserCan>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from "vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate, {_x} from "@/utils/translator/Translator";
import { dateTimeI18 } from "@/utils/Utils";
import Rest from "@/utils/http/Rest";
import ExportReport from "../ExportReport.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import { handleError, handleSuccess, $confirm } from "@/Bits/common";
import { prepareLabel } from "@/Modules/Reports/Utils/decorator";
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import CohortCurve from "./CohortCurve.vue";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";

const props = defineProps({
    reportFilter: {type: Object, required: true},
});

// Snapshots are rebuilt on demand; past this age the page asks for a new build
const STALE_DAYS = 7;
const INTERVALS = {
    daily: translate("Daily"), weekly: translate("Weekly"), monthly: translate("Monthly"),
    quarterly: translate("Quarterly"), half_yearly: translate("Half-yearly"), yearly: translate("Yearly"),
};

const reportFilter = props.reportFilter;
const cohortData = ref({cohorts: [], weighted_averages: [], intervals: [], full: false});
// FluentCart Pro serves MRR cohorts, billing intervals, rebuy-adjusted retention and the curve
const isFull = computed(() => !!cohortData.value.full);
const loading = ref(true);
const generating = ref(false);
// Step N of M of the build the page is running
const buildProgress = ref(null);
const hasError = ref(false);
let requestSeq = 0;
const groupBy = ref("year");
const metric = ref("subscribers");
const displayMode = ref("percent");
const billingInterval = ref("all");
const rebuyAdjusted = ref(false);
const maxPeriods = ref(12);

const intervals = computed(() => cohortData.value.intervals || []);
const intervalLabel = (key) => INTERVALS[key] || key;
const isEmpty = computed(() => !cohortData.value.cohorts || cohortData.value.cohorts.length === 0);
const money = (value) => new DOMParser().parseFromString(reportFilter.formatAmount(value || 0), "text/html").documentElement.textContent;
const isMrr = computed(() => metric.value === "mrr");

const generatedAt = computed(() => {
    const value = cohortData.value.generated_at;
    return value ? Date.parse(String(value).replace(" ", "T") + "Z") : null;
});
const isStale = computed(() => !generatedAt.value || Date.now() - generatedAt.value > STALE_DAYS * 86400000);
const generatedText = computed(() => {
    /* translators: %1$s - date and time the cohort data was last generated */
    return translate("Last generated %1$s", dateTimeI18(cohortData.value.generated_at, "date_time"));
});
const staleText = computed(() => {
    if (!generatedAt.value) {
        return translate("Cohort data has not been generated yet.");
    }
    /* translators: %1$s - date and time the cohort data was last generated */
    return translate('SubRpt_Cohort_Data', dateTimeI18(cohortData.value.generated_at, "date_time"));
});

const periodLabel = (i) => {
    if (groupBy.value === "year") {
        /* translators: %1$s - number of years since the cohort first paid */
        return translate("Year %1$s", i);
    }
    /* translators: %1$s - number of months since the cohort first paid */
    return translate("Month %1$s", i);
};

const rate = (period) => (isMrr.value ? period.retention_rate_mrr : period.retention_rate_count);
// A monthly cell marked partial is the store's current month, still running (the server decides, in store time)
const isRunning = (period) => groupBy.value === "month" && !!period.partial;
const cellText = (period, cohort) => {
    if (rate(period) === null || rate(period) === undefined) return "";
    const text = displayMode.value === "percent"
        ? `${rate(period)}%`
        : (isMrr.value ? money(period.retained_mrr) : formatNumber(period.retained_count));
    // A year cell only some monthly cohorts have reached yet, or the month still running
    return period.partial ? `${text}*` : text;
};
const cellTip = (period, cohort) => {
    if (rate(period) === null || rate(period) === undefined) return "";
    if (isRunning(period)) {
        /* translators: %1$s - retention percent so far in the month still running */
        return translate("%1$s so far: this month has not ended yet", `${rate(period)}%`);
    }
    if (period.partial) {
        /* translators: %1$s - retention percent, %2$s - cohorts that reached this year, %3$s - all monthly cohorts of the row */
        return translate("%1$s, from the %2$s of %3$s monthly cohorts that have reached this point", `${rate(period)}%`, period.matured_cohorts, period.total_cohorts);
    }
    if (isMrr.value) {
        /* translators: %1$s - retention percent, %2$s - MRR still coming in, %3$s - the cohort's starting MRR */
        return translate("%1$s: %2$s of %3$s MRR still coming in", `${period.retention_rate_mrr}%`, money(period.retained_mrr), money(cohort.start_mrr));
    }
    /* translators: %1$s - retention percent, %2$s - customers still subscribed, %3$s - customers in the cohort */
    return translate("%1$s: %2$s of %3$s customers still subscribed", `${period.retention_rate_count}%`, formatNumber(period.retained_count), formatNumber(cohort.start_count));
};

const tableData = computed(() => {
    if (isEmpty.value) return [];
    const rows = cohortData.value.cohorts.map((cohort) => {
        const row = {
            cohort_label: prepareLabel(String(cohort.cohort)),
            start_value: isMrr.value ? money(cohort.start_mrr) : formatNumber(cohort.start_count),
            is_average: false,
        };
        cohort.periods.forEach((period, index) => {
            row["cell_" + (index + 1)] = cellText(period, cohort);
            row["tip_" + (index + 1)] = cellTip(period, cohort);
            row["rate_" + (index + 1)] = rate(period);
        });
        return row;
    });

    if (cohortData.value.weighted_averages?.length) {
        const average = {cohort_label: translate("Weighted average"), start_value: "", is_average: true};
        cohortData.value.weighted_averages.forEach((avg) => {
            const value = isMrr.value ? avg.weighted_avg_mrr : avg.weighted_avg_count;
            average["cell_" + avg.offset] = `${value}%`;
            average["rate_" + avg.offset] = value;
        });
        rows.push(average);
    }
    return rows;
});

const exportData = computed(() => tableData.value.map((row) => {
    const out = {cohort: row.cohort_label, start: row.start_value};
    for (let i = 1; i <= maxPeriods.value; i++) {
        out[periodLabel(i)] = row["cell_" + i] || "";
    }
    return out;
}));

// Heat map over the table's own range: retention often sits within a few points, which a 0-100 scale paints alike
const range = computed(() => {
    const values = [];
    tableData.value.forEach((row) => {
        for (let i = 1; i <= maxPeriods.value; i++) {
            const value = row["rate_" + i];
            if (value !== null && value !== undefined) values.push(Number(value));
        }
    });
    return values.length ? [Math.min(...values), Math.max(...values)] : [0, 100];
});
const shade = (value) => {
    const [low, high] = range.value;
    return high > low ? 0.15 + 0.85 * (Number(value) - low) / (high - low) : 0.6;
};
const isDark = (value) => value !== null && value !== undefined && shade(value) > 0.55;
const cellStyle = ({row, columnIndex}) => {
    const value = row["rate_" + (columnIndex - 1)];
    if (columnIndex < 2 || value === null || value === undefined || value <= 0) return {};
    return {backgroundColor: `rgba(51, 92, 255, ${shade(value)})`};
};

const spanMethod = ({row, columnIndex}) => {
    if (row.is_average && columnIndex === 0) return {rowspan: 1, colspan: 2};
    if (row.is_average && columnIndex === 1) return {rowspan: 0, colspan: 0};
    return {rowspan: 1, colspan: 1};
};

const fetchData = () => {
    const seq = ++requestSeq;
    loading.value = true;
    hasError.value = false;

    Rest.get("reports/subscription-cohorts", {
        params: {
            ...reportFilter.applicableFilters.params,
            groupBy: groupBy.value,
            metric: metric.value,
            billingInterval: billingInterval.value === "all" ? "" : billingInterval.value,
            rebuyAdjusted: rebuyAdjusted.value ? 1 : 0,
        },
    }).then((response) => {
        if (seq !== requestSeq) return;
        cohortData.value = response;
        // An update changed the snapshot rules: rebuild once, without asking
        if (response.rebuild_needed && !generating.value) {
            runBuild({auto: true});
        }
        if (response.max_periods) {
            maxPeriods.value = response.max_periods;
        }
        // A currency without the chosen interval hides the switch; never keep filtering on a value it no longer offers
        if (billingInterval.value !== "all" && !(response.intervals || []).includes(billingInterval.value)) {
            billingInterval.value = "all";
            fetchData();
        }
    }).catch(() => {
        if (seq !== requestSeq) return;
        hasError.value = true;
    }).finally(() => {
        if (seq !== requestSeq) return;
        loading.value = false;
    });
};

const generateSnapshots = () => {
    $confirm(
        translate('SubRpt_Rebuilds_Cohort'),
        translate("Please confirm"),
        {confirmButtonText: translate("Continue"), cancelButtonText: translate("Cancel"), type: "warning"}
    ).then(runBuild).catch(() => {});
};

// The browser runs the build: one short request per step, so no background job, WP-Cron or long request is needed.
// The old snapshots stay until the last step publishes the new ones.
const runBuild = async (options) => {
    generating.value = true;
    buildProgress.value = null;
    try {
        const started = await Rest.post("reports/retention-snapshots/generate");
        // Another visitor's rebuild is publishing; the next visit shows its result
        if (options && options.auto && started.code === "already_running") {
            return;
        }
        if (!started.success) {
            handleError(started.message || translate("Failed to generate snapshots!"));
            return;
        }
        let step = {done: false, step: 0, steps: started.steps};
        while (!step.done) {
            buildProgress.value = {step: step.step, steps: step.steps};
            step = await Rest.post("reports/retention-snapshots/step", {token: started.token});
            if (!step.success) {
                handleError(step.message || translate("Failed to generate snapshots!"));
                return;
            }
        }
        handleSuccess(translate("Snapshots generated successfully!"));
        fetchData();
    } catch (error) {
        handleError(translate("Error generating snapshots"));
    } finally {
        generating.value = false;
        buildProgress.value = null;
    }
};

onMounted(() => {
    fetchData();
    reportFilter.addListener("cohort-report", fetchData);
});

onUnmounted(() => {
    reportFilter.removeListener("cohort-report");
});
</script>
