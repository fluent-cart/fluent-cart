<script setup>
import {nextTick, onMounted, onUnmounted, ref, watch} from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import ReportLoadError from "../Components/ReportLoadError.vue";
import {prepareLabel, axisDayLabel} from "@/Modules/Reports/Utils/decorator";

// A period series and, when given, the series it is compared with (a dotted line)
const props = defineProps({
  title: {type: String, required: true},
  text: {type: String, default: ''},
  groups: {type: Array, required: true},
  current: {type: Array, required: true},
  previous: {type: Array, default: null},
  currentName: {type: String, required: true},
  previousName: {type: String, default: ''},
  bars: {type: Boolean, default: false},
  loading: {type: Boolean, default: false},
  failed: {type: Boolean, default: false},
  formatAmount: {type: Function, required: true},
});
defineEmits(['retry']);

const chartRef = ref(null);
let chart = null;

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
  const palette = Theme.colors.report;
  const series = [{
    name: props.currentName, type: props.bars ? 'bar' : 'line', smooth: !props.bars, barMaxWidth: 30, symbol: 'circle',
    color: isDark ? palette.blue_dark_mode : palette.royal_blue, data: props.current,
  }];
  if (props.previous) {
    series.push({
      name: props.previousName, type: 'line', smooth: true, symbol: 'none', color: palette.light_gray_cyan_blue,
      lineStyle: {type: 'dashed'}, data: props.previous,
    });
  }
  chart.setOption({
    legend: {show: !!props.previous, textStyle: {color: textColor}},
    tooltip: {trigger: 'axis', valueFormatter: value => props.formatAmount(value || 0)},
    grid: {left: 16, right: 16, bottom: 8, containLabel: true},
    xAxis: {
      type: 'category', data: props.groups.map(group => prepareLabel(String(group))),
      axisLabel: {color: textColor, formatter: axisDayLabel}, axisLine: {lineStyle: {type: 'dashed', color: lineColor}},
    },
    yAxis: {type: 'value', axisLabel: {color: textColor, formatter: value => props.formatAmount(value, {chart: true})},
      splitLine: {lineStyle: {type: 'dashed', color: lineColor}}},
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

watch(() => [props.loading, props.current, props.previous], () => {
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
    <Card.Header :title="title" :text="text" title_size="small" border_bottom/>
    <Card.Body>
      <el-skeleton v-if="loading" animated :rows="6"/>
      <ReportLoadError v-else-if="failed" @retry="$emit('retry')"/>
      <el-empty v-else-if="!current.some(value => value) && !(previous || []).some(value => value)" :description="translate('No sales in this period')"/>
      <div v-else ref="chartRef" class="fct-chart-wrap" style="height: 300px"></div>
    </Card.Body>
  </Card.Container>
</template>
