<script setup>
import { siteToday } from "@/Modules/Reports/Utils/datePresets";
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from "vue";
import * as echarts from "echarts";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import Permission from "@/utils/permission/Permission";
import * as Card from "@/Bits/Components/Card/Card.js";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import SummaryLoader from "../Components/SummaryLoader.vue";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Pagination from "@/Bits/Components/Pagination.vue";
import Utils, {fluentDayjsLocale, resolveDateFormat, resolveYearAwareFormat, getDateTimeStringI18} from "@/utils/Utils";
import * as Fluid from "@/Bits/Components/FluidTab/FluidTab.js";
import dayjs from "dayjs";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import Clipboard from "@/utils/Clipboard";
import Theme from "@/utils/Theme";
import translate, {pluralizeTranslate} from "@/utils/translator/Translator";
import {formatNumber} from "../Utils/formatNumber";
import {prepareLabel} from "@/Modules/Reports/Utils/decorator";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import {hasExtensionComponent} from "@/utils/extensionComponents";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

const reportFilter = props.reportFilter;
const colors = Theme.colors.report;
const loading = ref(true);
const data = ref({summary: {}, schedule: [], starts: [], due_soon: {}, at_risk_count: 0, at_risk_amount: 0});
// The schedule, starts and collection rate come with FluentCart Pro only
const hasForecast = computed(() => Array.isArray(data.value.schedule));
// Share of the payments left that the projection expects; starts at the store's own rate
const collectionRate = ref(100);
const chartRef = ref(null);
let chartInstance = null;
const startsChartRef = ref(null);
let startsChartInstance = null;
let latestRequest = 0;

// This page's own product filter; the global report filters do not apply here
const selectedVariations = ref([]);
// [from, to] as YYYY-MM-DD on the plan start date; empty means all time
const startedRange = ref(null);
const rangeShortcuts = [
  {text: translate('This month'), value: () => [siteToday().startOf('month').toDate(), siteToday().endOf('day').toDate()]},
  {text: translate('Last 3 months'), value: () => [siteToday().subtract(2, 'month').startOf('month').toDate(), siteToday().endOf('day').toDate()]},
  {text: translate('Last 6 months'), value: () => [siteToday().subtract(5, 'month').startOf('month').toDate(), siteToday().endOf('day').toDate()]},
  {text: translate('This year'), value: () => [siteToday().startOf('year').toDate(), siteToday().endOf('day').toDate()]},
  {text: translate('Last year'), value: () => [siteToday().subtract(1, 'year').startOf('year').toDate(), siteToday().subtract(1, 'year').endOf('year').toDate()]},
];
const filterOptions = ref([]);
// A plain calendar day in the store's date format, with the year when it is not this year; no timezone shift
const formatDay = (day) => {
  const date = dayjs(day);
  return getDateTimeStringI18(date.locale(fluentDayjsLocale()).format(resolveDateFormat(resolveYearAwareFormat(date))), 'mNumber');
};

const scopedParams = () => ({
  started_from: startedRange.value ? startedRange.value[0] : '',
  started_to: startedRange.value ? startedRange.value[1] : '',
  ...reportFilter.applicableFilters.params,
  variation_ids: selectedVariations.value,
});

const atRisk = ref({data: [], loading: true});
const atRiskSearch = ref('');
// variations | overdue | lapsed; the at-risk tabs load only when opened
// Without Pro the variations tab is a preview, so the overdue list opens first
// The Overdue and Lapsed lists name customers: only for users with customer access
const canSeeCustomers = Permission.has('customers/view');
const activeTab = ref(!canSeeCustomers || hasExtensionComponent('reports.installments.variations') ? 'variations' : 'overdue');
const fluidTab = ref(null);
const atRiskPaging = ref({current_page: 1, per_page: 10, total: 0});
let latestAtRiskRequest = 0;

const fetchAtRisk = () => {
  if (!canSeeCustomers) {
    return;
  }
  const request = ++latestAtRiskRequest;
  atRisk.value.loading = true;

  Rest.get('reports/installments/at-risk', {
    params: scopedParams(),
    search: atRiskSearch.value,
    bucket: activeTab.value,
    page: atRiskPaging.value.current_page,
    per_page: atRiskPaging.value.per_page,
  })
    .then(response => {
      if (request === latestAtRiskRequest) {
        atRisk.value.data = response.data || [];
        atRiskPaging.value.total = response.total || 0;
      }
    })
    .catch(error => {
      if (request === latestAtRiskRequest) {
        Notify.error(error);
      }
    })
    .finally(() => {
      if (request === latestAtRiskRequest) {
        atRisk.value.loading = false;
      }
    });
};

// A new search or filter starts again from page 1
const refetchAtRisk = () => {
  atRiskPaging.value.current_page = 1;
  fetchAtRisk();
};
const onAtRiskSearch = Utils.debounce(refetchAtRisk, 400);

// Plans by Variation is a Pro section: it fetches when these params change
const variationsParams = ref(null);

// Each table has its own request; only the open tab loads
const loadActiveTab = () => {
  if (activeTab.value === 'variations') {
    variationsParams.value = scopedParams();
  } else {
    refetchAtRisk();
  }
};

const switchTab = (tab) => {
  if (activeTab.value === tab) {
    return;
  }
  activeTab.value = tab;
  loadActiveTab();
};

const fetchData = () => {
  loadActiveTab();
  const request = ++latestRequest;
  loading.value = true;

  Rest.get('reports/installments', {params: scopedParams()})
    .then(response => {
      if (request === latestRequest) {
        data.value = response;
        collectionRate.value = historyRate();
        filterOptions.value = response.filter_options || [];
      }
    })
    .catch(error => {
      if (request === latestRequest) {
        Notify.error(error);
      }
    })
    .finally(() => {
      if (request === latestRequest) {
        loading.value = false;
      }
    });
};

const money = (value) => reportFilter.formatAmount(value || 0, {cents: true});
// No ended plan yet: no history, so 100%
const historyRate = () => {
  const rate = (data.value.summary || {}).collection_rate;
  return rate === null || rate === undefined ? 100 : Math.round(rate);
};
const projected = (amount) => Math.round((amount || 0) * collectionRate.value / 100);

const dueSoon = computed(() => data.value.due_soon || {});

const summaryCards = computed(() => {
  const summary = data.value.summary || {};
  return [
    {title: translate('Collected'), value: money(summary.collected), info: translate('SubRpt_Paid_Started')},
    {title: translate('Scheduled'), value: money(summary.scheduled), info: translate('Payments left on active plans.')},
    {
      title: translate('Due next 30 days'),
      value: money(dueSoon.value.amount),
      /* translators: %1$s - an amount */
      note: dueSoon.value.overdue?.amount ? translate('Includes %1$s already past due', money(dueSoon.value.overdue.amount)) : '',
      /* translators: %1$s - number of payments */
      info: pluralizeTranslate(
        '%1$s payment on active and overdue plans falls due in the next 30 days, or is already past due.',
        '%1$s payments on active and overdue plans fall due in the next 30 days, or are already past due.',
        formatNumber(dueSoon.value.count || 0),
        '%1$s payments on active and overdue plans fall due in the next 30 days, or are already past due.'
      ),
    },
    ...(hasForecast.value ? [{title: translate('Projected to collect'), value: money(projected((summary.scheduled || 0) + (summary.overdue || 0))), info: translate('SubRpt_Scheduled_Overdue')}] : []),
    {title: translate('Overdue'), value: money(summary.overdue), info: translate('Payments left on expiring, past due and failing plans.')},
    {title: translate('Lapsed unpaid'), value: money(summary.lapsed_unpaid), info: translate('Payments left on ended plans that had no refund.')},
    {title: translate('Plans started'), value: formatNumber(summary.plans_started || 0), info: translate('Plans with at least one paid order.')},
    {title: translate('Completion rate'), value: (summary.completion_rate || 0) + '%', info: translate('SubRpt_Completed_Plans')},
  ];
});

const collectionRateInfo = computed(() => {
  const rate = (data.value.summary || {}).collection_rate;
  return rate === null || rate === undefined
    ? translate('SubRpt_Plan_Ended')
    : translate('SubRpt_Starts_Store', Math.round(rate) + '%');
});

const tabs = computed(() => {
  const summary = data.value.summary || {};
  return [
    {key: 'variations', label: translate('Plans by Variation')},
    ...(canSeeCustomers ? [
      {key: 'overdue', label: translate('Overdue'), count: summary.overdue_count || 0},
      {key: 'lapsed', label: translate('Lapsed'), count: summary.lapsed_count || 0},
    ] : []),
  ];
});

const atRiskText = computed(() => {
  const summary = data.value.summary || {};
  if (activeTab.value === 'overdue') {
    return translate('%1$s still owed on %2$s plans with a failed payment, largest balance first.', money(summary.overdue), formatNumber(summary.overdue_count || 0));
  }
  return translate('%1$s left unpaid on %2$s ended plans with no refund, largest balance first.', money(summary.lapsed_unpaid), formatNumber(summary.lapsed_count || 0));
});



const copyReactivationLink = (row) => {
  Clipboard.copy(row.reactivate_url);
};

const renderChart = () => {
  if (!chartRef.value) {
    return;
  }

  if (!chartInstance) {
    chartInstance = echarts.init(chartRef.value);
  }

  const isDark = Theme.isDark();
  const textColor = isDark ? '#ffffff' : '#000000';
  const lineColor = isDark ? '#253241' : '#D6DAE1';
  const schedule = data.value.schedule || [];

  chartInstance.setOption({
    legend: {show: true, textStyle: {color: textColor}},
    tooltip: {
      trigger: 'axis',
      valueFormatter: (value) => money(value),
    },
    xAxis: {
      type: 'category',
      data: schedule.map(item => prepareLabel(item.month)),
      axisLabel: {color: textColor},
      axisLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    yAxis: {
      type: 'value',
      axisLabel: {color: textColor, formatter: (value) => reportFilter.formatAmount(value, {cents: true, chart: true})},
      splitLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    series: [
      {
        name: translate('Scheduled'),
        type: 'bar',
        stack: 'due',
        barMaxWidth: 30,
        color: isDark ? colors.blue_dark_mode : colors.royal_blue,
        data: schedule.map(item => item.scheduled),
      },
      {
        name: translate('Overdue'),
        type: 'bar',
        stack: 'due',
        barMaxWidth: 30,
        color: colors.coral,
        data: schedule.map(item => item.overdue),
      },
      {
        name: translate('Projected'),
        type: 'line',
        symbol: 'circle',
        color: colors.sea_green,
        lineStyle: {type: 'dashed'},
        data: schedule.map(item => projected(item.amount)),
      },
    ],
  }, {notMerge: true});
};

const renderStartsChart = () => {
  if (!startsChartRef.value) {
    return;
  }

  if (!startsChartInstance) {
    startsChartInstance = echarts.init(startsChartRef.value);
  }

  const isDark = Theme.isDark();
  const textColor = isDark ? '#ffffff' : '#000000';
  const lineColor = isDark ? '#253241' : '#D6DAE1';
  const starts = data.value.starts || [];
  const outcomes = [
    {key: 'completed_count', name: translate('Completed'), color: colors.sea_green},
    {key: 'running_count', name: translate('Running'), color: isDark ? colors.blue_dark_mode : colors.royal_blue},
    {key: 'overdue_count', name: translate('Overdue'), color: colors.coral},
    {key: 'paused_count', name: translate('Paused'), color: colors.light_gray_cyan_blue},
    {key: 'upgraded_count', name: translate('Upgraded'), color: colors.deep_sky_blue},
    {key: 'lapsed_count', name: translate('Lapsed'), color: colors.golden_rod},
    {key: 'refunded_count', name: translate('Refunded'), color: colors.medium_slate_blue},
  ];

  startsChartInstance.setOption({
    legend: {show: true, textStyle: {color: textColor}},
    tooltip: {
      trigger: 'axis',
      formatter: (params) => {
        const month = starts[params[0].dataIndex] || {};
        let html = `${params[0].name}<br/>${translate('Plans started')}: <b>${formatNumber(month.plans_started || 0)}</b>`;
        params.forEach(param => {
          if (param.value) {
            html += `<br/>${param.marker} ${param.seriesName}: ${formatNumber(param.value)}`;
          }
        });
        return html + `<br/>${translate('Collected')}: ${money(month.collected)}`;
      },
    },
    xAxis: {
      type: 'category',
      data: starts.map(item => prepareLabel(item.month)),
      axisLabel: {color: textColor},
      axisLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    yAxis: {
      type: 'value',
      minInterval: 1,
      axisLabel: {color: textColor},
      splitLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    series: outcomes.map(outcome => ({
      name: outcome.name,
      type: 'bar',
      stack: 'starts',
      barMaxWidth: 30,
      color: outcome.color,
      data: starts.map(item => item[outcome.key]),
    })),
  }, {notMerge: true});
};

const renderCharts = () => {
  renderChart();
  renderStartsChart();
};

const disposeChart = () => {
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }
  if (startsChartInstance) {
    startsChartInstance.dispose();
    startsChartInstance = null;
  }
};

const resizeChart = () => {
  chartInstance && chartInstance.resize();
  startsChartInstance && startsChartInstance.resize();
};

watch(collectionRate, () => chartInstance && renderChart());

watch(loading, (value) => {
  disposeChart();
  if (!value) {
    nextTick(renderCharts);
  }
});

onMounted(() => {
  reportFilter.addListener('installments-report', fetchData);
  fetchData();
  nextTick(() => fluidTab.value && fluidTab.value.setActiveByActiveClass());
  window.addEventListener('onFluentCartThemeChange', renderCharts);
  window.addEventListener('resize', resizeChart);
});

onUnmounted(() => {
  reportFilter.removeListener('installments-report');
  latestRequest++;
  latestAtRiskRequest++;
  disposeChart();
  window.removeEventListener('onFluentCartThemeChange', renderCharts);
  window.removeEventListener('resize', resizeChart);
});
</script>

<template>
  <UserCan :permission="'reports/view'">
    <div class="fct-installments-report-page">
      <PageHeading>
        <template #title>
          <div>
            <h2 class="page-title">{{ translate('Installments') }}</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-0">
              {{ startedRange
                ? translate('Plans started from %1$s to %2$s, as they stand today.', formatDay(startedRange[0]), formatDay(startedRange[1]))
                : translate('Installment plans as of today, over all time.') }}
            </p>
          </div>
        </template>
      </PageHeading>

      <!-- In the title bar, like the other report pages' filters -->
      <Teleport defer to="#fct-report-header-actions">
          <!-- Wraps on a narrow screen rather than running out of the title bar -->
          <div v-if="filterOptions.length" class="flex flex-wrap items-center justify-end gap-2">
          <div class="flex-none" style="width: 260px">
            <fct-date-picker
                size="small"
                v-model="startedRange"
                type="daterange"
                value-format="YYYY-MM-DD"
                unlink-panels
                :shortcuts="rangeShortcuts"
                :start-placeholder="translate('Started from')"
                :end-placeholder="translate('Started to')"
                :aria-label="translate('Plans started between')"
                style="width: 100%"
                @change="fetchData"
            />
          </div>
          <fct-select
              v-model="selectedVariations"
              size="small"
              multiple
              collapse-tags
              collapse-tags-tooltip
              clearable
              filterable
              style="width: 260px"
              :placeholder="translate('All products')"
              :aria-label="translate('Filter by product')"
              @change="fetchData"
              :options="filterOptions.map(product => ({label: product.product_title, options: product.variations.map(variation => ({label: variation.title, value: variation.id}))}))"
          />
          </div>
      </Teleport>

      <SummaryLoader v-if="loading" :loading="loading" :count="8"/>

      <div v-else class="fct-report-order-summary summary-col-4">
        <div class="summary-item" v-for="card in summaryCards" :key="card.title">
          <div class="summary-item-inner">
            <div class="value">
              <div class="inner-value">
                <ReportSummary :amount="card.value" :title="card.title"/>
              </div>
            </div>
            <div class="title">
              {{ card.title }}
              <fct-tooltip :content="card.info" placement="top">
                <DynamicIcon class="text-gray-500 w-4 h-4 cursor-help" name="InformationFill"/>
              </fct-tooltip>
            </div>
            <div v-if="card.note" class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ card.note }}</div>
          </div>
        </div>
      </div>

      <template v-if="loading || hasForecast">
        <Card.Container>
          <Card.Header
              :title="translate('Plans Started by Month')"
              :text="translate('Plans by the month of their first payment, split by how they stand today.')"
              title_size="small"
              border_bottom
          />
          <Card.Body>
            <fct-skeleton v-if="loading" animated :rows="6"/>
            <el-empty v-else-if="!data.starts.length" :description="translate('No installment plans yet')"/>
            <div v-else ref="startsChartRef" class="fct-chart-wrap" style="height: 320px"></div>
          </Card.Body>
        </Card.Container>

        <Card.Container>
          <Card.Header
              :title="translate('Monthly Schedule')"
              :text="translate('SubRpt_Payments_Left')"
              title_size="small"
              border_bottom
          >
            <template #action v-if="!loading && data.schedule.length">
              <div class="flex items-center gap-3">
                <span class="text-sm text-system-dark dark:text-gray-200 whitespace-nowrap">{{ translate('Collection rate') }}</span>
                <el-slider v-model="collectionRate" style="width: 160px" :min="0" :max="100" :step="1" :show-tooltip="false" :aria-label="translate('Collection rate')"/>
                <span class="text-sm font-medium text-system-dark dark:text-gray-100 w-10">{{ collectionRate }}%</span>
                <fct-tooltip placement="top" :content="collectionRateInfo">
                  <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this setting')">
                    <DynamicIcon name="InformationFill" class="w-4 text-gray-500" aria-hidden="true"/>
                  </button>
                </fct-tooltip>
                <fct-button size="small" :disabled="collectionRate === historyRate()" @click="collectionRate = historyRate()">{{ translate('Reset') }}</fct-button>
              </div>
            </template>
          </Card.Header>
          <Card.Body>
            <fct-skeleton v-if="loading" animated :rows="6"/>
            <el-empty v-else-if="!data.schedule.length" :description="translate('No payments left on active or overdue plans')"/>
            <div v-else ref="chartRef" class="fct-chart-wrap" style="height: 320px"></div>
          </Card.Body>
        </Card.Container>
      </template>
      <ProReportPreview
          v-else
          :title="translate('See what your installment plans will bring in')"
          :text="translate('SubRpt_See_Payments')"
          :bullets="[translate('Monthly schedule with a collection-rate slider'), translate('Projected to collect'), translate('Plans started by month')]"
          placement="feature_lock_report_installment_forecast"
      >
        <ProReportSample
            :title="translate('Monthly Schedule')"
            :columns="[translate('Month'), translate('Scheduled'), translate('Projected')]"
            :rows="[[translate('Jan'), '2,000.00', '1,800.00'], [translate('Feb'), '1,800.00', '1,620.00'], [translate('Mar'), '1,500.00', '1,350.00']]"
        />
      </ProReportPreview>

      <Card.Container class="overflow-hidden">
        <Card.Header border_bottom>
          <template #title>
            <Fluid.Tab ref="fluidTab" small>
              <Fluid.Item
                  v-for="tab in tabs"
                  :key="tab.key"
                  :class="activeTab === tab.key ? 'active' : ''"
                  :aria-pressed="activeTab === tab.key"
                  @click="switchTab(tab.key)"
              >
                {{ tab.label }}<template v-if="tab.count !== undefined"> ({{ formatNumber(tab.count) }})</template>
              </Fluid.Item>
            </Fluid.Tab>
            <p v-if="activeTab !== 'variations' && !loading" class="fct-card-header-text mt-2 mb-0">{{ atRiskText }}</p>
          </template>
          <template #action v-if="activeTab !== 'variations'">
            <fct-input
                v-model="atRiskSearch"
                style="width: 240px"
                size="small"
                clearable
                :placeholder="translate('Search name or email')"
                :aria-label="translate('Search name or email')"
                @input="onAtRiskSearch"
                @clear="refetchAtRisk"
            />
          </template>
        </Card.Header>
        <Card.Body class="px-0 pb-0">
          <template v-if="activeTab === 'variations'">
            <ExtensionComponent v-if="variationsParams" name="reports.installments.variations" :report-filter="reportFilter" :params="variationsParams">
              <ProReportPreview
                  :title="translate('See how each installment plan performs')"
                  :text="translate('SubRpt_Compare_Installment')"
                  :bullets="[translate('Collected and scheduled per variation'), translate('Completed, running, overdue and lapsed plans')]"
                  placement="feature_lock_report_installment_variations"
              >
                <ProReportSample
                    :title="translate('Plans by Variation')"
                    :columns="[translate('Variation'), translate('Started'), translate('Net collected'), translate('Scheduled')]"
                    :rows="[[translate('Plan A'), '100', '5,000.00', '2,000.00'], [translate('Plan B'), '60', '3,000.00', '1,000.00']]"
                />
              </ProReportPreview>
            </ExtensionComponent>
          </template>
          <template v-else>
            <fct-skeleton v-if="atRisk.loading" animated :rows="5" class="px-4 pt-4 pb-4"/>
            <fct-table v-else :data="atRisk.data">
              <fct-table-column :label="translate('Customer')" min-width="220">
                <template #default="{row}">
                  <router-link :to="{name: 'view_subscription', params: {subscription_id: row.subscription_id}}">
                    {{ row.customer_name || row.email }}
                  </router-link>
                  <div class="text-gray-500 text-xs">{{ row.email }}</div>
                </template>
              </fct-table-column>
              <fct-table-column :label="translate('Product')" min-width="220">
                <template #default="{row}">
                  <div>{{ row.product_title }}</div>
                  <div class="text-gray-500 text-xs">{{ row.variation_title }} (#{{ row.variation_id }})</div>
                </template>
              </fct-table-column>
              <fct-table-column :label="translate('Status')" min-width="110">
                <template #default="{row}">{{ row.status }}</template>
              </fct-table-column>
              <fct-table-column :label="translate('Paid')" min-width="90">
                <template #default="{row}">{{ row.bill_count }} / {{ row.bill_times }}</template>
              </fct-table-column>
              <fct-table-column :label="translate('Collected')" min-width="120">
                <template #default="{row}">{{ money(row.collected) }}</template>
              </fct-table-column>
              <fct-table-column :label="translate('Left')" min-width="120">
                <template #default="{row}">{{ money(row.left_amount) }}</template>
              </fct-table-column>
              <fct-table-column :label="translate('Reactivation')" min-width="140">
                <template #default="{row}">
                  <fct-button v-if="row.reactivate_url" size="small" text @click="copyReactivationLink(row)">
                    {{ translate('Copy link') }}
                  </fct-button>
                  <span v-else class="text-gray-500 text-xs">{{ translate('Not available') }}</span>
                </template>
              </fct-table-column>
              <template #empty>
                <el-empty :description="atRiskSearch ? translate('No plans match this search') : (activeTab === 'overdue' ? translate('No overdue plans') : translate('No lapsed plans'))"/>
              </template>
            </fct-table>
            <Pagination
                v-if="atRiskPaging.total"
                :pagination="atRiskPaging"
                :hide_on_single="false"
                @fetch="fetchAtRisk"
            />
          </template>
        </Card.Body>
      </Card.Container>
    </div>
  </UserCan>
</template>
