<script setup>
import {nextTick, onMounted, onUnmounted, ref, watch} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import {prepareLabel, axisDayLabel} from "@/Modules/Reports/Utils/decorator";

const props = defineProps({
  points: {type: Array, required: true},
  gateways: {type: Array, required: true},
  loading: {type: Boolean, default: false},
  reportFilter: {type: Object, required: true},
});

const chartRef = ref(null);
let chart = null;
const palette = Theme.colors.report;
const colors = [palette.royal_blue, palette.coral, palette.sea_green, palette.golden_rod, palette.medium_slate_blue, palette.hot_pink,
  palette.deep_sky_blue, palette.medium_turquoise];

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
  chart.setOption({
    legend: {show: true, textStyle: {color: textColor}},
    tooltip: {trigger: 'axis', valueFormatter: value => props.reportFilter.formatAmount(value || 0)},
    grid: {left: 16, right: 16, bottom: 8, containLabel: true},
    xAxis: {
      type: 'category', data: props.points.map(point => prepareLabel(String(point.group))),
      axisLabel: {color: textColor, formatter: axisDayLabel}, axisLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    yAxis: {type: 'value', axisLabel: {color: textColor, formatter: value => props.reportFilter.formatAmount(value, {chart: true})},
      splitLine: {lineStyle: {type: 'dashed', color: lineColor}}},
    series: props.gateways.map((gateway, index) => ({
      name: gateway.label, type: 'bar', stack: 'gateways', barMaxWidth: 30, color: colors[index % colors.length],
      data: props.points.map(point => (point.gateways || {})[gateway.gateway] || 0),
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
    <Card.Header :title="translate('Net Sales by Payment Method')" :text="translate('Net sales of the orders each gateway took, by period.')" title_size="small" border_bottom/>
    <Card.Body>
      <el-skeleton v-if="loading" animated :rows="6"/>
      <el-empty v-else-if="!gateways.length" :description="translate('No orders in this period')"/>
      <div v-else ref="chartRef" class="fct-chart-wrap" style="height: 300px"></div>
    </Card.Body>
  </Card.Container>
</template>
