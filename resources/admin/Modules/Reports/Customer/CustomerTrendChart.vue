<script setup>
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import {prepareLabel, axisDayLabel} from "@/Modules/Reports/Utils/decorator";
import {formatNumber} from "../Utils/formatNumber";

const props = defineProps({
  points: {type: Array, default: () => []},
  loading: {type: Boolean, default: false},
  groupKey: {type: String, default: 'default'},
  reportFilter: {type: Object, required: true},
});
const emit = defineEmits(['update:groupKey']);

const colors = Theme.colors.report;
const chartRef = ref(null);
// customers | revenue
const metric = ref('customers');
let chart = null;

const groups = [
  {value: 'default', label: translate('Auto')},
  {value: 'daily', label: translate('Daily')},
  {value: 'monthly', label: translate('Monthly')},
  {value: 'yearly', label: translate('Yearly')},
];
const isEmpty = computed(() => !props.points.some(point => point.new_customers || point.returning_customers || point.renewing_customers));
const isMoney = computed(() => metric.value === 'revenue');
const format = (value) => isMoney.value ? props.reportFilter.formatAmount(value || 0, {cents: true}) : formatNumber(value || 0);

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
  const keys = isMoney.value
    ? [['new_net', translate('New customers')], ['returning_net', translate('Returning customers')], ['renewal_net', translate('Renewals')]]
    : [['new_customers', translate('New customers')], ['returning_customers', translate('Returning customers')], ['renewing_customers', translate('Renewing only')]];
  const palette = [isDark ? colors.blue_dark_mode : colors.royal_blue, colors.golden_rod, colors.sea_green];

  chart.setOption({
    legend: {show: true, textStyle: {color: textColor}},
    tooltip: {trigger: 'axis', valueFormatter: format},
    grid: {left: 16, right: 16, bottom: 8, containLabel: true},
    xAxis: {
      type: 'category',
      data: props.points.map(point => prepareLabel(String(point.group))),
      axisLabel: {color: textColor, formatter: axisDayLabel},
      axisLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    yAxis: {
      type: 'value',
      minInterval: isMoney.value ? 0 : 1,
      axisLabel: {color: textColor, formatter: (value) => isMoney.value ? props.reportFilter.formatAmount(value, {cents: true, chart: true}) : formatNumber(value)},
      splitLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    series: keys.map(([key, name], index) => ({
      name, type: 'bar', stack: 'customers', barMaxWidth: 30, color: palette[index],
      data: props.points.map(point => point[key] || 0),
    })),
  }, {notMerge: true});
};

const dispose = () => {
  if (chart) {
    chart.dispose();
    chart = null;
  }
};
const resize = () => chart && chart.resize();

watch(() => [props.loading, props.points], () => {
  dispose();
  if (!props.loading) {
    nextTick(render);
  }
});
watch(metric, () => chart && render());

onMounted(() => {
  nextTick(render);
  window.addEventListener('resize', resize);
  window.addEventListener('onFluentCartThemeChange', render);
});
onUnmounted(() => {
  dispose();
  window.removeEventListener('resize', resize);
  window.removeEventListener('onFluentCartThemeChange', render);
});
</script>

<template>
  <Card.Container>
    <Card.Header
        :title="translate('Customers Over Time')"
        :text="translate('CustomerRpt_Paid_Period')"
        title_size="small"
        border_bottom
    >
      <template #action>
        <div class="flex items-center gap-2">
          <fct-choice v-model="metric" size="small" :aria-label="translate('Chart metric')" type="radio" button :options="[{label: translate('Customers'), value: 'customers'}, {label: translate('Revenue'), value: 'revenue'}]"/>
          <fct-select
              :model-value="groupKey"
              size="small"
              style="width: 120px"
              :aria-label="translate('Group by')"
              @update:model-value="(value) => emit('update:groupKey', value)"
              :options="groups"
          />
        </div>
      </template>
    </Card.Header>
    <Card.Body>
      <fct-skeleton v-if="loading" animated :rows="6"/>
      <el-empty v-else-if="isEmpty" :description="translate('No customer paid in this date range')"/>
      <div v-else ref="chartRef" class="fct-chart-wrap" style="height: 320px"></div>
    </Card.Body>
  </Card.Container>
</template>
