<script setup>
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import Rest from "@/utils/http/Rest";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";
import Notify from "@/utils/Notify";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import {prepareLabel, axisDayLabel} from "@/Modules/Reports/Utils/decorator";
import {formatNumber} from "../Utils/formatNumber";

const props = defineProps({
  reportFilter: {type: Object, required: true},
});

const data = ref({summary: {}, previous: null, comparison: {coupon: {}, full_price: {}}, chart: []});
const loading = ref(true);
const chartRef = ref(null);
let chart = null;
let latest = 0;

const fetchData = (filters) => {
  const request = ++latest;
  loading.value = true;
  const call = Rest.get('reports/coupon-overview', {params: (filters || props.reportFilter.applicableFilters).params});
  props.reportFilter.trackCompareRange(call);
  call.then(response => request === latest && (data.value = response))
    .catch(error => request === latest && Notify.error(error))
    .finally(() => request === latest && (loading.value = false));
};

const money = (value) => props.reportFilter.formatAmount(value || 0, {cents: true});
const percent = (value) => (value || 0) + '%';
/* translators: %1$s - left empty; the text follows a change in percentage points, e.g. "+1.2%" then " pts" */
const pointsSuffix = translate('%1$s pts', '');

const cards = computed(() => {
  const s = data.value.summary || {};
  const previous = data.value.previous;
  // A share changes in points; an amount as a share of itself. More discounting reads as a cost (red when rising)
  const change = (key, points = false) => {
    if (!previous || (!points && !previous[key])) {
      return null;
    }
    return points
      ? Math.round(((s[key] || 0) - (previous[key] || 0)) * 10) / 10
      : Math.round((s[key] - previous[key]) / Math.abs(previous[key]) * 1000) / 10;
  };
  return [
    {key: 'discount', title: translate('Discount given'), value: money(s.discount), change: change('discount'),
      previous: (previous?.discount || 0) / 100, money: true, liability: true,
      info: translate('Coupon discounts on the orders placed in this period.')},
    {key: 'coupon_share', title: translate('Orders with a coupon'), value: percent(s.coupon_share), change: change('coupon_share', true), points: true, liability: true,
      /* translators: %1$s - orders with a coupon, %2$s - all orders */
      info: translate('%1$s of %2$s paid orders used a coupon, renewals included.', formatNumber(s.coupon_orders || 0), formatNumber(s.orders || 0))},
    {key: 'new_share', title: translate('New customers with a coupon'), value: percent(s.new_share), change: change('new_share', true), points: true, liability: true,
      /* translators: %1$s - new customers who used a coupon, %2$s - all new customers */
      info: translate('%1$s of %2$s new customers used a coupon on their first purchase.', formatNumber(s.coupon_new_customers || 0), formatNumber(s.new_customers || 0))},
    {key: 'discount_rate', title: translate('Average discount'), value: percent(s.discount_rate), change: change('discount_rate', true), points: true, liability: true,
      info: translate('CouponRpt_Discount_Given')},
  ];
});

const groups = computed(() => {
  const c = data.value.comparison || {};
  return [
    {key: 'coupon', title: translate('With a coupon'), row: c.coupon || {}},
    {key: 'full_price', title: translate('At full price'), row: c.full_price || {}},
  ];
});
const comparisonRows = computed(() => [
  {label: translate('New customers'), value: row => formatNumber(row.customers || 0)},
  {label: translate('Average first order'), value: row => money(row.average_first)},
  {label: translate('Average lifetime value'), value: row => money(row.average_ltv)},
  {label: translate('Bought again'), value: row => percent(row.repeat_rate)},
  {label: translate('First order refunded in part or full'), value: row => percent(row.refund_rate)},
]);

const render = () => {
  if (!chartRef.value) {
    return;
  }
  if (!chart) {
    chart = echarts.init(chartRef.value);
  }
  const isDark = Theme.isDark();
  // The same text and grid colors as the other report charts
  const textColor = isDark ? '#ffffff' : '#000000';
  const lineColor = isDark ? '#253241' : '#D6DAE1';
  const points = data.value.chart || [];
  const palette = Theme.colors.report;
  chart.setOption({
    legend: {show: true, textStyle: {color: textColor}},
    tooltip: {trigger: 'axis'},
    grid: {left: 16, right: 16, bottom: 8, containLabel: true},
    xAxis: {
      type: 'category', data: points.map(point => prepareLabel(String(point.group))),
      axisLabel: {color: textColor, formatter: axisDayLabel}, axisLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    yAxis: [
      {type: 'value', axisLabel: {color: textColor, formatter: value => props.reportFilter.formatAmount(value, {cents: true, chart: true})},
        splitLine: {lineStyle: {type: 'dashed', color: lineColor}}},
      {type: 'value', max: 100, axisLabel: {color: textColor, formatter: value => value + '%'}, splitLine: {show: false}},
    ],
    series: [
      {name: translate('Discount given'), type: 'bar', barMaxWidth: 30, color: palette.coral, data: points.map(point => point.discount || 0),
        tooltip: {valueFormatter: value => money(value)}},
      {name: translate('Orders with a coupon'), type: 'line', yAxisIndex: 1, smooth: true, symbol: 'circle',
        color: isDark ? palette.blue_dark_mode : palette.royal_blue,
        data: points.map(point => point.orders ? Math.round(point.coupon_orders / point.orders * 1000) / 10 : 0),
        tooltip: {valueFormatter: value => value + '%'}},
    ],
  }, {notMerge: true});
};

const dispose = () => {
  if (chart) {
    chart.dispose();
    chart = null;
  }
};
const resize = () => chart && chart.resize();
const hasOrders = computed(() => (data.value.summary || {}).orders > 0);

watch(() => [loading.value, data.value], () => {
  dispose();
  if (!loading.value) {
    nextTick(render);
  }
});

onMounted(() => {
  props.reportFilter.addListener('coupon-overview', fetchData);
  fetchData();
  window.addEventListener('resize', resize);
  window.addEventListener('onFluentCartThemeChange', render);
});
onUnmounted(() => {
  props.reportFilter.removeListener('coupon-overview');
  latest++;
  dispose();
  window.removeEventListener('resize', resize);
  window.removeEventListener('onFluentCartThemeChange', render);
});
</script>

<template>
  <div v-if="!loading" class="fct-report-order-summary summary-col-4">
    <div class="summary-item" v-for="item in cards" :key="item.key">
      <div class="summary-item-inner">
        <div class="value">
          <div class="inner-value text-lg font-medium">{{ item.value }}</div>
          <Fluctuation
              v-if="item.change !== null"
              :fluctuation="item.change"
              :amount="item.money ? item.previous : (data.previous || {})[item.key]"
              :has-currency="!!item.money"
              :is-percentage="!!item.points"
              :is-liability="!!item.liability"
              :fluctuation-suffix="item.points ? pointsSuffix : ''"
          />
        </div>
        <div class="title">
          {{ item.title }}
          <el-tooltip :content="item.info" placement="top" popper-class="fct-tooltip" effect="dark" :trigger="['hover', 'focus']">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
              <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true"/>
            </button>
          </el-tooltip>
        </div>
      </div>
    </div>
  </div>

  <div class="grid gap-6 lg:grid-cols-2 mb-6">
    <Card.Container class="mb-0">
      <Card.Header :title="translate('Discounts Over Time')" :text="translate('Discount given, and the share of orders that used a coupon.')" title_size="small" border_bottom/>
      <Card.Body>
        <el-skeleton v-if="loading" animated :rows="6"/>
        <el-empty v-else-if="!hasOrders" :description="translate('No orders in this date range')"/>
        <div v-else ref="chartRef" class="fct-chart-wrap" style="height: 280px"></div>
      </Card.Body>
    </Card.Container>

    <Card.Container v-if="loading || data.comparison" class="mb-0">
      <Card.Header
          :title="translate('Coupon vs Full Price')"
          :text="translate('CouponRpt_New_Customers')"
          title_size="small"
          border_bottom
      />
      <Card.Body class="px-0 pb-0">
        <el-skeleton v-if="loading" animated :rows="5" class="px-4 pt-4 pb-4"/>
        <table v-else class="w-full text-sm">
          <thead>
            <tr>
              <th class="text-left font-medium px-4 py-3"></th>
              <th v-for="group in groups" :key="group.key" class="text-left font-medium px-4 py-3">{{ group.title }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in comparisonRows" :key="row.label" class="border-t border-gray-100 dark:border-dark-400">
              <td class="px-4 py-2 text-system-mid dark:text-gray-300">{{ row.label }}</td>
              <td v-for="group in groups" :key="group.key" class="px-4 py-2 text-system-dark dark:text-gray-200">{{ row.value(group.row) }}</td>
            </tr>
          </tbody>
        </table>
      </Card.Body>
    </Card.Container>
    <ProReportPreview
        v-else
        :title="translate('See if your coupons win good customers')"
        :text="translate('CouponRpt_Compare_Customers')"
        :bullets="[translate('Coupon vs full price'), translate('Value of customers won per coupon'), translate('Refund rate per coupon')]"
        placement="feature_lock_report_coupon_quality"
    >
      <ProReportSample
          :title="translate('Coupon vs Full Price')"
          :columns="['', translate('With a coupon'), translate('At full price')]"
          :rows="[[translate('New customers'), '40', '60'], [translate('Lifetime value'), '80.00', '95.00']]"
      />
    </ProReportPreview>
  </div>
</template>
