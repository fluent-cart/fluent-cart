<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-future-renewals-page">
          <PageHeading>
            <template #title>
              <h1 class="page-title">
                {{ translate('Future Renewals') }}
                <el-tooltip popper-class="fct-tooltip" placement="top" :content="pageInfo">
                  <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this report')">
                    <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
                  </button>
                </el-tooltip>
              </h1>
            </template>
            <template #action>
              <el-select v-model="horizon" size="small" class="w-[170px]" :aria-label="translate('Horizon')" @change="fetchData(reportFilter.applicableFilters)">
                <el-option v-for="option in horizonOptions" :key="option.value" :label="option.label" :value="option.value" />
              </el-select>
            </template>
          </PageHeading>

          <SummaryLoader v-if="loading" :loading="loading" :count="5" />
          <div v-else class="fct-report-order-summary summary-col-5">
            <div class="summary-item" v-for="card in cards" :key="card.key">
              <div class="summary-item-inner">
                <div class="value"><div class="inner-value">{{ card.value }}</div></div>
                <div class="title">
                  {{ card.title }}
                  <el-tooltip :content="card.info" placement="top" popper-class="fct-tooltip" effect="dark" :trigger="['hover', 'focus']">
                    <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
                      <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true" />
                    </button>
                  </el-tooltip>
                </div>
                <div v-if="card.sub" class="text-xs text-system-light dark:text-gray-400 mt-1">{{ card.sub }}</div>
              </div>
            </div>
          </div>

          <Card.Container v-if="!loading && sliders.length" class="mb-6">
            <Card.Header title_size="small" border_bottom>
              <template #title>
                <div class="flex items-center gap-2">
                  <h4 class="fct-card-header-title is-small">{{ translate('Renewal rate assumptions') }}</h4>
                  <el-tooltip popper-class="fct-tooltip" placement="top" :content="ratesInfo">
                    <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this setting')">
                      <DynamicIcon name="InformationFill" class="w-4 text-gray-500" aria-hidden="true" />
                    </button>
                  </el-tooltip>
                </div>
              </template>
              <template #action>
                <el-button size="small" :disabled="!changed" @click="resetRates">{{ translate('Reset to your history') }}</el-button>
              </template>
            </Card.Header>
            <Card.Body>
              <div class="grid gap-x-8 gap-y-4 md:grid-cols-2">
                <div v-for="slider in sliders" :key="slider.key">
                  <div class="flex justify-between text-sm mb-1">
                    <span class="text-system-dark dark:text-gray-200">{{ slider.label }}</span>
                    <span class="font-medium text-system-dark dark:text-gray-100">{{ rates[slider.key] }}%</span>
                  </div>
                  <el-slider v-model="rates[slider.key]" :min="0" :max="100" :step="1" :show-tooltip="false" :aria-label="slider.label" />
                  <div class="text-xs text-system-light dark:text-gray-400">{{ slider.hint }}</div>
                </div>
              </div>
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header title_size="small" border_bottom>
              <template #title>
                <h4 class="fct-card-header-title is-small">{{ translate('Renewals by month') }}</h4>
              </template>
            </Card.Header>
            <Card.Body>
              <el-skeleton v-if="loading" animated :rows="6" />
              <div v-else-if="!data.totalRenewals" class="py-10 text-center text-sm text-system-light dark:text-gray-400">
                {{ translate('No renewal is scheduled in this horizon') }}
              </div>
              <div v-else ref="chartRef" class="w-full h-[340px]"></div>
            </Card.Body>
          </Card.Container>

          <ExtensionComponent v-if="!loading" name="reports.renewals.variations" class="mt-6" :rows="variationRows" :report-filter="reportFilter">
            <ProReportPreview
                class="mt-6"
                :title="translate('See which products your renewals come from')"
                :text="translate('SubRpt_Split_Forecast')"
                :bullets="[translate('Renewals per variant'), translate('Ideal and projected amount per variant')]"
                placement="feature_lock_report_renewals_by_variant"
            >
              <ProReportSample
                  :title="translate('Renewals by Variant')"
                  :columns="[translate('Product'), translate('Variant'), translate('Renewals'), translate('Projected')]"
                  :rows="[[translate('Product A'), translate('Monthly'), '40', '2,000.00'], [translate('Product B'), translate('Yearly'), '10', '1,500.00']]"
              />
            </ProReportPreview>
          </ExtensionComponent>
        </div>
    </UserCan>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted, nextTick } from "vue";
import * as echarts from "echarts";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import SummaryLoader from "@/Modules/Reports/Components/SummaryLoader.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber";
import { chartAxisPointer, chartTooltipPosition } from "@/utils/Utils";
import { prepareLabel, storeTimeNote } from "@/Modules/Reports/Utils/decorator";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";

const props = defineProps({
    reportFilter: {type: Object, required: true},
});

const reportFilter = props.reportFilter;
const colors = Theme.colors.report;
const INTERVALS = {
    daily: translate("Daily plans"), weekly: translate("Weekly plans"), monthly: translate("Monthly plans"),
    quarterly: translate("Quarterly plans"), half_yearly: translate("Half-yearly plans"), yearly: translate("Yearly plans"),
};
const horizonOptions = [
    {value: '3', label: translate('Next 3 months')},
    {value: '6', label: translate('Next 6 months')},
    {value: '12', label: translate('Next 12 months')},
];

const horizon = ref('3');
const loading = ref(true);
const data = ref({totalRenewals: 0, totalProjected: 0, projections: [], by_interval: {}, trial: {}, rates: {}, trial_first: {count: 0, amount: 0},
    overdue: {count: 0, amount: 0}, retrying: {count: 0, amount: 0}, wont_renew: {count: 0, amount: 0, by_month: {}}, by_variation: []});
// Renewal rate per interval (and "trial" for first charges), in percent; the defaults are the store's own
const rates = reactive({});
const defaults = ref({});
const chartRef = ref(null);
let chart = null;
let requestSeq = 0;

const money = (cents) => new DOMParser().parseFromString(reportFilter.formatAmount(cents || 0, {cents: true}), "text/html").documentElement.textContent;

// The store's renewal rate across its intervals, weighted by what is due: the fallback for an interval with no history
const overallRate = (d) => {
    const known = Object.entries(d.rates || {}).filter(([, rate]) => rate !== null && rate !== undefined);
    if (!known.length) return null;
    const due = (key) => sumCents({[key]: d.by_interval?.[key]}) || 1;
    const weight = known.reduce((sum, [key]) => sum + due(key), 0);

    return Math.round(known.reduce((sum, [key, rate]) => sum + Number(rate) * due(key), 0) / weight);
};

// Intervals with renewals due, as plans or as trials that convert
const intervalKeys = (d) => [...new Set([...Object.keys(d.by_interval || {}), ...Object.keys(d.trial || {})])];
const sumCents = (byPosition) => Object.values(byPosition || {}).reduce((sum, row) => sum + Object.values(row || {}).reduce((a, b) => a + Number(b), 0), 0);

const applyDefaults = (d) => {
    const fallback = overallRate(d) ?? 100;
    const next = {};
    intervalKeys(d).forEach((key) => {
        const rate = d.rates?.[key];
        next[key] = rate === null || rate === undefined ? fallback : Math.round(Number(rate));
    });
    if (d.trial_first?.count) {
        next.trial = d.trial_rate === null || d.trial_rate === undefined ? 50 : Math.round(Number(d.trial_rate));
    }
    defaults.value = next;
    Object.keys(rates).forEach((key) => delete rates[key]);
    Object.assign(rates, next);
};
const resetRates = () => Object.assign(rates, defaults.value);
const changed = computed(() => Object.keys(defaults.value).some((key) => rates[key] !== defaults.value[key]));

const sliders = computed(() => {
    const list = intervalKeys(data.value).map((key) => {
        const history = data.value.rates?.[key];
        return {
            key,
            label: INTERVALS[key] || key,
            hint: history === null || history === undefined
                ? translate('SubRpt_Renewal_Plans')
                /* translators: %1$s - renewal rate with its percent sign */
                : translate("Your last 12 closed months: %1$s renewed.", `${history}%`),
        };
    });
    if (data.value.trial_first?.count) {
        list.push({
            key: "trial",
            label: translate("Free trials converting"),
            hint: data.value.trial_rate === null || data.value.trial_rate === undefined
                ? translate("No free trial ended in the last 12 closed months; starts at 50%.")
                /* translators: %1$s - trial conversion rate with its percent sign */
                : translate("Your last 12 closed months: %1$s of ended trials paid.", `${data.value.trial_rate}%`),
        });
    }
    return list;
});

// The k-th renewal of a subscription in the horizon is paid only if every one before it was: rate^k. A trial's
// charges come only if it converts first (k = 0 is the converting charge)
const expectedOf = (byInterval, trial) => {
    const rate = (key) => (rates[key] ?? 100) / 100;
    const convert = (rates.trial ?? 100) / 100;
    let cents = 0;
    Object.entries(byInterval || {}).forEach(([key, positions]) => {
        Object.entries(positions || {}).forEach(([k, amount]) => {
            cents += Number(amount) * Math.pow(rate(key), Number(k));
        });
    });
    Object.entries(trial || {}).forEach(([key, positions]) => {
        Object.entries(positions || {}).forEach(([k, amount]) => {
            cents += Number(amount) * convert * Math.pow(rate(key), Number(k));
        });
    });
    return Math.round(cents);
};

const expectedTotal = computed(() => expectedOf(data.value.by_interval, data.value.trial));
const monthly = computed(() => (data.value.projections || []).map((row) => ({
    label: prepareLabel(String(row.group)),
    scheduled: Number(row.projected_amount) || 0,
    expected: expectedOf(row.by_interval, row.trial),
    wont: Number(data.value.wont_renew?.by_month?.[row.group]) || 0,
})));

const variationRows = computed(() => (data.value.by_variation || []).map((row) => ({...row, expected: expectedOf(row.by_interval, row.trial)})));

const cards = computed(() => {
    const d = data.value;
    const atRisk = (d.retrying?.amount || 0) + (d.overdue?.amount || 0);
    return [
        {key: "scheduled", title: translate("Ideal Renewals (100%)"), value: money(d.totalProjected),
            info: translate('SubRpt_Every_Renewal'),
            /* translators: %1$s - number of renewals */
            sub: translate("%1$s renewals due", formatNumber(d.totalRenewals || 0))},
        {key: "expected", title: translate("Projected Renewals"), value: money(expectedTotal.value),
            info: translate('SubRpt_Ideal_Amount_Renewal'),
            /* translators: %1$s - projected share of the ideal amount, with its percent sign */
            sub: d.totalProjected ? translate("%1$s of ideal", `${Math.round(expectedTotal.value / d.totalProjected * 100)}%`) : ""},
        {key: "gap", title: translate("Projected Shortfall"), value: money(Math.max(0, (d.totalProjected || 0) - expectedTotal.value)),
            info: translate('SubRpt_Ideal_Amount')},
        {key: "wont", title: translate("Won't Renew"), value: money(d.wont_renew?.amount),
            info: translate('SubRpt_Canceled_Subscriptions'),
            /* translators: %1$s - number of subscriptions */
            sub: translate("%1$s canceled subscriptions", formatNumber(d.wont_renew?.count || 0))},
        {key: "risk", title: translate("At Risk Now"), value: money(atRisk),
            info: translate('SubRpt_Renewals_Already'),
            /* translators: %1$s - renewals in payment retry, %2$s - overdue renewals */
            sub: translate("%1$s retrying · %2$s overdue", formatNumber(d.retrying?.count || 0), formatNumber(d.overdue?.count || 0))},
    ];
});

const pageInfo = computed(() => {
    /* translators: %1$s - the store timezone note */
    return translate('SubRpt_Renewals_Scheduled', storeTimeNote());
});
const ratesInfo = translate('SubRpt_Share_Renewals');

const render = () => {
    if (!chartRef.value || loading.value || !data.value.totalRenewals) return;
    if (chart && chart.getDom() !== chartRef.value) {
        chart.dispose();
        chart = null;
    }
    if (!chart) chart = echarts.init(chartRef.value);
    const dark = Theme.isDark();
    const textColor = dark ? "#ffffff" : "#565865";
    const rows = monthly.value;
    const showWont = rows.some((row) => row.wont);
    const series = [
        {name: translate("Ideal (100%)"), type: "bar", barMaxWidth: 34, color: dark ? colors.dark_cyan_blue_16 : colors.light_gray_blue, data: rows.map((row) => row.scheduled)},
        {name: translate("Projected"), type: "line", symbolSize: 6, color: dark ? colors.blue_dark_mode : colors.royal_blue, data: rows.map((row) => row.expected)},
    ];
    if (showWont) {
        series.push({name: translate("Won't renew"), type: "bar", barMaxWidth: 34, color: colors.coral, data: rows.map((row) => row.wont)});
    }
    chart.setOption({
        legend: {show: true, itemGap: 20, itemWidth: 10, itemHeight: 10, textStyle: {color: textColor}},
        grid: {left: 16, right: 16, bottom: 8, top: 48, containLabel: true},
        tooltip: {
            trigger: "axis", confine: true, position: chartTooltipPosition,
            axisPointer: chartAxisPointer(dark, colors.dark_cyan_blue_16, colors.light_gray_blue),
            formatter: (params) => params[0].name + params.map((param) => `<div>${param.marker} ${param.seriesName}
                <span style="float: right; margin-left: 20px">${money(param.value)}</span></div>`).join(""),
        },
        xAxis: {type: "category", data: rows.map((row) => row.label), axisLabel: {color: textColor}},
        yAxis: {type: "value", axisLabel: {color: textColor, formatter: (value) => money(value)}, splitLine: {lineStyle: {type: "dashed", color: dark ? "#253241" : "#D6DAE1"}}},
        series,
    }, {notMerge: true});
};

const fetchData = (filters) => {
    const seq = ++requestSeq;
    loading.value = true;
    Rest.get("reports/future-renewals", {
        params: {...(filters || reportFilter.applicableFilters).params, horizon: horizon.value},
    }).then((response) => {
        if (seq !== requestSeq) return;
        data.value = response;
        applyDefaults(response);
    }).catch((error) => {
        if (seq === requestSeq) Notify.error(error);
    }).finally(() => {
        if (seq !== requestSeq) return;
        loading.value = false;
    });
};

watch(() => [monthly.value, loading.value], () => nextTick(render), {deep: true});
const resize = () => chart && chart.resize();
onMounted(() => {
    fetchData(reportFilter.applicableFilters);
    reportFilter.addListener("future-renewals", fetchData);
    window.addEventListener("resize", resize);
});
onUnmounted(() => {
    reportFilter.removeListener("future-renewals");
    window.removeEventListener("resize", resize);
    if (chart) {
        chart.dispose();
        chart = null;
    }
});
</script>
