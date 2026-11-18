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
// net | units
const metric = ref('net');
let chart = null;

const groups = [
  {value: 'default', label: translate('Auto')},
  {value: 'daily', label: translate('Daily')},
  {value: 'monthly', label: translate('Monthly')},
  {value: 'yearly', label: translate('Yearly')},
];

const hasInstallments = computed(() => props.points.some(point => point.installment_net));
const isEmpty = computed(() => !props.points.some(point => point.units || point.new_net || point.renewal_net || point.installment_net));
const money = (value) => props.reportFilter.formatAmount(value || 0, {cents: true});

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
  const labels = props.points.map(point => prepareLabel(String(point.group)));
  const bar = (name, key, color) => ({
    name, type: 'bar', stack: 'sales', barMaxWidth: 30, color,
    data: props.points.map(point => point[key] || 0),
  });
  const series = metric.value === 'units'
    ? [bar(translate('Units sold'), 'units', isDark ? colors.blue_dark_mode : colors.royal_blue)]
    : [
      bar(translate('New sales'), 'new_net', isDark ? colors.blue_dark_mode : colors.royal_blue),
      bar(translate('Renewals'), 'renewal_net', colors.sea_green),
      ...(hasInstallments.value ? [bar(translate('Installment payments'), 'installment_net', colors.golden_rod)] : []),
    ];

  chart.setOption({
    legend: {show: true, textStyle: {color: textColor}},
    tooltip: {
      trigger: 'axis',
      valueFormatter: (value) => metric.value === 'units' ? formatNumber(value) : money(value),
    },
    grid: {left: 16, right: 16, bottom: 8, containLabel: true},
    xAxis: {
      type: 'category',
      data: labels,
      axisLabel: {color: textColor, formatter: axisDayLabel},
      axisLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    yAxis: {
      type: 'value',
      minInterval: metric.value === 'units' ? 1 : 0,
      axisLabel: {
        color: textColor,
        formatter: (value) => metric.value === 'units' ? formatNumber(value) : props.reportFilter.formatAmount(value, {cents: true, chart: true}),
      },
      splitLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    series,
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
        :title="translate('Sales Over Time')"
        :text="translate('Net sales split into new sales, renewals and installment payments.')"
        title_size="small"
        border_bottom
    >
      <template #action>
        <div class="flex items-center gap-2">
          <fct-choice v-model="metric" size="small" :aria-label="translate('Chart metric')" type="radio" button :options="[{label: translate('Net sales'), value: 'net'}, {label: translate('Units'), value: 'units'}]"/>
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
      <el-empty v-else-if="isEmpty" :description="translate('No sales in this date range')"/>
      <div v-else ref="chartRef" class="fct-chart-wrap" style="height: 320px"></div>
    </Card.Body>
  </Card.Container>
</template>
